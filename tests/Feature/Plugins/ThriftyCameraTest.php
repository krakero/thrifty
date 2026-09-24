<?php

use Illuminate\Support\Facades\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Testing\Native;
use Thrifty\Camera\Elements\ThriftyCameraView;
use Thrifty\Camera\Events\CameraFailed;
use Thrifty\Camera\Events\FrameCaptured;
use Thrifty\Camera\Events\VideoFramesExtracted;
use Thrifty\Camera\Facades\ThriftyCamera;

class ThriftyCameraFixtureScreen extends NativeComponent
{
    public bool $scanning = false;

    public int $interval = 5;

    public string $facing = 'back';

    public string $framesDirectory = '/data/storage/app/private/frames';

    /** @var list<array<string, mixed>> */
    public array $frames = [];

    public ?string $failure = null;

    #[On(FrameCaptured::class)]
    public function frameCaptured(string $path, string $source, int $width, int $height, string $capturedAt, ?float $videoSeconds = null): void
    {
        $this->frames[] = compact('path', 'source', 'width', 'height', 'capturedAt', 'videoSeconds');
    }

    #[On(CameraFailed::class)]
    public function cameraFailed(string $message): void
    {
        $this->failure = $message;
    }

    public function toggle(): void
    {
        $this->scanning = ! $this->scanning;
    }

    public function render(): Illuminate\View\View
    {
        return view('thrifty-camera-fixture');
    }
}

beforeEach(function () {
    View::addLocation(__DIR__.'/views');
});

it('calls the snapshot bridge method with the directory', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::snapshot('/tmp/frames');

    $bridge->assertCalled('ThriftyCamera.Snapshot', fn (array $params) => $params === ['directory' => '/tmp/frames']);
});

it('calls the video extraction bridge method', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::extractVideoFrames('/tmp/clip.mov', 3, '/tmp/frames');

    $bridge->assertCalled('ThriftyCamera.ExtractVideoFrames', fn (array $params) => $params === [
        'videoPath' => '/tmp/clip.mov',
        'intervalSeconds' => 3,
        'directory' => '/tmp/frames',
    ]);
});

it('never sends an interval below one second', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::extractVideoFrames('/tmp/clip.mov', 0, '/tmp/frames');

    $bridge->assertCalled('ThriftyCamera.ExtractVideoFrames', fn (array $params) => $params['intervalSeconds'] === 1);
});

it('calls the image import bridge method', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::importImage('/tmp/IMG_0001.HEIC', '/tmp/frames');

    $bridge->assertCalled('ThriftyCamera.ImportImage', fn (array $params) => $params === [
        'imagePath' => '/tmp/IMG_0001.HEIC',
        'directory' => '/tmp/frames',
    ]);
});

it('delivers imported image frames to on handlers', function () {
    Native::test(ThriftyCameraFixtureScreen::class)
        ->emitNative(FrameCaptured::class, [
            'path' => '/frames/picked.jpg', 'source' => 'image', 'width' => 960, 'height' => 1280, 'capturedAt' => '2026-09-23T10:00:00.000Z',
        ])
        ->assertSet('frames', [
            ['path' => '/frames/picked.jpg', 'source' => 'image', 'width' => 960, 'height' => 1280, 'capturedAt' => '2026-09-23T10:00:00.000Z', 'videoSeconds' => null],
        ]);
});

it('calls the chime bridge method', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::chime();

    $bridge->assertCalledTimes('ThriftyCamera.Chime', 1);
});

it('sends a normalized share card', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::shareFindCard([
        'title' => 'Pyrex bowl',
        'subtitle' => 'Kitchenware',
        'imagePath' => '/tmp/frame.jpg',
        'box' => ['xMin' => 100, 'yMin' => 200, 'xMax' => 600, 'yMax' => 900],
        'rows' => [['label' => 'Asking', 'value' => '$4.00'], ['label' => 'Resale', 'value' => '$30–$45']],
        'summary' => 'Worth grabbing.',
    ]);

    $bridge->assertCalled('ThriftyCamera.ShareFindCard', fn (array $params) => $params['title'] === 'Pyrex bowl'
        && $params['box'] === ['xMin' => 100, 'yMin' => 200, 'xMax' => 600, 'yMax' => 900]
        && $params['rows'][1] === ['label' => 'Resale', 'value' => '$30–$45']
        && $params['summary'] === 'Worth grabbing.');
});

