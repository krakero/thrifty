import AVFoundation
import SwiftUI
import UIKit

/// `<native:thrifty-camera>` — full-bleed, aspect-fill live camera preview.
///
/// Props (see Thrifty\Camera\Elements\ThriftyCameraView):
/// - `scanning` (bool): emit a FrameCaptured event every `interval` seconds
/// - `interval` (int seconds, >= 1)
/// - `facing` ("back" | "front" | "off"): "off" stops the camera
/// - `frames_directory` (absolute path): where live frames are written
struct ThriftyCameraRenderer: View {
    let node: NativeUINode

    var body: some View {
        let props = node.props

        ThriftyCameraPreview(
            scanning: props.getBool("scanning", default: false),
            interval: max(1, props.getInt("interval", default: 3)),
            facing: props.getString("facing", default: "back"),
            framesDirectory: props.getString("frames_directory", default: "")
        )
        .background(Color.black)
        .clipped()
        .accessibilityElement()
        .accessibilityLabel(Text("Camera preview"))
    }
}

private struct ThriftyCameraPreview: UIViewRepresentable {
    let scanning: Bool
    let interval: Int
    let facing: String
    let framesDirectory: String

    func makeUIView(context: Context) -> ThriftyCameraPreviewView {
        let view = ThriftyCameraPreviewView()
        apply(to: view)
        return view
    }

    func updateUIView(_ view: ThriftyCameraPreviewView, context: Context) {
        apply(to: view)
    }

    static func dismantleUIView(_ view: ThriftyCameraPreviewView, coordinator: ()) {
        view.detachFromController()
        view.previewLayer.session = nil
    }

    private func apply(to view: ThriftyCameraPreviewView) {
        view.setCameraOn(facing != "off")

        ThriftyCameraController.shared.update(
            scanning: scanning,
            interval: interval,
            facing: facing,
            framesDirectory: framesDirectory
        )
    }
}

/// Hosts the preview layer and tells the controller when it is on screen,
/// so the session only runs while a preview is visible.
final class ThriftyCameraPreviewView: UIView {
    override class var layerClass: AnyClass {
        AVCaptureVideoPreviewLayer.self
    }

    var previewLayer: AVCaptureVideoPreviewLayer {
        // swiftlint:disable:next force_cast
        layer as! AVCaptureVideoPreviewLayer
    }

    private var isAttached = false

    override init(frame: CGRect) {
        super.init(frame: frame)
        setUp()
    }

    required init?(coder: NSCoder) {
        super.init(coder: coder)
        setUp()
    }

    private func setUp() {
        backgroundColor = .black
        previewLayer.videoGravity = .resizeAspectFill

        // The preview connection only exists once the session has an input,
        // so re-apply the rotation whenever the session (re)starts.
        NotificationCenter.default.addObserver(
            self,
            selector: #selector(sessionDidStartRunning),
            name: AVCaptureSession.didStartRunningNotification,
            object: ThriftyCameraController.shared.session
        )
    }

    @objc private func sessionDidStartRunning() {
        DispatchQueue.main.async {
            self.attachSessionIfNeeded()
            self.setNeedsLayout()
        }
    }

    private var isCameraOn = false

    /// Called by the renderer on every prop update (main thread).
    func setCameraOn(_ on: Bool) {
        isCameraOn = on
        previewLayer.isHidden = !on

        if on {
            attachSessionIfNeeded()
        } else {
            previewLayer.session = nil
        }
    }

    /// Attaching a preview layer makes AVFoundation build the capture graph,
    /// so it only happens once the controller has actually started the
    /// session (never while the camera is off or on the simulator).
    private func attachSessionIfNeeded() {
        let session = ThriftyCameraController.shared.session

        guard isCameraOn, previewLayer.session == nil, session.isRunning else {
            return
        }

        previewLayer.session = session
    }

    override func didMoveToWindow() {
        super.didMoveToWindow()

        if window != nil {
            attachToController()
        } else {
            detachFromController()
        }
    }

    override func layoutSubviews() {
        super.layoutSubviews()

        // The app is portrait-only; keep the preview upright.
        if let connection = previewLayer.connection, connection.isVideoRotationAngleSupported(90) {
            connection.videoRotationAngle = 90
        }
    }

    func attachToController() {
        guard !isAttached else {
            return
        }

        isAttached = true
        ThriftyCameraController.shared.previewDidAppear()
    }

    func detachFromController() {
        guard isAttached else {
            return
        }

        isAttached = false
        ThriftyCameraController.shared.previewDidDisappear()
    }
}
