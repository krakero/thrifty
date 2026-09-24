<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\CallbackRegistry;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Native\Mobile\Testing\Native;
use Thrifty\Camera\Elements\ThriftyCameraView;
use Thrifty\Camera\Events\CameraFailed;
use Thrifty\Camera\Events\CameraStarted;
use Thrifty\Camera\Events\FrameCaptured;
use Thrifty\Camera\Events\VideoFramesExtracted;
use Thrifty\Camera\Facades\ThriftyCamera;
use Thrifty\Camera\VideoRunJournal;

class ThriftyCameraFixtureScreen extends NativeComponent
{
    public bool $scanning = false;

    public int $interval = 5;

    public string $facing = 'back';

    public string $framesDirectory = '/data/storage/app/private/frames';

    /** @var list<array<string, mixed>> */
    public array $frames = [];

    public ?string $failure = null;

    public ?string $videoRunId = null;

    /** @var list<string> */
    public array $consumed = [];

    /**
     * Scan-style consumption: ignore the direct payload and drain the journal.
     */
    public function consumeVideoEvents(): void
    {
        if ($this->videoRunId === null) {
            return;
        }

        foreach (ThriftyCamera::takeVideoEvents($this->videoRunId) as $event) {
            $this->consumed[] = match (true) {
                $event instanceof FrameCaptured => 'frame:'.$event->path,
                $event instanceof CameraFailed => 'failed:'.$event->message,
                $event instanceof VideoFramesExtracted => 'done:'.$event->count,
            };
        }
    }

    /** @var list<array{count: int, runId: string|null}> */
    public array $extractions = [];

    #[On(FrameCaptured::class)]
    public function frameCaptured(string $path, string $source, int $width, int $height, string $capturedAt, ?float $videoSeconds = null, ?string $runId = null): void
    {
        $this->frames[] = compact('path', 'source', 'width', 'height', 'capturedAt', 'videoSeconds', 'runId');
    }

    #[On(VideoFramesExtracted::class)]
    public function videoFramesExtracted(int $count, ?string $runId = null): void
    {
        $this->extractions[] = compact('count', 'runId');
    }

    #[On(CameraFailed::class)]
    public function cameraFailed(string $message): void
    {
        $this->failure = $message;
    }

    public ?string $startedFacing = null;

    #[On(CameraStarted::class)]
    public function cameraStarted(string $facing): void
    {
        $this->startedFacing = $facing;
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

class ThriftyCameraOtherFixtureScreen extends NativeComponent
{
    public function render(): Illuminate\View\View
    {
        return view('thrifty-camera-fixture', [
            'scanning' => false, 'interval' => 2, 'facing' => 'off', 'framesDirectory' => '/frames',
        ]);
    }
}

class ThriftyCameraScanStyleFixtureScreen extends NativeComponent
{
    public ?string $videoRunId = 'run-current';

    /** @var list<string> */
    public array $handled = [];

    #[On(FrameCaptured::class)]
    #[On(VideoFramesExtracted::class)]
    #[On(CameraFailed::class)]
    public function videoEvent(?string $runId = null): void
    {
        if ($runId !== null && $runId === $this->videoRunId) {
            $this->drainVideoRun();
        }
    }

    public function onResume(): void
    {
        $this->drainVideoRun();
    }

    private function drainVideoRun(): void
    {
        foreach (ThriftyCamera::takeVideoEvents($this->videoRunId) as $event) {
            $this->handled[] = class_basename($event).':'.match (true) {
                $event instanceof FrameCaptured => basename($event->path),
                $event instanceof CameraFailed => $event->message,
                $event instanceof VideoFramesExtracted => $event->count,
            };
        }
    }

    public function render(): Illuminate\View\View
    {
        return view('thrifty-camera-fixture', [
            'scanning' => false, 'interval' => 2, 'facing' => 'off', 'framesDirectory' => '/frames',
        ]);
    }
}

beforeEach(function () {
    View::addLocation(__DIR__.'/views');

    $this->journalPath = sys_get_temp_dir().'/thrifty-camera-test-'.uniqid().'/video-runs.json';
    app()->instance(VideoRunJournal::class, new VideoRunJournal($this->journalPath));
});

afterEach(function () {
    File::deleteDirectory(dirname($this->journalPath));
});

it('calls the snapshot bridge method with the directory', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::snapshot('/tmp/frames');

    $bridge->assertCalled('ThriftyCamera.Snapshot', fn (array $params) => $params === ['directory' => '/tmp/frames']);
});

it('starts a video extraction with a fresh run id and returns it', function () {
    $bridge = Native::fakeBridge();

    $runId = ThriftyCamera::extractVideoFrames('/tmp/clip.mov', 3, '/tmp/frames');
    $secondRunId = ThriftyCamera::extractVideoFrames('/tmp/clip.mov', 0, '/tmp/frames');

    expect($runId)->toBeString()->not->toBe('')
        ->and($secondRunId)->not->toBe($runId);

    $bridge->assertCalled('ThriftyCamera.ExtractVideoFrames', fn (array $params) => $params === [
        'videoPath' => '/tmp/clip.mov',
        'intervalSeconds' => 3,
        'directory' => '/tmp/frames',
        'runId' => $runId,
    ]);
    $bridge->assertCalled('ThriftyCamera.ExtractVideoFrames', fn (array $params) => $params['runId'] === $secondRunId
        && $params['intervalSeconds'] === 1);
});

it('cancels a video extraction by run id', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::cancelVideoExtraction('run-1');

    $bridge->assertCalled('ThriftyCamera.CancelVideoExtraction', fn (array $params) => $params === ['runId' => 'run-1']);
});

