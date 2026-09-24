## thrifty/camera

iOS-only camera plugin for Thrifty: live scanning preview, snapshots, video frame extraction, picked-image import, the find chime and share cards.

### Live preview element

@verbatim
<code-snippet name="Camera preview" lang="blade">
<native:thrifty-camera ref="camera" :scanning="$scanning" :interval="$interval" facing="back" frames-directory="{{ $framesDirectory }}" class="w-full h-full" />
</code-snippet>
@endverbatim

- `facing` is `back`, `front` or `off` (`off` stops the camera).
- While `scanning` is true a JPEG is written to `frames-directory` 0.35s after scanning starts, then every `interval` seconds. Frames use the web app's parameters: at most 960px wide, quality 0.76 (0.82 for imported images).
- Starting a scan or a snapshot re-checks camera permission; a denied camera sends `CameraFailed` ("Camera access is off — enable it in Settings.") each time.
- A snapshot waits up to 4s for the first frame, so a Snap that also turns the camera on still captures.
- The camera only runs while the preview is on screen and the app is in the foreground.

### Facade

@verbatim
<code-snippet name="ThriftyCamera facade" lang="php">
use Thrifty\Camera\Facades\ThriftyCamera;

ThriftyCamera::snapshot($directory);
$runId = ThriftyCamera::extractVideoFrames($videoPath, $intervalSeconds, $directory);
ThriftyCamera::cancelVideoExtraction($runId);
ThriftyCamera::importImage($imagePath, $directory);
ThriftyCamera::shutter(); // snapshot click + light haptic
ThriftyCamera::chime(); // find chime + success haptic
$timezone = ThriftyCamera::deviceTimezone(); // e.g. "Europe/London", null outside the app
ThriftyCamera::shareFindCard([
    'title' => '…', 'subtitle' => '…', 'imagePath' => '/abs/frame.jpg',
    'boxes' => [['xMin' => 0, 'yMin' => 0, 'xMax' => 500, 'yMax' => 500, 'selected' => true]],
    'rows' => [['label' => 'Resale', 'value' => '$30–$45']], 'summary' => '…',
]);
</code-snippet>
@endverbatim

### Events

Handlers receive the payload by parameter name, so name parameters after the event properties.

@verbatim
<code-snippet name="Listening for frames" lang="php">
use Native\Mobile\Attributes\On;
use Thrifty\Camera\Events\FrameCaptured;

#[On(FrameCaptured::class)]
public function frameCaptured(string $path, string $source, int $width, int $height, string $capturedAt, ?float $videoSeconds = null, ?string $runId = null): void
{
    //
}
</code-snippet>
@endverbatim

- `FrameCaptured` — `source` is `live`, `snapshot`, `video` or `image`; `videoSeconds` and `runId` are only sent for video frames.
- Video plays through in real time: a frame at 0.35s, then every `interval` seconds of wall-clock time, until the video ends. Ignore frames whose `runId` isn't the current run.
- `importImage()` accepts anything iOS decodes (HEIC/HEIF, PNG, JPEG, WebP), applies EXIF orientation and writes a JPEG at most 960px wide (quality 0.82, like the web's uploads), so on-device PHP never has to read HEIC. Use it for gallery picks before analysis.
- `VideoFramesExtracted(int $count, ?string $runId = null)` — fires exactly once per extraction on every exit path: the end of the video, a failure (after `CameraFailed`) or `cancelVideoExtraction()`.
- `CameraFailed(string $message)` — user-presentable message (permission denied, no camera, unreadable video, etc.).
