import AVFoundation
import Foundation
import ImageIO
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

    /// Plays through a video in real time, sampling a frame at 0.35s and
    /// then every `intervalSeconds`, into `directory`.
    class ExtractVideoFrames: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let videoPath = parameters["videoPath"] as? String, !videoPath.isEmpty else {
                throw BridgeError.invalidParameters("videoPath is required")
            }

            guard let directory = parameters["directory"] as? String, !directory.isEmpty else {
                throw BridgeError.invalidParameters("directory is required")
            }

            let interval = max(1, (parameters["intervalSeconds"] as? NSNumber)?.doubleValue ?? 2)
            let runId = (parameters["runId"] as? String).flatMap { $0.isEmpty ? nil : $0 } ?? UUID().uuidString.lowercased()

            ThriftyVideoFrameExtractor.start(runId: runId, videoPath: videoPath, interval: interval, directory: directory)

            return ["started": true, "runId": runId]
        }
    }

    // MARK: - ThriftyCamera.ImportImage

    /// Normalizes a picked image into a JPEG frame in `directory` (source "image").
    class ImportImage: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let imagePath = parameters["imagePath"] as? String, !imagePath.isEmpty else {
                throw BridgeError.invalidParameters("imagePath is required")
            }

            guard let directory = parameters["directory"] as? String, !directory.isEmpty else {
                throw BridgeError.invalidParameters("directory is required")
            }

            DispatchQueue.global(qos: .userInitiated).async {
                ThriftyImageImporter.importImage(imagePath: imagePath, directory: directory)
            }

            return ["started": true]
        }
    }

    // MARK: - ThriftyCamera.CancelVideoExtraction

    /// Cancels a running video extraction; it still ends with VideoFramesExtracted.
    class CancelVideoExtraction: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let runId = parameters["runId"] as? String, !runId.isEmpty else {
                throw BridgeError.invalidParameters("runId is required")
            }

            return ["cancelled": ThriftyVideoFrameExtractor.cancel(runId: runId)]
        }
    }

    // MARK: - ThriftyCamera.Shutter

    /// Plays the snapshot feedback: a short shutter click and a light haptic.
    class Shutter: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            ThriftyChimePlayer.shared.playShutter()

            return ["played": true]
        }
    }

    // MARK: - ThriftyCamera.DeviceTimezone

    /// Returns the device's IANA timezone identifier.
    class DeviceTimezone: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            ["timezone": TimeZone.current.identifier]
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

// MARK: - Image import

enum ThriftyImageImporter {
    /// Decodes anything ImageIO understands (HEIC/HEIF, PNG, JPEG, WebP, …),
    /// applies the EXIF orientation and downscales in a single pass, then
    /// writes the result through the shared frame writer.
    static func importImage(imagePath: String, directory: String) {
        let url = fileURL(imagePath)

        guard FileManager.default.fileExists(atPath: url.path) else {
            ThriftyCameraEvents.cameraFailed("Couldn't find that image.")
            return
        }

        guard let source = CGImageSourceCreateWithURL(url as CFURL, [kCGImageSourceShouldCache: false] as CFDictionary),
              CGImageSourceGetCount(source) > 0 else {
            ThriftyCameraEvents.cameraFailed("That file isn't an image Thrifty can read.")
            return
        }

        var options: [CFString: Any] = [
            kCGImageSourceCreateThumbnailFromImageAlways: true,
            kCGImageSourceCreateThumbnailWithTransform: true,
            kCGImageSourceShouldCacheImmediately: true,
        ]

        // Decode straight to the web's size (at most 960px wide once
        // oriented) instead of decoding a full 12MP HEIC first.
        if let maxPixelSize = decodeMaxPixelSize(source) {
            options[kCGImageSourceThumbnailMaxPixelSize] = maxPixelSize
        }

        guard let image = CGImageSourceCreateThumbnailAtIndex(source, 0, options as CFDictionary) else {
            ThriftyCameraEvents.cameraFailed("Couldn't decode that image. Try a JPEG, PNG or HEIC photo.")
            return
        }

        do {
            let frame = try ThriftyFrameWriter.write(cgImage: image, to: directory, quality: ThriftyFrameWriter.imageQuality)
            ThriftyCameraEvents.frameCaptured(frame, source: "image")
        } catch {
            ThriftyCameraEvents.cameraFailed("Couldn't save that image: \(error.localizedDescription)")
        }
    }

    /// The longest side to decode at so the oriented width lands on 960px.
    private static func decodeMaxPixelSize(_ source: CGImageSource) -> Int? {
        guard let properties = CGImageSourceCopyPropertiesAtIndex(source, 0, nil) as? [CFString: Any],
              let pixelWidth = (properties[kCGImagePropertyPixelWidth] as? NSNumber)?.doubleValue,
              let pixelHeight = (properties[kCGImagePropertyPixelHeight] as? NSNumber)?.doubleValue,
              pixelWidth > 0, pixelHeight > 0 else {
            return nil
        }

        // EXIF orientations 5–8 rotate by 90°, swapping width and height.
        let orientation = (properties[kCGImagePropertyOrientation] as? NSNumber)?.intValue ?? 1
        let orientedWidth = orientation >= 5 ? pixelHeight : pixelWidth
        let scale = ThriftyFrameWriter.scale(forWidth: CGFloat(orientedWidth))

        return Int((max(pixelWidth, pixelHeight) * Double(scale)).rounded(.up))
    }

