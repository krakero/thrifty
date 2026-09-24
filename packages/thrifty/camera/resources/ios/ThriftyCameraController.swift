import AVFoundation
import CoreImage
import Foundation
import UIKit

/// Owns the single AVCaptureSession behind every `thrifty_camera` preview.
///
/// Threading:
/// - session configuration, start and stop run on `sessionQueue`
/// - sample buffers arrive on `videoQueue`, where due frames are downscaled
/// - JPEG encoding and file writes run on `encodeQueue`
/// - scan settings are guarded by `stateLock` (written from the main thread
///   by the renderer, read on `videoQueue`)
final class ThriftyCameraController: NSObject, AVCaptureVideoDataOutputSampleBufferDelegate {
    static let shared = ThriftyCameraController()

    let session = AVCaptureSession()

    private let sessionQueue = DispatchQueue(label: "com.thrifty.camera.session")
    private let videoQueue = DispatchQueue(label: "com.thrifty.camera.video", qos: .userInitiated)
    private let encodeQueue = DispatchQueue(label: "com.thrifty.camera.encode", qos: .userInitiated)
    private let videoOutput = AVCaptureVideoDataOutput()

    /// Delay before the first live frame after scanning starts, matching the web app.
    private let firstFrameDelay: CFTimeInterval = 0.35

    // MARK: Scan state (stateLock)

    private let stateLock = NSLock()
    private var scanning = false
    private var interval: CFTimeInterval = 3
    private var framesDirectory = ""
    private var nextLiveCaptureAt: CFTimeInterval = 0
    private var isEncodingLiveFrame = false
    private var pendingSnapshots: [PendingSnapshot] = []

    /// How long a snapshot waits for the camera to deliver a frame, so a
    /// snap that also turns the camera on still captures (like the web).
    private let snapshotTimeout: TimeInterval = 4

    private struct PendingSnapshot {
        let id: UUID
        let directory: String
    }

    // MARK: Session state (sessionQueue)

    private var visiblePreviews = 0
    private var desiredFacing = "back"
    private var appIsActive = true
    private var configuredPosition: AVCaptureDevice.Position?
    private var currentInput: AVCaptureDeviceInput?
    private var outputConfigured = false
    private var reportedPermissionProblem = false
    private var reportedMissingCamera = false

    /// True only between our own startRunning() and stopRunning(). Attaching
    /// a preview layer makes AVFoundation build the capture graph even
    /// without a start, and on the simulator that posts runtime errors;
    /// those are ignored unless we actually meant the camera to run.
    private var sessionShouldRun = false

