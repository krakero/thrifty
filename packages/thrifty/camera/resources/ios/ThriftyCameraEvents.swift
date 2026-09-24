import Foundation
import UIKit
import CoreImage
import AVFoundation

/// Event names and dispatch helpers shared by the Thrifty camera plugin.
///
/// Payload keys match the PHP event constructor parameter names, because
/// NativeComponent `#[On]` handlers receive the payload by parameter name.
enum ThriftyCameraEvents {
    static let frameCaptured = "Thrifty\\Camera\\Events\\FrameCaptured"
    static let videoFramesExtracted = "Thrifty\\Camera\\Events\\VideoFramesExtracted"
    static let cameraFailed = "Thrifty\\Camera\\Events\\CameraFailed"
    static let cameraStarted = "Thrifty\\Camera\\Events\\CameraStarted"

    static func cameraStarted(facing: String) {
        print("[ThriftyCamera] Camera started (\(facing))")
        send(cameraStarted, ["facing": facing])
    }

    static func frameCaptured(_ frame: ThriftyEncodedFrame, source: String, videoSeconds: Double? = nil, runId: String? = nil) {
        var payload: [String: Any] = [
            "path": frame.path,
            "source": source,
            "width": frame.width,
            "height": frame.height,
            "capturedAt": frame.capturedAt,
        ]

        // Omitted rather than null so a `?float $videoSeconds = null`
        // handler parameter keeps its default instead of coercing to 0.0.
        if let videoSeconds = videoSeconds {
            payload["videoSeconds"] = videoSeconds
        }

        if let runId = runId {
            payload["runId"] = runId
        }

        send(frameCaptured, payload)
    }

    static func videoFramesExtracted(count: Int, runId: String?) {
        var payload: [String: Any] = ["count": count]

        if let runId = runId {
            payload["runId"] = runId
        }

        send(videoFramesExtracted, payload)
    }

    /// `runId` is set when the failure belongs to a video extraction run.
    static func cameraFailed(_ message: String, runId: String? = nil) {
        print("[ThriftyCamera] \(message)")

        var payload: [String: Any] = ["message": message]
        if let runId = runId {
            payload["runId"] = runId
        }

        send(cameraFailed, payload)
    }

    private static func send(_ event: String, _ payload: [String: Any]) {
        DispatchQueue.main.async {
            LaravelBridge.shared.send?(event, payload)
        }
    }
}

/// A JPEG frame that has been written to disk.
struct ThriftyEncodedFrame {
    let path: String
    let width: Int
    let height: Int
    let capturedAt: String
}

/// Downscales and writes frames as JPEG with the web app's capture
/// parameters: at most 960px wide (scaled by width, never upscaled),
/// quality 0.76 for camera and video frames and 0.82 for picked images.
enum ThriftyFrameWriter {
    static let maxWidth: CGFloat = 960
    static let frameQuality: CGFloat = 0.76
    static let imageQuality: CGFloat = 0.82

    private static let ciContext = CIContext(options: [.cacheIntermediates: false])

