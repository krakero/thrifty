<?php

use App\Agent\Exceptions\AnalysisFailed;
use App\Agent\Exceptions\MissingApiKey;
use App\Agent\FrameAnalyzer;
use App\Async\AnalyzeFrame;
use App\Enums\FrameRunStatus;
use App\Models\AppStat;
use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ScanSession;
use App\NativeComponents\Scan;
use App\Scanning\FrameImporter;
use App\Scanning\LiveScanState;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\AsyncTask;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Testing\Native;
use Thrifty\Camera\Events\CameraFailed;
use Thrifty\Camera\Events\FrameCaptured;
use Thrifty\Camera\Events\VideoFramesExtracted;

beforeEach(function () {
    Storage::fake('local');
    $this->asyncFake = AsyncTask::fake();
    $this->bridge = Native::fakeBridge();
    $this->mock(FrameAnalyzer::class)->shouldReceive('analyze')->andReturn([]);
});

afterEach(function () {
    AsyncTask::clearFake();
});

function scanState(): LiveScanState
{
    return app(LiveScanState::class);
}

/**
 * Deliver a camera frame the way the plugin does: a JPEG in the frames directory plus a FrameCaptured event.
 */
function captureFrame($component, string $source = 'live', string $name = 'frame.jpg')
{
    $disk = Storage::disk('local');
    $disk->put('frames/'.$name, 'jpeg');

    return $component->emitNative(FrameCaptured::class, [
        'path' => $disk->path('frames/'.$name),
        'source' => $source,
        'width' => 1280,
        'height' => 720,
        'capturedAt' => '2026-09-23T10:00:00Z',
    ]);
}

/**
 * The shared async delivery for the most recent (or given) AnalyzeFrame dispatch.
 *
 * @param  array<string, mixed>  $overrides
 */
function finishAnalysis($component, array $itemIds = [], ?string $taskId = null, array $overrides = [])
{
    $taskId ??= array_key_last(scanState()->pending);

    return $component->emitNative(Scan::FrameAnalyzedEvent, [
        'id' => $taskId,
        'status' => 'finished',
        'result' => [
            'frameRunId' => 'run',
            'itemIds' => $itemIds,
            'newItemIds' => $itemIds,
            'stats' => ['framesProcessed' => 1, 'itemsIdentified' => count($itemIds), 'searchesPerformed' => 0, 'modelCalls' => 1],
            'run' => ['latencyMs' => 1000, 'modelCalls' => 1, 'searchesPerformed' => 0],
        ],
        ...$overrides,
    ]);
}

it('starts paused with the camera off', function () {
    Native::test(Scan::class)
        ->assertSee('Paused')
        ->assertSee('Camera off')
        ->assertSee('0/4')
        ->assertSee('Live')
        ->assertSee('Snap')
        ->assertSee('Upload');
});

it('starts a camera session and goes live', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');

    $session = ScanSession::sole();

    expect($session->source_type)->toBe('camera')
        ->and(scanState()->sessionId)->toBe($session->id)
        ->and(scanState()->scanning)->toBeTrue()
        ->and(scanState()->facing)->toBe('back');

    $component->assertSee('Stop')->assertSee('Back camera')
        ->assertElement('thrifty_camera', fn (array $node) => $node['props']['scanning'] === true
            && $node['props']['facing'] === 'back'
            && $node['props']['interval'] === 5);
});

it('dispatches an analysis for each captured frame', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');

    captureFrame($component)->assertSee('1/4');

    $this->asyncFake->assertDispatched(fn (array $dispatch) => $dispatch['work']['task'] === AnalyzeFrame::class
        && $dispatch['work']['args'][0] === scanState()->sessionId
        && $dispatch['work']['args'][1] === 'frames/frame.jpg'
        && $dispatch['work']['args'][2] === '2026-09-23T10:00:00Z');
    $this->asyncFake->assertShared(Scan::FrameAnalyzedEvent);
});

