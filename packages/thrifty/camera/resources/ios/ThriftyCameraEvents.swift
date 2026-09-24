import Foundation
import UIKit
import CoreImage

/// Event names and dispatch helpers shared by the Thrifty camera plugin.
///
/// Payload keys match the PHP event constructor parameter names, because
/// NativeComponent `#[On]` handlers receive the payload by parameter name.
enum ThriftyCameraEvents {
    static let frameCaptured = "Thrifty\\Camera\\Events\\FrameCaptured"
    static let videoFramesExtracted = "Thrifty\\Camera\\Events\\VideoFramesExtracted"
    static let cameraFailed = "Thrifty\\Camera\\Events\\CameraFailed"

    static func frameCaptured(_ frame: ThriftyEncodedFrame, source: String, videoSeconds: Double? = nil) {
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

        send(frameCaptured, payload)
    }

    static func videoFramesExtracted(count: Int) {
        send(videoFramesExtracted, ["count": count])
    }

    static func cameraFailed(_ message: String) {
        print("[ThriftyCamera] \(message)")
        send(cameraFailed, ["message": message])
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

/// Downscales and writes frames as JPEG, matching the web app's compressed
/// sampling: longest side at most 1280px, quality 0.7.
enum ThriftyFrameWriter {
    static let maxDimension: CGFloat = 1280
    static let jpegQuality: CGFloat = 0.7

    private static let ciContext = CIContext(options: [.cacheIntermediates: false])

    private static let timestampFormatter: ISO8601DateFormatter = {
        let formatter = ISO8601DateFormatter()
        formatter.formatOptions = [.withInternetDateTime, .withFractionalSeconds]
        return formatter
    }()

    static func timestamp(_ date: Date = Date()) -> String {
        timestampFormatter.string(from: date)
    }

    /// Renders a (camera) CIImage into a downscaled CGImage. Done
    /// synchronously on the capture queue so the pixel buffer is released
    /// back to the capture pool before the slower JPEG encode.
    static func downscaledImage(from ciImage: CIImage) -> CGImage? {
        let extent = ciImage.extent.integral
        guard extent.width > 0, extent.height > 0 else {
            return nil
        }

        let scale = min(1, maxDimension / max(extent.width, extent.height))

        var image = ciImage.transformed(by: CGAffineTransform(translationX: -extent.origin.x, y: -extent.origin.y))
        if scale < 1 {
            image = image.transformed(by: CGAffineTransform(scaleX: scale, y: scale))
        }

        let outputRect = CGRect(
            x: 0,
            y: 0,
            width: max(1, (extent.width * scale).rounded(.down)),
            height: max(1, (extent.height * scale).rounded(.down))
        )

        return ciContext.createCGImage(image, from: outputRect)
    }

    static func write(cgImage: CGImage, to directory: String, capturedAt: Date = Date()) throws -> ThriftyEncodedFrame {
        var source = cgImage
        let longest = CGFloat(max(cgImage.width, cgImage.height))

        if longest > maxDimension, let resized = resize(cgImage, scale: maxDimension / longest) {
            source = resized
        }

        guard let data = UIImage(cgImage: source).jpegData(compressionQuality: jpegQuality) else {
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

    var errorDescription: String? {
        switch self {
        case .encodingFailed:
            return "Couldn't save the camera frame."
        case .cameraUnavailable:
            return "No camera is available on this device."
        case .permissionDenied:
            return "Camera access is off. Allow it for Thrifty in Settings to scan."
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