it('sends a null box when the card has none', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::shareFindCard([
        'title' => 'Lamp', 'subtitle' => '', 'imagePath' => '/tmp/frame.jpg', 'box' => null, 'rows' => [], 'summary' => '',
    ]);

    $bridge->assertCalled('ThriftyCamera.ShareFindCard', fn (array $params) => $params['box'] === null && $params['rows'] === []);
});

it('builds events with the contract signatures', function () {
    $frame = new FrameCaptured('/frames/a.jpg', 'video', 1280, 720, '2026-09-23T10:00:00Z', 12.5);

    expect($frame->path)->toBe('/frames/a.jpg')
        ->and($frame->source)->toBe('video')
        ->and($frame->width)->toBe(1280)
        ->and($frame->height)->toBe(720)
        ->and($frame->capturedAt)->toBe('2026-09-23T10:00:00Z')
        ->and($frame->videoSeconds)->toBe(12.5)
        ->and((new FrameCaptured('/frames/b.jpg', 'live', 1, 1, 'now'))->videoSeconds)->toBeNull()
        ->and((new VideoFramesExtracted(4))->count)->toBe(4)
        ->and((new CameraFailed('Camera access denied'))->message)->toBe('Camera access denied');
});

it('serializes the fluent element', function () {
    $props = ThriftyCameraView::make()->scanning()->interval(0)->facing('sideways')->framesDirectory('/frames')
        ->getResolvedProps(new CallbackRegistry);

    expect($props)->toMatchArray([
        'scanning' => true,
        'interval' => 1,
        'facing' => 'back',
        'frames_directory' => '/frames',
    ]);
});

it('renders the camera element from blade and follows state', function () {
    Native::test(ThriftyCameraFixtureScreen::class)
        ->assertElement('thrifty_camera', fn (array $node) => $node['props']['scanning'] === false
            && $node['props']['interval'] === 5
            && $node['props']['facing'] === 'back'
            && $node['props']['frames_directory'] === '/data/storage/app/private/frames')
        ->call('toggle')
        ->assertElement('thrifty_camera', fn (array $node) => $node['props']['scanning'] === true);
});

it('passes facing off through to the renderer', function () {
    Native::test(ThriftyCameraFixtureScreen::class)
        ->set('facing', 'off')
        ->assertElement('thrifty_camera', fn (array $node) => $node['props']['facing'] === 'off');
});

it('delivers native frame payloads to on handlers by parameter name', function () {
    Native::test(ThriftyCameraFixtureScreen::class)
        ->emitNative(FrameCaptured::class, [
            'path' => '/frames/live.jpg', 'source' => 'live', 'width' => 720, 'height' => 1280, 'capturedAt' => '2026-09-23T10:00:00.000Z',
        ])
        ->emitNative(FrameCaptured::class, [
            'path' => '/frames/video.jpg', 'source' => 'video', 'width' => 1280, 'height' => 720, 'capturedAt' => '2026-09-23T10:00:01.000Z', 'videoSeconds' => 4.5,
        ])
        ->emitNative(CameraFailed::class, ['message' => 'Camera access is off.'])
        ->assertSet('frames', [
            ['path' => '/frames/live.jpg', 'source' => 'live', 'width' => 720, 'height' => 1280, 'capturedAt' => '2026-09-23T10:00:00.000Z', 'videoSeconds' => null],
            ['path' => '/frames/video.jpg', 'source' => 'video', 'width' => 1280, 'height' => 720, 'capturedAt' => '2026-09-23T10:00:01.000Z', 'videoSeconds' => 4.5],
        ])
        ->assertSet('failure', 'Camera access is off.');
});
