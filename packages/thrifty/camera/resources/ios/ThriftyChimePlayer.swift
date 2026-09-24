import AVFoundation
import Foundation
import UIKit

/// Plays the web app's two feedback sounds, synthesized once into PCM
/// buffers with the same oscillator, glide and gain envelope as its
/// Web Audio graphs (App.tsx playFoundSound / playSnapshotFeedback):
///
/// - find chime: sine gliding 720Hz → 1080Hz over 0.12s, gain rising to
///   0.12 in 10ms and decaying to silence by 0.22s (stops at 0.24s),
///   plus a success haptic
/// - shutter: square wave gliding 1800Hz → 700Hz over 0.07s, gain 0.1
///   decaying to silence by 0.09s, plus a light haptic
///
/// Uses the ambient audio category so it mixes with the user's music.
/// Like Web Audio in iOS Safari, it is silent when the ringer switch is off;
/// the haptic fires either way.
final class ThriftyChimePlayer {
    static let shared = ThriftyChimePlayer()

    private static let sampleRate = 44_100.0

    private let queue = DispatchQueue(label: "com.thrifty.camera.chime", qos: .userInitiated)
    private let engine = AVAudioEngine()
    private let player = AVAudioPlayerNode()
    private var isConnected = false
    private var chimeBuffer: AVAudioPCMBuffer?
    private var shutterBuffer: AVAudioPCMBuffer?

    func play() {
        DispatchQueue.main.async {
            let haptic = UINotificationFeedbackGenerator()
            haptic.prepare()
            haptic.notificationOccurred(.success)
        }

        schedule { try self.chime() }
    }

    func playShutter() {
        DispatchQueue.main.async {
            let haptic = UIImpactFeedbackGenerator(style: .light)
            haptic.prepare()
            haptic.impactOccurred()
        }

        schedule { try self.shutter() }
    }

    // MARK: - Playback (queue)

    private func schedule(_ buffer: @escaping () throws -> AVAudioPCMBuffer) {
        queue.async {
            do {
                try self.playBuffer(buffer())
            } catch {
                print("[ThriftyCamera] Sound failed: \(error.localizedDescription)")
            }
        }
    }

    private func playBuffer(_ buffer: AVAudioPCMBuffer) throws {
        let audioSession = AVAudioSession.sharedInstance()
        if audioSession.category != .ambient {
            try audioSession.setCategory(.ambient, mode: .default, options: [.mixWithOthers])
        }
        try audioSession.setActive(true)

        if !isConnected {
            engine.attach(player)
            engine.connect(player, to: engine.mainMixerNode, format: buffer.format)
            isConnected = true
        }

        if !engine.isRunning {
            engine.prepare()
            try engine.start()
        }

        player.scheduleBuffer(buffer, at: nil, options: .interrupts, completionHandler: nil)
        if !player.isPlaying {
            player.play()
        }
    }

    // MARK: - Synthesis

    private func chime() throws -> AVAudioPCMBuffer {
        if let chimeBuffer = chimeBuffer {
            return chimeBuffer
        }

        let buffer = try Self.synthesize(
            duration: 0.24,
            frequency: { Self.exponentialRamp(from: 720, to: 1_080, at: $0, over: 0.12) },
            gain: { time in
                time < 0.01
                    ? Self.exponentialRamp(from: 0.0001, to: 0.12, at: time, over: 0.01)
                    : Self.exponentialRamp(from: 0.12, to: 0.0001, at: time - 0.01, over: 0.21)
            },
            wave: { sin($0) }
        )
        chimeBuffer = buffer

        return buffer
    }

    private func shutter() throws -> AVAudioPCMBuffer {
        if let shutterBuffer = shutterBuffer {
            return shutterBuffer
        }

        let buffer = try Self.synthesize(
            duration: 0.09,
            frequency: { Self.exponentialRamp(from: 1_800, to: 700, at: $0, over: 0.07) },
            gain: { Self.exponentialRamp(from: 0.1, to: 0.0001, at: $0, over: 0.09) },
            wave: { sin($0) >= 0 ? 1 : -1 }
        )
        shutterBuffer = buffer

        return buffer
    }

    /// Web Audio's exponentialRampToValueAtTime: holds `to` after `duration`.
    private static func exponentialRamp(from start: Double, to end: Double, at time: Double, over duration: Double) -> Double {
        let progress = min(max(time / duration, 0), 1)

        return start * pow(end / start, progress)
    }

    private static func synthesize(
        duration: Double,
        frequency: (Double) -> Double,
        gain: (Double) -> Double,
        wave: (Double) -> Double
    ) throws -> AVAudioPCMBuffer {
        guard let format = AVAudioFormat(standardFormatWithSampleRate: sampleRate, channels: 1) else {
            throw ThriftyCameraError.encodingFailed
        }

        let frameCount = AVAudioFrameCount(sampleRate * duration)
        guard let buffer = AVAudioPCMBuffer(pcmFormat: format, frameCapacity: frameCount),
              let samples = buffer.floatChannelData?[0] else {
            throw ThriftyCameraError.encodingFailed
        }

        buffer.frameLength = frameCount

        // Accumulate phase so the glide is continuous.
        var phase = 0.0
        for index in 0..<Int(frameCount) {
            let time = Double(index) / sampleRate
            samples[index] = Float(gain(time) * wave(phase))
            phase += 2 * Double.pi * frequency(time) / sampleRate
        }

        return buffer
    }
}