    private static let timestampFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter
    }()

    static func timestamp(_ date: Date = Date()) -> String {
        timestampFormatter.string(from: date)
    }

    /// The web's `Math.min(1, 960 / width)`.
    static func scale(forWidth width: CGFloat) -> CGFloat {
        min(1, maxWidth / max(width, 1))
    }

    /// Renders a (camera) CIImage into a downscaled CGImage. Done
    /// synchronously on the capture queue so the pixel buffer is released
    /// back to the capture pool before the slower JPEG encode.
    static func downscaledImage(from ciImage: CIImage) -> CGImage? {
        let extent = ciImage.extent.integral
        guard extent.width > 0, extent.height > 0 else {
            return nil
        }

        let scale = scale(forWidth: extent.width)

        var image = ciImage.transformed(by: CGAffineTransform(translationX: -extent.origin.x, y: -extent.origin.y))
        if scale < 1 {
            image = image.transformed(by: CGAffineTransform(scaleX: scale, y: scale))
        }

        let outputRect = CGRect(
            x: 0,
            y: 0,
            width: max(1, (extent.width * scale).rounded()),
            height: max(1, (extent.height * scale).rounded())
        )

        return ciContext.createCGImage(image, from: outputRect)
    }

    static func write(cgImage: CGImage, to directory: String, quality: CGFloat = frameQuality, capturedAt: Date = Date()) throws -> ThriftyEncodedFrame {
        var source = cgImage
        let scale = scale(forWidth: CGFloat(cgImage.width))

        if scale < 1, let resized = resize(cgImage, scale: scale) {
            source = resized
        }

        guard let data = UIImage(cgImage: source).jpegData(compressionQuality: quality) else {
            throw ThriftyCameraError.encodingFailed
        }

        let directoryURL = URL(fileURLWithPath: directory, isDirectory: true)
        try FileManager.default.createDirectory(at: directoryURL, withIntermediateDirectories: true)

        let fileURL = directoryURL.appendingPathComponent(UUID().uuidString.lowercased()).appendingPathExtension("jpg")
        try data.write(to: fileURL, options: .atomic)

        return ThriftyEncodedFrame(
            path: fileURL.path,
            width: source.width,
            height: source.height,
            capturedAt: timestamp(capturedAt)
        )
    }

    private static func resize(_ image: CGImage, scale: CGFloat) -> CGImage? {
        let size = CGSize(
            width: max(1, (CGFloat(image.width) * scale).rounded()),
            height: max(1, (CGFloat(image.height) * scale).rounded())
        )

        let format = UIGraphicsImageRendererFormat()
        format.scale = 1
        format.opaque = true

        return UIGraphicsImageRenderer(size: size, format: format).image { _ in
            UIImage(cgImage: image).draw(in: CGRect(origin: .zero, size: size))
        }.cgImage
    }
}

enum ThriftyCameraError: LocalizedError {
    case encodingFailed
    case cameraUnavailable
    case permissionDenied

    /// False on the simulator and on devices without a camera, so capture
    /// is never configured there (AVFoundation fails with opaque errors).
    static var hasCamera: Bool {
        #if targetEnvironment(simulator)
        return false
        #else
        return AVCaptureDevice.default(for: .video) != nil
        #endif
    }

    /// Maps AVFoundation / NSError failures to a message a person can act
    /// on. Raw system text ("The operation could not be completed") is
    /// never shown; it is logged instead.
    static func friendlyMessage(for error: Error?, fallback: String) -> String {
        if let error = error {
            print("[ThriftyCamera] Underlying error: \(error)")
        }

        switch AVCaptureDevice.authorizationStatus(for: .video) {
        case .denied, .restricted:
            return ThriftyCameraError.permissionDenied.localizedDescription
        default:
            break
        }

        guard hasCamera else {
            return ThriftyCameraError.cameraUnavailable.localizedDescription
        }

        guard let avError = error as? AVError else {
            return fallback
        }

        switch avError.code {
        case .applicationIsNotAuthorizedToUseDevice:
            return ThriftyCameraError.permissionDenied.localizedDescription
        case .deviceAlreadyUsedByAnotherSession:
            return "Another app is using the camera. Close it and try again."
        case .deviceNotConnected, .deviceWasDisconnected:
            return ThriftyCameraError.cameraUnavailable.localizedDescription
        case .deviceIsNotAvailableInBackground, .sessionWasInterrupted, .mediaServicesWereReset:
            return "The camera was interrupted. Turn it off and on again."
        default:
            return fallback
        }
    }

    var errorDescription: String? {
        switch self {
        case .encodingFailed:
            return "Couldn't save the camera frame."
        case .cameraUnavailable:
            return "No camera is available on this device."
        case .permissionDenied:
            return "Camera access is off — enable it in Settings."
        }
    }
}

/// Finds the view controller that is currently on screen, for presenting
/// system sheets.
enum ThriftyPresenter {
    static func topViewController() -> UIViewController? {
        let scenes = UIApplication.shared.connectedScenes.compactMap { $0 as? UIWindowScene }
        let window = scenes
            .filter { $0.activationState == .foregroundActive }
            .flatMap(\.windows)
            .first(where: \.isKeyWindow)
            ?? scenes.flatMap(\.windows).first(where: \.isKeyWindow)
            ?? scenes.first?.windows.first

        var top = window?.rootViewController
        while let presented = top?.presentedViewController, !presented.isBeingDismissed {
            top = presented
        }

        return top
    }
}