it('drops frames while every analysis slot is busy', function () {
    app(AppSettings::class)->set(AppSettings::MaxConcurrentFrames, '2');
    $component = Native::test(Scan::class)->tap('toggle-live');

    captureFrame($component, name: 'a.jpg');
    captureFrame($component, name: 'b.jpg');
    captureFrame($component, name: 'c.jpg')->assertSee('2/2');

    $this->asyncFake->assertDispatchedTimes(2);
    Storage::disk('local')->assertMissing('frames/c.jpg');

    finishAnalysis($component)->assertSee('1/2');
    captureFrame($component, name: 'd.jpg');

    $this->asyncFake->assertDispatchedTimes(3);
});

it('turns the camera on instead of snapping when it is off', function () {
    Native::test(Scan::class)
        ->tap('snapshot')
        ->assertNativeNotCalled('ThriftyCamera.Snapshot')
        ->assertSee('Back camera');

    expect(ScanSession::sole()->source_type)->toBe('camera')
        ->and(scanState()->scanning)->toBeFalse();
});

it('flashes on a snapshot frame', function () {
    $component = Native::test(Scan::class)->tap('snapshot')->tap('snapshot')
        ->assertNativeCalled('ThriftyCamera.Snapshot');

    captureFrame($component, 'snapshot')->assertElement('column', fn (array $node) => ($node['props']['ref'] ?? $node['ref'] ?? null) === 'snapshot-flash');
    $this->asyncFake->assertDispatchedTimes(1);
});

it('does not snap while at capacity', function () {
    app(AppSettings::class)->set(AppSettings::MaxConcurrentFrames, '1');
    $component = Native::test(Scan::class)->tap('toggle-live');
    captureFrame($component);

    $component->tap('snapshot')->assertNativeNotCalled('ThriftyCamera.Snapshot');

    finishAnalysis($component);
    $component->tap('snapshot')->assertNativeCalled('ThriftyCamera.Snapshot',
        fn (array $params) => $params['directory'] === Storage::disk('local')->path('frames'));
});

it('streams finds into the feed newest first and chimes', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');
    $first = Item::factory()->create(['name' => 'Pyrex bowl']);
    $second = Item::factory()->create(['name' => 'Nintendo 64']);

    captureFrame($component);
    AppStat::record(1, 2, 3, 4);
    finishAnalysis($component, [$first->id, $second->id])
        ->assertNativeCalled('ThriftyCamera.Chime')
        ->assertSee('Pyrex bowl')
        ->assertDontSee('Nintendo 64')
        ->assertSee('0/4')
        ->assertSee('Searches');

    expect(scanState()->revealQueue)->toBe([$second->id]);

    scanState()->lastRevealAt = 0;
    $component->firePolls()->assertSee('Nintendo 64');

    expect(scanState()->liveItemIds)->toBe([$second->id, $first->id]);
});

it('moves a repeat find back to the top', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');
    [$a, $b] = Item::factory()->count(2)->create();
    scanState()->liveItemIds = [$b->id, $a->id];

    captureFrame($component);
    finishAnalysis($component, [$a->id]);

    expect(scanState()->liveItemIds)->toBe([$a->id, $b->id]);
});

it('does not chime when a frame has no finds', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');

    captureFrame($component);
    finishAnalysis($component)->assertNativeNotCalled('ThriftyCamera.Chime');
});

it('shows analysis errors and clears them on the next success', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');

    captureFrame($component, name: 'a.jpg');
    $component->emitNative(Scan::FrameAnalyzedEvent, [
        'id' => array_key_last(scanState()->pending),
        'status' => 'failed',
        'exceptionClass' => AnalysisFailed::class,
        'message' => 'The model returned an invalid response.',
    ])->assertSee('The model returned an invalid response.')->assertSee('0/4');

    captureFrame($component, name: 'b.jpg');
    finishAnalysis($component)->assertDontSee('The model returned an invalid response.');
});

it('offers a path to settings when the api key is missing', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');

    captureFrame($component);
    $component->emitNative(Scan::FrameAnalyzedEvent, [
        'id' => array_key_last(scanState()->pending),
        'status' => 'failed',
        'exceptionClass' => MissingApiKey::class,
        'message' => 'Add your OpenAI API key in Settings to start scanning.',
    ])->assertSee('Add your OpenAI API key in Settings to start scanning.');

    $component->tap('error-open-settings')->assertNavigatedTo('/settings');
});