it('calls the shutter bridge method', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::shutter();

    $bridge->assertCalledTimes('ThriftyCamera.Shutter', 1);
});

it('reads the device timezone', function () {
    Native::fakeBridge()->respondTo('ThriftyCamera.DeviceTimezone', ['timezone' => 'America/Toronto']);

    expect(ThriftyCamera::deviceTimezone())->toBe('America/Toronto');
});

it('returns no timezone when the device gives none or an invalid one', function (array|string|null $response) {
    Native::fakeBridge()->respondTo('ThriftyCamera.DeviceTimezone', $response);

    expect(ThriftyCamera::deviceTimezone())->toBeNull();
})->with([
    'no response' => [null],
    'empty object' => [[]],
    'unknown zone' => [['timezone' => 'Mars/Olympus']],
    'not json' => ['nope'],
]);

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
            ['path' => '/frames/picked.jpg', 'source' => 'image', 'width' => 960, 'height' => 1280, 'capturedAt' => '2026-09-23T10:00:00.000Z', 'videoSeconds' => null, 'runId' => null],
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

    $bridge->assertCalled('ThriftyCamera.ShareFindCard', fn (array $params) => $params['box'] === null
        && $params['boxes'] === []
        && $params['rows'] === []);
});

it('sends every box in the frame with its selected flag', function () {
    $bridge = Native::fakeBridge();

    ThriftyCamera::shareFindCard([
        'title' => 'Lamp', 'subtitle' => '', 'imagePath' => '/tmp/frame.jpg', 'rows' => [], 'summary' => '',
        'boxes' => [
            ['xMin' => 10, 'yMin' => 20, 'xMax' => 300, 'yMax' => 400, 'selected' => true],
            ['xMin' => 500, 'yMin' => 500, 'xMax' => 900, 'yMax' => 950],
        ],
    ]);

    $bridge->assertCalled('ThriftyCamera.ShareFindCard', fn (array $params) => $params['box'] === null && $params['boxes'] === [
        ['xMin' => 10, 'yMin' => 20, 'xMax' => 300, 'yMax' => 400, 'selected' => true],
        ['xMin' => 500, 'yMin' => 500, 'xMax' => 900, 'yMax' => 950, 'selected' => false],
    ]);
});

