## thrifty/camera

iOS-only camera plugin for Thrifty: live scanning preview, snapshots, video frame extraction, the find chime and share cards.

### Live preview element

@verbatim
<code-snippet name="Camera preview" lang="blade">
<native:thrifty-camera ref="camera" :scanning="$scanning" :interval="$interval" facing="back" frames-directory="{{ $framesDirectory }}" class="w-full h-full" />
</code-snippet>
@endverbatim

- `facing` is `back`, `front` or `off` (`off` stops the camera).
- While `scanning` is true a JPEG (longest side 1280px, quality 0.7) is written to `frames-directory` every `interval` seconds.
- The camera only runs while the preview is on screen and the app is in the foreground.

### Facade

@verbatim
<code-snippet name="ThriftyCamera facade" lang="php">
use Thrifty\Camera\Facades\ThriftyCamera;

ThriftyCamera::snapshot($directory);
ThriftyCamera::extractVideoFrames($videoPath, $intervalSeconds, $directory);
ThriftyCamera::chime();
ThriftyCamera::shareFindCard(['title' => '…', 'subtitle' => '…', 'imagePath' => '/abs/frame.jpg', 'box' => null, 'rows' => [], 'summary' => '…']);
</code-snippet>
@endverbatim

### Events

Handlers receive the payload by parameter name, so name parameters after the event properties.

@verbatim
<code-snippet name="Listening for frames" lang="php">
use Native\Mobile\Attributes\On;
use Thrifty\Camera\Events\FrameCaptured;

#[On(FrameCaptured::class)]
public function frameCaptured(string $path, string $source, int $width, int $height, string $capturedAt, ?float $videoSeconds = null): void
{
    //
}
</code-snippet>
@endverbatim

- `FrameCaptured` — `source` is `live`, `snapshot` or `video`; `videoSeconds` is only sent for video frames.
- `VideoFramesExtracted(int $count)` — fires after the last video frame (also after a failed extraction, with the count so far).
- `CameraFailed(string $message)` — user-presentable message (permission denied, no camera, unreadable video, etc.).
