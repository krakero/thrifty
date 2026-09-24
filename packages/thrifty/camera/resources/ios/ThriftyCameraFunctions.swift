import AVFoundation
import Foundation
import ImageIO
import LinkPresentation
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

    // MARK: - ThriftyCamera.SetVideoFrameInterval

    /// Changes a running video run's sampling interval: the next frame comes
    /// `intervalSeconds` after the change (like the web restarting its timer),
    /// then every `intervalSeconds`. A no-op for unknown or finished runs.
    class SetVideoFrameInterval: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let runId = parameters["runId"] as? String, !runId.isEmpty else {
                throw BridgeError.invalidParameters("runId is required")
            }

            let interval = max(1, (parameters["intervalSeconds"] as? NSNumber)?.doubleValue ?? 2)

            return ["updated": ThriftyVideoFrameExtractor.setInterval(runId: runId, seconds: interval)]
        }
    }

    // MARK: - ThriftyCamera.VideoRunStatus

    /// Whether a video extraction run is still active and how many frames it
    /// has emitted, so the Scan screen can re-sync after being covered.
    class VideoRunStatus: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let runId = parameters["runId"] as? String, !runId.isEmpty else {
                throw BridgeError.invalidParameters("runId is required")
            }

            let status = ThriftyVideoFrameExtractor.status(runId: runId)

            return ["active": status.active, "framesEmitted": status.framesEmitted]
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

                    let title = card.title.isEmpty ? "Thrifty find" : card.title
                    let item = ThriftyFindCardItemSource(image: image, title: title)
                    let sheet = UIActivityViewController(activityItems: [item], applicationActivities: nil)

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
            print("[ThriftyCamera] Image write failed: \(error)")
            ThriftyCameraEvents.cameraFailed("Couldn't save that image.")
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
    private static var pacing: [String: ThriftyVideoPacing] = [:]

    /// Frames emitted per run, kept after the run ends (most recent runs only).
    private static var framesEmitted: [String: Int] = [:]
    private static var framesEmittedOrder: [String] = []
    private static let maxRememberedRuns = 50

    static func start(runId: String, videoPath: String, interval: Double, directory: String) {
        lock.lock()
        defer { lock.unlock() }

        runs[runId]?.cancel()
        rememberRun(runId)

        let runPacing = ThriftyVideoPacing(interval: interval)
        pacing[runId] = runPacing

        runs[runId] = Task.detached(priority: .userInitiated) {
            let count = await extract(runId: runId, videoPath: videoPath, pacing: runPacing, directory: directory)

            ThriftyCameraEvents.videoFramesExtracted(count: count, runId: runId)

            lock.withLock {
                runs[runId] = nil
                pacing[runId] = nil
            }
        }
    }

    /// Returns false (and does nothing) for unknown or finished runs.
    @discardableResult
    static func setInterval(runId: String, seconds: Double) -> Bool {
        guard let runPacing = lock.withLock({ pacing[runId] }) else {
            return false
        }

        runPacing.change(to: seconds)

        return true
    }

    static func status(runId: String) -> (active: Bool, framesEmitted: Int) {
        lock.withLock {
            (runs[runId] != nil, framesEmitted[runId] ?? 0)
        }
    }

    /// Call with the lock held.
    private static func rememberRun(_ runId: String) {
        if framesEmitted[runId] == nil {
            framesEmittedOrder.append(runId)
        }
        framesEmitted[runId] = 0

        while framesEmittedOrder.count > maxRememberedRuns {
            framesEmitted[framesEmittedOrder.removeFirst()] = nil
        }
    }

    private static func frameEmitted(runId: String) {
        lock.withLock {
            framesEmitted[runId, default: 0] += 1
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
    private static func extract(runId: String, videoPath: String, pacing: ThriftyVideoPacing, directory: String) async -> Int {
        let url = ThriftyImageImporter.fileURL(videoPath)

        guard FileManager.default.fileExists(atPath: url.path) else {
            ThriftyCameraEvents.cameraFailed("Couldn't find that video.", runId: runId)
            return 0
        }

        let asset = AVURLAsset(url: url)

        let duration: Double
        let orientedSize: CGSize
        do {
            duration = try await asset.load(.duration).seconds
            let tracks = try await asset.loadTracks(withMediaType: .video)

            guard let track = tracks.first, duration.isFinite, duration > 0 else {
                ThriftyCameraEvents.cameraFailed("That file doesn't contain any video to scan.", runId: runId)
                return 0
            }

            let (naturalSize, transform) = try await track.load(.naturalSize, .preferredTransform)
            let transformed = naturalSize.applying(transform)
            orientedSize = CGSize(width: abs(transformed.width), height: abs(transformed.height))
        } catch {
            print("[ThriftyCamera] Video load failed: \(error)")
            ThriftyCameraEvents.cameraFailed("Couldn't read that video. Try an MP4 or MOV file.", runId: runId)
            return 0
        }

        let generator = AVAssetImageGenerator(asset: asset)
        generator.appliesPreferredTrackTransform = true

        // Decode at the output size (960px wide once oriented). A square
        // bound on the longest side keeps this right whichever way the
        // track is rotated; the writer then lands the exact width.
        if orientedSize.width > 0, orientedSize.height > 0 {
            let scale = ThriftyFrameWriter.scale(forWidth: orientedSize.width)
            let longest = (max(orientedSize.width, orientedSize.height) * scale).rounded(.up)
            generator.maximumSize = CGSize(width: longest, height: longest)
        }

        // Frames should show what was on screen at that moment, like a
        // canvas grab of the playing <video>.
        let tolerance = CMTime(seconds: 0.05, preferredTimescale: 600)
        generator.requestedTimeToleranceBefore = tolerance
        generator.requestedTimeToleranceAfter = tolerance

        let clock = ThriftyPlaybackClock()
        var count = 0
        var nextSampleAt = firstFrameDelay
        var lastPosition = -1.0

        while !Task.isCancelled {
            // Sleep until the next sample, or until the video ends if that
            // comes first, so the run ends exactly at the end of the video.
            // Wakes in short steps so an interval change applies right away.
            do {
                while true {
                    if pacing.takeChange() {
                        nextSampleAt = clock.elapsed + pacing.interval
                    }

                    let target = min(nextSampleAt, duration)
                    if clock.elapsed >= target {
                        break
                    }

                    try await clock.sleep(until: min(target, clock.elapsed + 0.25))
                }
            } catch {
                break
            }

            // The playhead is wherever playback time says it is (1x).
            let position = clock.elapsed
            guard position < duration, !Task.isCancelled else {
                break
            }

            let interval = pacing.interval
            nextSampleAt += interval
            while nextSampleAt <= clock.elapsed {
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
                frameEmitted(runId: runId)
            } catch {
                print("[ThriftyCamera] Skipped video frame at \(position)s: \(error.localizedDescription)")
            }
        }

        if count == 0 && !Task.isCancelled {
            ThriftyCameraEvents.cameraFailed("Couldn't read any frames from that video.", runId: runId)
        }

        return count
    }
}

/// Playback time for a video run: wall-clock time since the start, minus
/// any time the app spent in the background (iOS pauses a playing video
/// when the app leaves the foreground, so sampling pauses too).
final class ThriftyPlaybackClock {
    private let lock = NSLock()
    private let startedAt = Date()
    private var pausedTotal: TimeInterval = 0
    private var pausedSince: Date?
    private var observers: [NSObjectProtocol] = []

    init() {
        let center = NotificationCenter.default

        observers.append(center.addObserver(forName: UIApplication.didEnterBackgroundNotification, object: nil, queue: nil) { [weak self] _ in
            self?.pause()
        })
        observers.append(center.addObserver(forName: UIApplication.willEnterForegroundNotification, object: nil, queue: nil) { [weak self] _ in
            self?.resume()
        })

        // Started while already in the background (unlikely, but consistent).
        DispatchQueue.main.async { [weak self] in
            if UIApplication.shared.applicationState == .background {
                self?.pause()
            }
        }
    }

    deinit {
        observers.forEach(NotificationCenter.default.removeObserver)
    }

    var elapsed: TimeInterval {
        lock.withLock {
            let now = Date()
            let pausedNow = pausedSince.map { now.timeIntervalSince($0) } ?? 0

            return now.timeIntervalSince(startedAt) - pausedTotal - pausedNow
        }
    }

    var isPaused: Bool {
        lock.withLock { pausedSince != nil }
    }

    /// Sleeps until playback time reaches `target`, waiting out any pause.
    func sleep(until target: TimeInterval) async throws {
        while true {
            try Task.checkCancellation()

            if isPaused {
                try await Task.sleep(nanoseconds: 250_000_000)
                continue
            }

            let remaining = target - elapsed
            guard remaining > 0 else {
                return
            }

            // Re-check at least every 0.5s so a background pause is noticed.
            try await Task.sleep(nanoseconds: UInt64(min(remaining, 0.5) * 1_000_000_000))
        }
    }

    private func pause() {
        lock.withLock {
            if pausedSince == nil {
                pausedSince = Date()
            }
        }
    }

    private func resume() {
        lock.withLock {
            if let since = pausedSince {
                pausedTotal += Date().timeIntervalSince(since)
                pausedSince = nil
            }
        }
    }
}

// MARK: - Share sheet item

/// Shares the rendered card image and gives the share sheet a proper header
/// (the find's title and the card as its thumbnail) instead of the generic
/// placeholder.
final class ThriftyFindCardItemSource: NSObject, UIActivityItemSource {
    private let image: UIImage
    private let title: String

    init(image: UIImage, title: String) {
        self.image = image
        self.title = title
    }

    func activityViewControllerPlaceholderItem(_ activityViewController: UIActivityViewController) -> Any {
        image
    }

    func activityViewController(_ activityViewController: UIActivityViewController, itemForActivityType activityType: UIActivity.ActivityType?) -> Any? {
        image
    }

    func activityViewController(_ activityViewController: UIActivityViewController, subjectForActivityType activityType: UIActivity.ActivityType?) -> String {
        title
    }

    func activityViewControllerLinkMetadata(_ activityViewController: UIActivityViewController) -> LPLinkMetadata? {
        let metadata = LPLinkMetadata()
        metadata.title = title
        metadata.iconProvider = NSItemProvider(object: image)
        metadata.imageProvider = NSItemProvider(object: image)

        return metadata
    }
}

/// A video run's sampling interval, changeable while it runs.
final class ThriftyVideoPacing {
    private let lock = NSLock()
    private var currentInterval: Double
    private var changed = false

    init(interval: Double) {
        currentInterval = max(1, interval)
    }

    var interval: Double {
        lock.withLock { currentInterval }
    }

    func change(to seconds: Double) {
        lock.withLock {
            currentInterval = max(1, seconds)
            changed = true
        }
    }

    /// True once after each change.
    func takeChange() -> Bool {
        lock.withLock {
            defer { changed = false }
            return changed
        }
    }
}