it('builds events with the contract signatures', function () {
    $frame = new FrameCaptured('/frames/a.jpg', 'video', 1280, 720, '2026-09-23T10:00:00Z', 12.5);

    expect($frame->path)->toBe('/frames/a.jpg')
        ->and($frame->source)->toBe('video')
        ->and($frame->width)->toBe(1280)
        ->and($frame->height)->toBe(720)
        ->and($frame->capturedAt)->toBe('2026-09-23T10:00:00Z')
        ->and($frame->videoSeconds)->toBe(12.5)
        ->and($frame->runId)->toBeNull()
        ->and((new FrameCaptured('/frames/c.jpg', 'video', 1, 1, 'now', 1.0, 'run-1'))->runId)->toBe('run-1')
        ->and((new VideoFramesExtracted(0, 'run-1'))->runId)->toBe('run-1')
        ->and((new VideoFramesExtracted(2))->runId)->toBeNull()
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
            ['path' => '/frames/live.jpg', 'source' => 'live', 'width' => 720, 'height' => 1280, 'capturedAt' => '2026-09-23T10:00:00.000Z', 'videoSeconds' => null, 'runId' => null],
            ['path' => '/frames/video.jpg', 'source' => 'video', 'width' => 1280, 'height' => 720, 'capturedAt' => '2026-09-23T10:00:01.000Z', 'videoSeconds' => 4.5, 'runId' => null],
        ])
        ->assertSet('failure', 'Camera access is off.');
});

it('delivers video run ids on frames and on the end event', function () {
    Native::test(ThriftyCameraFixtureScreen::class)
        ->emitNative(FrameCaptured::class, [
            'path' => '/frames/v.jpg', 'source' => 'video', 'width' => 960, 'height' => 540, 'capturedAt' => '2026-09-23T10:00:00.000Z', 'videoSeconds' => 0.35, 'runId' => 'run-9',
        ])
        ->emitNative(VideoFramesExtracted::class, ['count' => 1, 'runId' => 'run-9'])
        ->emitNative(VideoFramesExtracted::class, ['count' => 0])
        ->assertSet('frames', [
            ['path' => '/frames/v.jpg', 'source' => 'video', 'width' => 960, 'height' => 540, 'capturedAt' => '2026-09-23T10:00:00.000Z', 'videoSeconds' => 0.35, 'runId' => 'run-9'],
        ])
        ->assertSet('extractions', [['count' => 1, 'runId' => 'run-9'], ['count' => 0, 'runId' => null]]);
});

it('keeps the manifest, the facade and the swift bridge classes in sync', function () {
    $root = dirname(__DIR__, 3).'/packages/thrifty/camera';
    $manifest = json_decode(file_get_contents($root.'/nativephp.json'), true);
    $manifestNames = collect($manifest['bridge_functions'])->pluck('name')->sort()->values()->all();

    $bridge = Native::fakeBridge();
    ThriftyCamera::snapshot('/tmp');
    ThriftyCamera::extractVideoFrames('/tmp/v.mov', 2, '/tmp');
    ThriftyCamera::cancelVideoExtraction('run');
    ThriftyCamera::videoRunStatus('run');
    ThriftyCamera::importImage('/tmp/i.heic', '/tmp');
    ThriftyCamera::shutter();
    ThriftyCamera::deviceTimezone();
    ThriftyCamera::chime();
    ThriftyCamera::shareFindCard(['title' => '', 'subtitle' => '', 'imagePath' => '', 'rows' => [], 'summary' => '']);

    $calledNames = collect($bridge->calls)->pluck('method')->unique()->sort()->values()->all();
    $publicMethods = collect((new ReflectionClass(Thrifty\Camera\ThriftyCamera::class))->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method) => $method->isConstructor())
        ->reject(fn (ReflectionMethod $method) => in_array($method->getName(), ['takeVideoEvents', 'forgetVideoRun'], true))
        ->count();

    $swift = collect(glob($root.'/resources/ios/*.swift'))->map(fn (string $file) => file_get_contents($file))->implode("\n");

    expect($calledNames)->toBe($manifestNames)
        ->and($publicMethods)->toBe(count($manifestNames));

    foreach ($manifest['bridge_functions'] as $function) {
        [$namespace, $class] = explode('.', $function['ios']);

        expect($namespace)->toBe('ThriftyCameraFunctions')
            ->and($swift)->toContain("class {$class}: BridgeFunction");
    }

    foreach ($manifest['events'] as $event) {
        expect(class_exists($event))->toBeTrue()
            ->and($swift)->toContain(str_replace('\\', '\\\\', $event));
    }
});

