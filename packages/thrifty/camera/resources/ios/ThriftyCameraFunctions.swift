import AVFoundation
import Foundation
import UIKit

// MARK: - ThriftyCamera Function Namespace

/// Bridge functions for the Thrifty camera plugin.
/// Namespace: "ThriftyCamera.*"
///
/// Long-running work is dispatched off the calling thread; results come
/// back to PHP as events (FrameCaptured, VideoFramesExtracted, CameraFailed).
enum ThriftyCameraFunctions {

    // MARK: - ThriftyCamera.Snapshot

    /// Saves the next live preview frame into `directory` (source "snapshot").
    class Snapshot: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let directory = parameters["directory"] as? String, !directory.isEmpty else {
                throw BridgeError.invalidParameters("directory is required")
            }

            ThriftyCameraController.shared.requestSnapshot(directory: directory)

            return ["queued": true]
        }
    }

    // MARK: - ThriftyCamera.ExtractVideoFrames

    /// Samples a video every `intervalSeconds` into `directory`.
    class ExtractVideoFrames: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let videoPath = parameters["videoPath"] as? String, !videoPath.isEmpty else {
                throw BridgeError.invalidParameters("videoPath is required")
            }

            guard let directory = parameters["directory"] as? String, !directory.isEmpty else {
                throw BridgeError.invalidParameters("directory is required")
            }

            let interval = max(1, (parameters["intervalSeconds"] as? NSNumber)?.doubleValue ?? 3)

            Task.detached(priority: .userInitiated) {
                await ThriftyVideoFrameExtractor.extract(videoPath: videoPath, interval: interval, directory: directory)
            }

            return ["started": true]
        }
    }

    // MARK: - ThriftyCamera.Chime

    /// Plays the find chime plus a success haptic.
    class Chime: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            ThriftyChimePlayer.shared.play()

            return ["played": true]
        }
    }

    // MARK: - ThriftyCamera.ShareFindCard

    /// Renders the find card image and presents the share sheet.
    class ShareFindCard: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let card = ThriftyFindCard(parameters: parameters)

            DispatchQueue.global(qos: .userInitiated).async {
                let image = ThriftyFindCardRenderer.render(card)

                DispatchQueue.main.async {
                    guard let presenter = ThriftyPresenter.topViewController() else {
                        ThriftyCameraEvents.cameraFailed("Couldn't open the share sheet.")
                        return
                    }

                    let sheet = UIActivityViewController(activityItems: [image], applicationActivities: nil)

                    if let popover = sheet.popoverPresentationController {
                        popover.sourceView = presenter.view
                        popover.sourceRect = CGRect(x: presenter.view.bounds.midX, y: presenter.view.bounds.maxY, width: 0, height: 0)
                        popover.permittedArrowDirections = []
                    }

                    presenter.present(sheet, animated: true)
                }
            }

            return ["presenting": true]
        }
    }
}

// MARK: - Video frame extraction

enum ThriftyVideoFrameExtractor {
    static func extract(videoPath: String, interval: Double, directory: String) async {
        let url: URL
        if videoPath.hasPrefix("file://"), let fileURL = URL(string: videoPath) {
            url = fileURL
        } else {
            url = URL(fileURLWithPath: videoPath)
        }

        guard FileManager.default.fileExists(atPath: url.path) else {
            ThriftyCameraEvents.cameraFailed("Couldn't find that video.")
            return
        }

        let asset = AVURLAsset(url: url)

        let duration: Double
        do {
            duration = try await asset.load(.duration).seconds
            let tracks = try await asset.loadTracks(withMediaType: .video)

            guard !tracks.isEmpty, duration.isFinite, duration > 0 else {
                ThriftyCameraEvents.cameraFailed("That file doesn't contain any video to scan.")
                return
            }
        } catch {
            ThriftyCameraEvents.cameraFailed("Couldn't read that video: \(error.localizedDescription)")
            return
        }

        let generator = AVAssetImageGenerator(asset: asset)
        generator.appliesPreferredTrackTransform = true
        generator.maximumSize = CGSize(width: ThriftyFrameWriter.maxDimension, height: ThriftyFrameWriter.maxDimension)

        let tolerance = CMTime(seconds: min(0.5, interval / 4), preferredTimescale: 600)
        generator.requestedTimeToleranceBefore = tolerance
        generator.requestedTimeToleranceAfter = tolerance

        var count = 0
        var seconds = 0.0

        while seconds < duration {
            do {
                let (cgImage, actualTime) = try await generator.image(at: CMTime(seconds: seconds, preferredTimescale: 600))
                let frame = try ThriftyFrameWriter.write(cgImage: cgImage, to: directory)
                let position = actualTime.seconds.isFinite ? actualTime.seconds : seconds

                ThriftyCameraEvents.frameCaptured(frame, source: "video", videoSeconds: (position * 100).rounded() / 100)
                count += 1
            } catch {
                print("[ThriftyCamera] Skipped video frame at \(seconds)s: \(error.localizedDescription)")
            }

            seconds += interval
        }

        if count == 0 {
            ThriftyCameraEvents.cameraFailed("Couldn't read any frames from that video.")
        }

        ThriftyCameraEvents.videoFramesExtracted(count: count)
    }
}