    static func fileURL(_ path: String) -> URL {
        if path.hasPrefix("file://"), let url = URL(string: path) {
            return url
        }

        return URL(fileURLWithPath: path)
    }
}

// MARK: - Video frame extraction

/// Mirrors the web app's video scanning: the video "plays" in real time and
/// a frame is sampled at 0.35s, then every `interval` seconds of wall-clock
/// time, until the end of the video. Each run has an id carried by its
/// events and can be cancelled. Every exit path (end, failure, cancel) ends
/// with VideoFramesExtracted.
enum ThriftyVideoFrameExtractor {
    static let firstFrameDelay = 0.35

    private static let lock = NSLock()
    private static var runs: [String: Task<Void, Never>] = [:]

    static func start(runId: String, videoPath: String, interval: Double, directory: String) {
        lock.lock()
        defer { lock.unlock() }

        runs[runId]?.cancel()
        runs[runId] = Task.detached(priority: .userInitiated) {
            let count = await extract(runId: runId, videoPath: videoPath, interval: interval, directory: directory)

            ThriftyCameraEvents.videoFramesExtracted(count: count, runId: runId)

            lock.withLock {
                runs[runId] = nil
            }
        }
    }

    /// Returns false when no run with that id is active.
    @discardableResult
    static func cancel(runId: String) -> Bool {
        lock.lock()
        defer { lock.unlock() }

        guard let task = runs[runId] else {
            return false
        }

        task.cancel()

        return true
    }

    /// Returns the number of frames emitted.
    private static func extract(runId: String, videoPath: String, interval: Double, directory: String) async -> Int {
        let url = ThriftyImageImporter.fileURL(videoPath)

        guard FileManager.default.fileExists(atPath: url.path) else {
            ThriftyCameraEvents.cameraFailed("Couldn't find that video.")
            return 0
        }

        let asset = AVURLAsset(url: url)

        let duration: Double
        do {
            duration = try await asset.load(.duration).seconds
            let tracks = try await asset.loadTracks(withMediaType: .video)

            guard !tracks.isEmpty, duration.isFinite, duration > 0 else {
                ThriftyCameraEvents.cameraFailed("That file doesn't contain any video to scan.")
                return 0
            }
        } catch {
            ThriftyCameraEvents.cameraFailed("Couldn't read that video: \(error.localizedDescription)")
            return 0
        }

        let generator = AVAssetImageGenerator(asset: asset)
        generator.appliesPreferredTrackTransform = true

        // Frames should show what was on screen at that moment, like a
        // canvas grab of the playing <video>.
        let tolerance = CMTime(seconds: 0.05, preferredTimescale: 600)
        generator.requestedTimeToleranceBefore = tolerance
        generator.requestedTimeToleranceAfter = tolerance

        let startedAt = Date()
        var count = 0
        var nextSampleAt = firstFrameDelay
        var lastPosition = -1.0

        while !Task.isCancelled {
            let wait = nextSampleAt - Date().timeIntervalSince(startedAt)
            if wait > 0 {
                do {
                    try await Task.sleep(nanoseconds: UInt64(wait * 1_000_000_000))
                } catch {
                    break
                }
            }

            // The playhead is wherever real time says it is (1x playback).
            let position = Date().timeIntervalSince(startedAt)
            guard position < duration, !Task.isCancelled else {
                break
            }

            nextSampleAt += interval
            while nextSampleAt <= Date().timeIntervalSince(startedAt) {
                nextSampleAt += interval
            }

            // Like the web, skip when the playhead hasn't moved.
            guard position > lastPosition else {
                continue
            }
            lastPosition = position

            do {
                let (cgImage, actualTime) = try await generator.image(at: CMTime(seconds: position, preferredTimescale: 600))
                guard !Task.isCancelled else {
                    break
                }

                let frame = try ThriftyFrameWriter.write(cgImage: cgImage, to: directory, quality: ThriftyFrameWriter.frameQuality)
                let seconds = actualTime.seconds.isFinite ? actualTime.seconds : position

                ThriftyCameraEvents.frameCaptured(frame, source: "video", videoSeconds: (seconds * 100).rounded() / 100, runId: runId)
                count += 1
            } catch {
                print("[ThriftyCamera] Skipped video frame at \(position)s: \(error.localizedDescription)")
            }
        }

        if count == 0 && !Task.isCancelled {
            ThriftyCameraEvents.cameraFailed("Couldn't read any frames from that video.")
        }

        return count
    }
}