it('dismisses the error banner', function () {
    scanState()->fail('Camera access failed');

    Native::test(Scan::class)
        ->assertSee('Camera access failed')
        ->tap('dismiss-error')
        ->assertDontSee('Camera access failed');
});

it('surfaces camera failures and stops scanning', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');

    $component->emitNative(CameraFailed::class, ['message' => 'Camera access was denied.'])
        ->assertSee('Camera access was denied.')
        ->assertSee('Paused');
});

it('stops live scanning and ends the session', function () {
    $component = Native::test(Scan::class)->tap('toggle-live')->tap('toggle-live');

    expect(ScanSession::sole()->ended_at)->not->toBeNull()
        ->and(scanState()->scanning)->toBeFalse()
        ->and(scanState()->sessionId)->toBeNull();

    $component->assertSee('Paused')->assertSee('Live');

    captureFrame($component);
    $this->asyncFake->assertNotDispatched();
});

it('switches cameras into a new session and keeps scanning', function () {
    $component = Native::test(Scan::class)->tap('toggle-live')->call('useFrontCamera');

    expect(ScanSession::count())->toBe(2)
        ->and(ScanSession::query()->whereNull('ended_at')->count())->toBe(1)
        ->and(scanState()->facing)->toBe('front')
        ->and(scanState()->scanning)->toBeTrue();

    $component->assertSee('Front camera')->call('turnCameraOff')->assertSee('Camera off')->assertSee('Paused');

    expect(ScanSession::query()->whereNull('ended_at')->count())->toBe(0);
});

it('opens settings', function () {
    Native::test(Scan::class)->tap('open-settings')->assertNavigatedTo('/settings');
});

it('opens the gallery picker for uploads', function () {
    Native::test(Scan::class)
        ->tap('upload')
        ->assertNativeCalled('Camera.PickMedia', fn (array $params) => $params['mediaType'] === 'all' && $params['id'] === Scan::UploadPickerId);
});

it('analyzes an uploaded photo once', function () {
    $source = tempnam(sys_get_temp_dir(), 'upload').'.jpg';
    $image = imagecreatetruecolor(2000, 1000);
    imagejpeg($image, $source);
    $analyzedWidth = null;
    $this->mock(FrameAnalyzer::class)->shouldReceive('analyze')->once()
        ->andReturnUsing(function (string $sessionId, string $framePath) use (&$analyzedWidth) {
            $analyzedWidth = getimagesizefromstring(Storage::disk('local')->get($framePath))[0];

            return [];
        });

    $component = Native::test(Scan::class)->emitNative(MediaSelected::class, [
        'success' => true,
        'files' => [['path' => $source, 'mimeType' => 'image/jpeg', 'type' => 'image']],
        'count' => 1,
        'id' => Scan::UploadPickerId,
    ]);

    $session = ScanSession::sole();
    $framePath = scanState()->pending[array_key_last(scanState()->pending)]['framePath'];

    expect($session->source_type)->toBe('image')
        ->and($framePath)->toStartWith('frames/')
        ->and($analyzedWidth)->toBe(FrameImporter::MaxLongestSide);
    $this->asyncFake->assertDispatchedTimes(1);
    $component->assertSee('Uploaded photo');

    finishAnalysis($component);

    expect($session->fresh()->ended_at)->not->toBeNull();
});

it('rejects unreadable uploads', function () {
    Native::test(Scan::class)->emitNative(MediaSelected::class, [
        'success' => true,
        'files' => [['path' => '/nope/missing.heic', 'type' => 'image']],
        'id' => Scan::UploadPickerId,
    ])->assertSee('The selected image could not be read.');

    $this->asyncFake->assertNotDispatched();
});

it('ignores a cancelled picker', function () {
    Native::test(Scan::class)
        ->emitNative(MediaSelected::class, ['success' => false, 'cancelled' => true, 'id' => Scan::UploadPickerId])
        ->assertDontSee('could not be loaded');

    expect(ScanSession::count())->toBe(0);
});