it('overrides the generic plugin permission strings', function () {
    expect(config('nativephp.permissions'))
        ->NSCameraUsageDescription->toContain('Thrifty')
        ->NSMicrophoneUsageDescription->toContain('Thrifty')
        ->NSPhotoLibraryUsageDescription->toContain('Thrifty')
        ->NSPhotoLibraryAddUsageDescription->toContain('Thrifty');
});

it('broadcasts plugin events globally', function (string $class) {
    expect(is_subclass_of($class, BroadcastsGlobally::class))->toBeTrue();
})->with([FrameCaptured::class, VideoFramesExtracted::class, CameraFailed::class, CameraStarted::class]);

it('carries a run id on video failures', function () {
    expect((new CameraFailed('Couldn\'t read that video.', 'run-1'))->runId)->toBe('run-1')
        ->and((new CameraFailed('Camera access is off.'))->runId)->toBeNull();
});

it('journals video run events delivered while another screen is active', function () {
    Native::test(ThriftyCameraOtherFixtureScreen::class)
        ->emitNative(FrameCaptured::class, [
            'path' => '/frames/a.jpg', 'source' => 'video', 'width' => 960, 'height' => 540, 'capturedAt' => 'now', 'videoSeconds' => 0.35, 'runId' => 'run-1',
        ])
        ->emitNative(CameraFailed::class, ['message' => 'Skipped', 'runId' => 'run-1'])
        ->emitNative(FrameCaptured::class, [
            'path' => '/frames/live.jpg', 'source' => 'live', 'width' => 960, 'height' => 1707, 'capturedAt' => 'now',
        ])
        ->emitNative(CameraFailed::class, ['message' => 'Camera access is off.'])
        ->emitNative(VideoFramesExtracted::class, ['count' => 1, 'runId' => 'run-1']);

    $events = ThriftyCamera::takeVideoEvents('run-1');

    expect($events)->toHaveCount(3)
        ->and($events[0])->toBeInstanceOf(FrameCaptured::class)
        ->and($events[0]->path)->toBe('/frames/a.jpg')
        ->and($events[0]->videoSeconds)->toBe(0.35)
        ->and($events[0]->runId)->toBe('run-1')
        ->and($events[1])->toBeInstanceOf(CameraFailed::class)
        ->and($events[1]->message)->toBe('Skipped')
        ->and($events[2])->toBeInstanceOf(VideoFramesExtracted::class)
        ->and($events[2]->count)->toBe(1)
        ->and(ThriftyCamera::takeVideoEvents('run-1'))->toBe([]);
});

it('journals an event before the active screen handles it', function () {
    Native::test(ThriftyCameraFixtureScreen::class)
        ->set('videoRunId', 'run-2')
        ->emitNative(FrameCaptured::class, [
            'path' => '/frames/b.jpg', 'source' => 'video', 'width' => 960, 'height' => 540, 'capturedAt' => 'now', 'videoSeconds' => 2.35, 'runId' => 'run-2',
        ])
        ->call('consumeVideoEvents')
        ->assertSet('consumed', ['frame:/frames/b.jpg'])
        ->call('consumeVideoEvents')
        ->assertSet('consumed', ['frame:/frames/b.jpg']);
});

it('keeps an unfinished run and drops a finished one once taken', function () {
    $journal = app(VideoRunJournal::class);
    $journal->record(new FrameCaptured('/frames/c.jpg', 'video', 1, 1, 'now', 0.35, 'run-3'));

    ThriftyCamera::takeVideoEvents('run-3');

    expect($journal->status('run-3'))->toBe(['known' => true, 'finished' => false, 'framesEmitted' => 1, 'pending' => 0]);

    $journal->record(new VideoFramesExtracted(1, 'run-3'));
    ThriftyCamera::takeVideoEvents('run-3');

    expect($journal->status('run-3')['known'])->toBeFalse();
});

