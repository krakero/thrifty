import AVFoundation
import Foundation
import UIKit

/// Plays the "find" chime: two quick rising sine notes (720Hz → 1080Hz, the
/// same interval as the web app's glide) synthesized once into a PCM buffer.
///
/// Uses the ambient audio category so it mixes with the user's music and
/// respects the silent switch; the success haptic fires either way.
final class ThriftyChimePlayer {
    static let shared = ThriftyChimePlayer()

    private let queue = DispatchQueue(label: "com.thrifty.camera.chime", qos: .userInitiated)
    private let engine = AVAudioEngine()
    private let player = AVAudioPlayerNode()
    private var buffer: AVAudioPCMBuffer?

    func play() {
        DispatchQueue.main.async {
            let haptic = UINotificationFeedbackGenerator()
            haptic.prepare()
            haptic.notificationOccurred(.success)
        }

        queue.async {
            do {
                try self.playChime()
            } catch {
                print("[ThriftyCamera] Chime failed: \(error.localizedDescription)")
            }
        }
    }

    private func playChime() throws {
        let audioSession = AVAudioSession.sharedInstance()
        if audioSession.category != .ambient {
            try audioSession.setCategory(.ambient, mode: .default, options: [.mixWithOthers])
        }
        try audioSession.setActive(true)

        let chime = try preparedBuffer()

        if !engine.isRunning {
            engine.prepare()
            try engine.start()
        }

        player.scheduleBuffer(chime, at: nil, options: .interrupts, completionHandler: nil)
        if !player.isPlaying {
            player.play()
        }
    }

    private func preparedBuffer() throws -> AVAudioPCMBuffer {
        if let buffer = buffer {
            return buffer
        }

        let sampleRate = 44_100.0
        guard let format = AVAudioFormat(standardFormatWithSampleRate: sampleRate, channels: 1) else {
            throw ThriftyCameraError.encodingFailed
        }

        let duration = 0.38
        let frameCount = AVAudioFrameCount(sampleRate * duration)
        guard let pcm = AVAudioPCMBuffer(pcmFormat: format, frameCapacity: frameCount),
              let samples = pcm.floatChannelData?[0] else {
            throw ThriftyCameraError.encodingFailed
        }

        pcm.frameLength = frameCount

        let notes: [(frequency: Double, start: Double, length: Double, peak: Double)] = [
            (720, 0.0, 0.16, 0.22),
            (1080, 0.09, 0.29, 0.24),
        ]

        for index in 0..<Int(frameCount) {
            let time = Double(index) / sampleRate
            var value = 0.0

            for note in notes where time >= note.start && time < note.start + note.length {
                let local = time - note.start
                let attack = min(1, local / 0.01)
                let decay = exp(-local * 18)
                let tail = min(1, (note.start + note.length - time) / 0.02)
                let phase = 2 * Double.pi * note.frequency * local
                let tone = sin(phase) + 0.18 * sin(2 * phase)
                value += note.peak * attack * decay * tail * tone
            }

            samples[index] = Float(value)
        }

        engine.attach(player)
        engine.connect(player, to: engine.mainMixerNode, format: format)
        buffer = pcm

        return pcm
    }
}