it('extracts and analyzes video frames within the concurrency limit', function () {
    app(AppSettings::class)->set(AppSettings::MaxConcurrentFrames, '2');
    app(AppSettings::class)->set(AppSettings::ScanIntervalSeconds, '3');

    $component = Native::test(Scan::class)->emitNative(MediaSelected::class, [
        'success' => true,
        'files' => [['path' => '/tmp/Gallery/clip.mov', 'mimeType' => 'video/quicktime', 'type' => 'video']],
        'id' => Scan::UploadPickerId,
    ]);

    $component->assertNativeCalled('ThriftyCamera.ExtractVideoFrames', fn (array $params) => $params['videoPath'] === '/tmp/Gallery/clip.mov'
        && $params['intervalSeconds'] === 3
        && $params['directory'] === Storage::disk('local')->path('frames'));
    expect(ScanSession::sole()->source_type)->toBe('video');
    $component->assertSee('Uploaded video')->assertSee('Live');

    foreach (['v1.jpg', 'v2.jpg', 'v3.jpg'] as $name) {
        captureFrame($component, 'video', $name);
    }

    $this->asyncFake->assertDispatchedTimes(2);
    expect(scanState()->queue)->toHaveCount(1);

    $component->emitNative(VideoFramesExtracted::class, ['count' => 3]);
    finishAnalysis($component);
    $this->asyncFake->assertDispatchedTimes(3);

    finishAnalysis($component);
    expect(ScanSession::sole()->ended_at)->toBeNull();

    finishAnalysis($component)->assertSee('Paused');
    expect(ScanSession::sole()->ended_at)->not->toBeNull();
});

it('reconciles results that arrived while another screen was active', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');
    captureFrame($component, name: 'a.jpg');
    captureFrame($component, name: 'b.jpg');

    $run = FrameRun::factory()->create(['frame_path' => 'frames/a.jpg', 'status' => FrameRunStatus::Completed]);
    $item = Item::factory()->create(['frame_run_id' => $run->id, 'name' => 'Eames chair']);
    FrameRun::factory()->create(['frame_path' => 'frames/b.jpg', 'status' => FrameRunStatus::Failed, 'error' => 'Rate limited']);

    Native::test(Scan::class)->assertSee('Eames chair')->assertSee('0/4')->assertSee('Rate limited');

    expect(scanState()->liveItemIds)->toBe([$item->id]);
});

it('expires analyses that never report back', function () {
    scanState()->pending['lost'] = ['sessionId' => 's', 'framePath' => 'frames/lost.jpg', 'dispatchedAt' => time() - Scan::PendingExpirySeconds - 1];
    scanState()->pending['recent'] = ['sessionId' => 's', 'framePath' => 'frames/recent.jpg', 'dispatchedAt' => time()];

    Native::test(Scan::class)->assertSee('1/4');

    expect(array_keys(scanState()->pending))->toBe(['recent']);
});

it('keeps the camera error when a video cannot be read', function () {
    $component = Native::test(Scan::class)->emitNative(MediaSelected::class, [
        'success' => true,
        'files' => [['path' => '/tmp/Gallery/broken.mov', 'type' => 'video']],
        'id' => Scan::UploadPickerId,
    ]);

    $component->emitNative(CameraFailed::class, ['message' => 'This video could not be opened.'])
        ->emitNative(VideoFramesExtracted::class, ['count' => 0])
        ->assertSee('This video could not be opened.')
        ->assertSee('Paused');

    expect(ScanSession::sole()->ended_at)->not->toBeNull();
});

it('ignores results for analyses it is not waiting on', function () {
    Native::test(Scan::class)
        ->emitNative(Scan::FrameAnalyzedEvent, ['id' => 'unknown', 'status' => 'finished', 'result' => ['itemIds' => ['x']]])
        ->assertNativeNotCalled('ThriftyCamera.Chime');
});

it('is accessible', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');
    Item::factory()->create();
    scanState()->liveItemIds = [Item::first()->id];
    scanState()->fail('Something went wrong');

    $component->call('dismissError')->assertAccessible();
});

it('is the home tab', function () {
    Native::visit('/')->assertScreen(Scan::class)->assertHasTab('Scan')->assertTabActive('Scan');
});