    private override init() {
        super.init()

        let center = NotificationCenter.default
        center.addObserver(self, selector: #selector(appDidEnterBackground), name: UIApplication.didEnterBackgroundNotification, object: nil)
        center.addObserver(self, selector: #selector(appWillEnterForeground), name: UIApplication.willEnterForegroundNotification, object: nil)
        center.addObserver(self, selector: #selector(sessionRuntimeError(_:)), name: AVCaptureSession.runtimeErrorNotification, object: session)
    }

    // MARK: - Renderer API (main thread)

    /// Applies the element's props. Safe to call on every SwiftUI update.
    func update(scanning newScanning: Bool, interval newInterval: Int, facing: String, framesDirectory directory: String) {
        stateLock.lock()
        let startedScanning = newScanning && !scanning
        if startedScanning {
            nextLiveCaptureAt = CACurrentMediaTime() + firstFrameDelay
        }
        scanning = newScanning
        interval = CFTimeInterval(max(1, newInterval))
        framesDirectory = directory
        stateLock.unlock()

        let normalizedFacing = ["back", "front", "off"].contains(facing) ? facing : "back"

        sessionQueue.async {
            // Starting a scan re-checks permission, so a denied camera is
            // reported every time the user tries, not just once.
            if startedScanning {
                self.reportedPermissionProblem = false
            }

            guard startedScanning || self.desiredFacing != normalizedFacing else {
                return
            }

            self.desiredFacing = normalizedFacing
            self.evaluateRunningState()
        }
    }

    /// A preview entered a window.
    func previewDidAppear() {
        sessionQueue.async {
            self.visiblePreviews += 1
            self.evaluateRunningState()
        }
    }

    /// A preview left its window (navigated away, tab switched, or torn down).
    func previewDidDisappear() {
        sessionQueue.async {
            self.visiblePreviews = max(0, self.visiblePreviews - 1)

            if self.visiblePreviews == 0 {
                self.reportedPermissionProblem = false
                self.reportedMissingCamera = false
            }

            self.evaluateRunningState()
        }
    }

    // MARK: - Snapshot API

    /// Queues the next preview frame to be saved into `directory` (source
    /// "snapshot"). If the camera is still starting (e.g. the same tap turned
    /// it on), the snapshot waits briefly for the first frame.
    func requestSnapshot(directory: String) {
        sessionQueue.async {
            guard ThriftyCameraError.hasCamera else {
                ThriftyCameraEvents.cameraFailed(ThriftyCameraError.cameraUnavailable.localizedDescription)
                return
            }

            switch AVCaptureDevice.authorizationStatus(for: .video) {
            case .denied, .restricted:
                ThriftyCameraEvents.cameraFailed(ThriftyCameraError.permissionDenied.localizedDescription)
                return
            default:
                break
            }

            let snapshot = PendingSnapshot(id: UUID(), directory: directory)

            self.stateLock.lock()
            self.pendingSnapshots.append(snapshot)
            self.stateLock.unlock()

            self.sessionQueue.asyncAfter(deadline: .now() + self.snapshotTimeout) {
                self.stateLock.lock()
                let stillPending = self.pendingSnapshots.contains { $0.id == snapshot.id }
                self.pendingSnapshots.removeAll { $0.id == snapshot.id }
                self.stateLock.unlock()

                guard stillPending else {
                    return
                }

                if AVCaptureDevice.authorizationStatus(for: .video) != .authorized {
                    ThriftyCameraEvents.cameraFailed(ThriftyCameraError.permissionDenied.localizedDescription)
                } else {
                    ThriftyCameraEvents.cameraFailed("Turn the camera on to take a snapshot.")
                }
            }
        }
    }

    // MARK: - Session lifecycle (sessionQueue)

    private func evaluateRunningState() {
        let shouldRun = visiblePreviews > 0 && appIsActive && desiredFacing != "off"

        guard shouldRun else {
            sessionShouldRun = false
            if session.isRunning {
                session.stopRunning()
            }
            return
        }

        // The simulator has no camera: never configure or start capture there.
        guard ThriftyCameraError.hasCamera else {
            reportMissingCamera()
            return
        }

        switch AVCaptureDevice.authorizationStatus(for: .video) {
        case .authorized:
            break
        case .notDetermined:
            sessionQueue.suspend()
            AVCaptureDevice.requestAccess(for: .video) { granted in
                self.sessionQueue.resume()

                if !granted {
                    self.sessionQueue.async { self.reportPermissionProblem() }
                }
            }
            // Re-evaluates once the prompt is answered and the queue resumes.
            sessionQueue.async { self.evaluateRunningState() }
            return
        default:
            reportPermissionProblem()
            return
        }

        guard configureSession(position: desiredFacing == "front" ? .front : .back) else {
            return
        }

        sessionShouldRun = true
        if !session.isRunning {
            session.startRunning()
        }
    }

    private func reportMissingCamera() {
        guard !reportedMissingCamera else {
            return
        }

        reportedMissingCamera = true
        ThriftyCameraEvents.cameraFailed(ThriftyCameraError.cameraUnavailable.localizedDescription)
    }

    private func reportPermissionProblem() {
        guard !reportedPermissionProblem else {
            return
        }

        reportedPermissionProblem = true
        ThriftyCameraEvents.cameraFailed(ThriftyCameraError.permissionDenied.localizedDescription)
    }

    /// Returns false when no camera exists for the position (e.g. the simulator).
    private func configureSession(position: AVCaptureDevice.Position) -> Bool {
        if configuredPosition == position, currentInput != nil {
            return true
        }

        guard let device = AVCaptureDevice.default(.builtInWideAngleCamera, for: .video, position: position)
            ?? AVCaptureDevice.default(for: .video) else {
            reportMissingCamera()
            return false
        }

        let input: AVCaptureDeviceInput
        do {
            input = try AVCaptureDeviceInput(device: device)
        } catch {
            ThriftyCameraEvents.cameraFailed(ThriftyCameraError.friendlyMessage(for: error, fallback: "Couldn't start the camera. Turn it off and on again."))
            return false
        }

        session.beginConfiguration()
        defer { session.commitConfiguration() }

        // The web asks getUserMedia for an ideal 1920x1080 stream and then
        // scales frames to 960px wide; do the same.
        if session.canSetSessionPreset(.hd1920x1080) {
            session.sessionPreset = .hd1920x1080
        } else {
            session.sessionPreset = .high
        }

        if let currentInput = currentInput {
            session.removeInput(currentInput)
            self.currentInput = nil
        }

        guard session.canAddInput(input) else {
            ThriftyCameraEvents.cameraFailed("Couldn't start the camera.")
            return false
        }

        session.addInput(input)
        currentInput = input
        configuredPosition = device.position

        if !outputConfigured {
            videoOutput.videoSettings = [kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA]
            videoOutput.alwaysDiscardsLateVideoFrames = true
            videoOutput.setSampleBufferDelegate(self, queue: videoQueue)

            if session.canAddOutput(videoOutput) {
                session.addOutput(videoOutput)
                outputConfigured = true
            }
        }

        if let connection = videoOutput.connection(with: .video) {
            // The app is portrait: store frames upright.
            if connection.isVideoRotationAngleSupported(90) {
                connection.videoRotationAngle = 90
            }

            // Match the (mirrored) front preview so boxes line up with what the user saw.
            if connection.isVideoMirroringSupported {
                connection.automaticallyAdjustsVideoMirroring = false
                connection.isVideoMirrored = device.position == .front
            }
        }

        return true
    }

    // MARK: - Notifications

    @objc private func appDidEnterBackground() {
        sessionQueue.async {
            self.appIsActive = false
            self.evaluateRunningState()
        }
    }

    @objc private func appWillEnterForeground() {
        sessionQueue.async {
            self.appIsActive = true
            self.evaluateRunningState()
        }
    }

    @objc private func sessionRuntimeError(_ notification: Notification) {
        let error = notification.userInfo?[AVCaptureSessionErrorKey] as? Error

        sessionQueue.async {
            guard self.sessionShouldRun else {
                print("[ThriftyCamera] Ignoring capture error while the camera is off: \(String(describing: error))")
                return
            }

            if (error as? AVError)?.code == .mediaServicesWereReset {
                // Recoverable: rebuild the input and restart.
                self.configuredPosition = nil
                self.evaluateRunningState()
                return
            }

            ThriftyCameraEvents.cameraFailed(ThriftyCameraError.friendlyMessage(for: error, fallback: "The camera stopped unexpectedly. Turn it off and on again."))
        }
    }

    // MARK: - AVCaptureVideoDataOutputSampleBufferDelegate (videoQueue)

    func captureOutput(_ output: AVCaptureOutput, didOutput sampleBuffer: CMSampleBuffer, from connection: AVCaptureConnection) {
        let now = CACurrentMediaTime()

        stateLock.lock()
        let snapshotDirectories = pendingSnapshots.map(\.directory)
        pendingSnapshots.removeAll()

        var liveDirectory: String?
        if scanning, !framesDirectory.isEmpty, !isEncodingLiveFrame, now >= nextLiveCaptureAt {
            liveDirectory = framesDirectory
            nextLiveCaptureAt = now + interval
            isEncodingLiveFrame = true
        }
        stateLock.unlock()

        guard !snapshotDirectories.isEmpty || liveDirectory != nil else {
            return
        }

        let capturedAt = Date()
        let image = CMSampleBufferGetImageBuffer(sampleBuffer)
            .flatMap { ThriftyFrameWriter.downscaledImage(from: CIImage(cvPixelBuffer: $0)) }

        guard let image = image else {
            finishLiveFrame(liveDirectory != nil)
            if !snapshotDirectories.isEmpty {
                ThriftyCameraEvents.cameraFailed(ThriftyCameraError.encodingFailed.localizedDescription)
            }
            return
        }

        encodeQueue.async {
            for directory in snapshotDirectories {
                self.writeFrame(image, to: directory, source: "snapshot", capturedAt: capturedAt)
            }

            if let liveDirectory = liveDirectory {
                self.writeFrame(image, to: liveDirectory, source: "live", capturedAt: capturedAt)
                self.finishLiveFrame(true)
            }
        }
    }

    private func writeFrame(_ image: CGImage, to directory: String, source: String, capturedAt: Date) {
        do {
            let frame = try ThriftyFrameWriter.write(cgImage: image, to: directory, capturedAt: capturedAt)
            ThriftyCameraEvents.frameCaptured(frame, source: source)
        } catch {
            print("[ThriftyCamera] Frame write failed: \(error)")
            ThriftyCameraEvents.cameraFailed(ThriftyCameraError.encodingFailed.localizedDescription)
        }
    }

    private func finishLiveFrame(_ wasLive: Bool) {
        guard wasLive else {
            return
        }

        stateLock.lock()
        isEncodingLiveFrame = false
        stateLock.unlock()
    }
}