it('forgets a run and deletes its undelivered frames', function () {
    $directory = dirname($this->journalPath).'/frames';
    File::ensureDirectoryExists($directory);
    File::put($directory.'/stale.jpg', 'jpeg');
    File::put($directory.'/other.jpg', 'jpeg');

    $journal = app(VideoRunJournal::class);
    $journal->record(new FrameCaptured($directory.'/stale.jpg', 'video', 1, 1, 'now', 0.35, 'run-4'));
    $journal->record(new FrameCaptured($directory.'/other.jpg', 'video', 1, 1, 'now', 0.35, 'run-5'));

    ThriftyCamera::forgetVideoRun('run-4');

    expect(File::exists($directory.'/stale.jpg'))->toBeFalse()
        ->and(File::exists($directory.'/other.jpg'))->toBeTrue()
        ->and($journal->status('run-4')['known'])->toBeFalse()
        ->and($journal->status('run-5')['pending'])->toBe(1);
});

it('reads the video run status from the device', function () {
    $bridge = Native::fakeBridge()->respondTo('ThriftyCamera.VideoRunStatus', ['active' => true, 'framesEmitted' => 3]);

    expect(ThriftyCamera::videoRunStatus('run-6'))->toBe(['active' => true, 'framesEmitted' => 3]);

    $bridge->assertCalled('ThriftyCamera.VideoRunStatus', fn (array $params) => $params === ['runId' => 'run-6']);
});

it('falls back to the journal for the video run status', function () {
    Native::fakeBridge();
    $journal = app(VideoRunJournal::class);

    expect(ThriftyCamera::videoRunStatus('run-7'))->toBe(['active' => false, 'framesEmitted' => 0]);

    $journal->record(new FrameCaptured('/frames/d.jpg', 'video', 1, 1, 'now', 0.35, 'run-7'));
    expect(ThriftyCamera::videoRunStatus('run-7'))->toBe(['active' => true, 'framesEmitted' => 1]);

    $journal->record(new VideoFramesExtracted(1, 'run-7'));
    expect(ThriftyCamera::videoRunStatus('run-7'))->toBe(['active' => false, 'framesEmitted' => 1]);
});

it('lets a scan screen catch up on a video run delivered while it was covered', function () {
    $frame = fn (string $name, string $runId) => [
        'path' => "/frames/{$name}.jpg", 'source' => 'video', 'width' => 960, 'height' => 540, 'capturedAt' => 'now', 'videoSeconds' => 1.0, 'runId' => $runId,
    ];

    // Delivered while Settings is showing.
    Native::test(ThriftyCameraOtherFixtureScreen::class)
        ->emitNative(FrameCaptured::class, $frame('covered-1', 'run-current'))
        ->emitNative(FrameCaptured::class, $frame('stale', 'run-old'))
        ->emitNative(CameraFailed::class, ['message' => 'Skipped a frame', 'runId' => 'run-current']);

    Native::test(ThriftyCameraScanStyleFixtureScreen::class)
        ->call('onResume')
        ->assertSet('handled', ['FrameCaptured:covered-1.jpg', 'CameraFailed:Skipped a frame'])
        ->emitNative(FrameCaptured::class, $frame('live-1', 'run-current'))
        ->emitNative(FrameCaptured::class, $frame('stale-2', 'run-old'))
        ->emitNative(VideoFramesExtracted::class, ['count' => 2, 'runId' => 'run-current'])
        ->assertSet('handled', [
            'FrameCaptured:covered-1.jpg',
            'CameraFailed:Skipped a frame',
            'FrameCaptured:live-1.jpg',
            'VideoFramesExtracted:2',
        ]);

    expect(app(VideoRunJournal::class)->status('run-current')['known'])->toBeFalse()
        ->and(app(VideoRunJournal::class)->status('run-old')['pending'])->toBe(2);
});

it('delivers camera started to on handlers', function () {
    expect((new CameraStarted('front'))->facing)->toBe('front');

    Native::test(ThriftyCameraFixtureScreen::class)
        ->emitNative(CameraStarted::class, ['facing' => 'back'])
        ->assertSet('startedFacing', 'back');
});

it('does not journal camera started', function () {
    Native::test(ThriftyCameraOtherFixtureScreen::class)
        ->emitNative(CameraStarted::class, ['facing' => 'back']);

    expect(File::exists($this->journalPath))->toBeFalse();
});
