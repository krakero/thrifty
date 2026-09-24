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
use App\NativeComponents\Settings;
use App\Scanning\LiveScanState;
use App\Services\AppSettings;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\AsyncTask;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Testing\Native;
use Thrifty\Camera\Events\CameraFailed;
use Thrifty\Camera\Events\FrameCaptured;
use Thrifty\Camera\Events\VideoFramesExtracted;
use Thrifty\Camera\VideoRunJournal;

beforeEach(function () {
    Storage::fake('local');
    $this->asyncFake = AsyncTask::fake();
    $this->bridge = Native::fakeBridge();
    $this->mock(FrameAnalyzer::class)->shouldReceive('analyze')->andReturn([]);
    app(AppSettings::class)->set(AppSettings::OpenAiApiKey, 'sk-test');
    $this->journalPath = sys_get_temp_dir().'/thrifty-video-runs-'.uniqid().'.json';
    app()->instance(VideoRunJournal::class, new VideoRunJournal($this->journalPath));
});

afterEach(function () {
    AsyncTask::clearFake();
    @unlink($this->journalPath);
});

function scanState(): LiveScanState
{
    return app(LiveScanState::class);
}

/**
 * Deliver a frame the way the plugin does: a JPEG in the frames directory plus a FrameCaptured event.
 */
function captureFrame($component, string $source = 'live', string $name = 'frame.jpg', ?string $runId = null)
{
    $disk = Storage::disk('local');
    $disk->put('frames/'.$name, 'jpeg');

    return $component->emitNative(FrameCaptured::class, array_filter([
        'path' => $disk->path('frames/'.$name),
        'source' => $source,
        'width' => 1280,
        'height' => 720,
        'capturedAt' => '2026-09-23T10:00:00Z',
        'videoSeconds' => $source === 'video' ? 1.35 : null,
        'runId' => $runId,
    ], fn ($value) => $value !== null));
}

/**
 * The shared async delivery for the most recent (or given) AnalyzeFrame dispatch.
 *
 * @param  list<string>  $itemIds
 */
function finishAnalysis($component, array $itemIds = [], ?string $taskId = null)
{
    $taskId ??= array_key_last(scanState()->pending);

    return $component->emitNative(Scan::FrameAnalyzedEvent, [
        'id' => $taskId,
        'status' => 'finished',
        'result' => [
            'frameRunId' => scanState()->pending[$taskId]['frameRunId'] ?? 'run',
            'itemIds' => $itemIds,
            'newItemIds' => $itemIds,
            'stats' => ['framesProcessed' => 1, 'itemsIdentified' => count($itemIds), 'searchesPerformed' => 0, 'modelCalls' => 1],
            'run' => ['latencyMs' => 1000, 'modelCalls' => 1, 'searchesPerformed' => 0],
        ],
    ]);
}

function failAnalysis($component, string $exceptionClass, string $message)
{
    return $component->emitNative(Scan::FrameAnalyzedEvent, [
        'id' => array_key_last(scanState()->pending),
        'status' => 'failed',
        'exceptionClass' => $exceptionClass,
        'message' => $message,
    ]);
}

function pickMedia($component, string $path, string $type)
{
    return $component->emitNative(MediaSelected::class, [
        'success' => true,
        'files' => [['path' => $path, 'type' => $type, 'mimeType' => $type === 'video' ? 'video/quicktime' : 'image/heic']],
        'count' => 1,
        'id' => Scan::UploadPickerId,
    ]);
}

/**
 * Deliver the imported JPEG for a photo pick into the directory Scan handed to ThriftyCamera.ImportImage.
 */
function importedFrame($component, int $pick = -1, string $name = 'photo.jpg')
{
    $calls = array_values($component->bridge()->callsTo('ThriftyCamera.ImportImage'));
    $directory = $calls[$pick < 0 ? count($calls) + $pick : $pick]['params']['directory'];
    file_put_contents($directory.'/'.$name, 'jpeg');

    return $component->emitNative(FrameCaptured::class, [
        'path' => $directory.'/'.$name,
        'source' => 'image',
        'width' => 1280,
        'height' => 960,
        'capturedAt' => '2026-09-23T10:00:00Z',
    ]);
}

function pickedTempFile(string $extension): string
{
    $path = tempnam(sys_get_temp_dir(), 'picked').'.'.$extension;
    file_put_contents($path, 'original');

    return $path;
}

it('is the home tab', function () {
    Native::visit('/')->assertScreen(Scan::class)->assertHasTab('Scan')->assertTabActive('Scan');
});

it('starts paused with the camera off', function () {
    Native::test(Scan::class)
        ->assertSee('Paused')
        ->assertSee('Camera off')
        ->assertSee('0/4')
        ->assertSee('Snap')
        ->assertSee('Upload')
        ->assertMissingElement('row', fn (array $node) => ($node['ref'] ?? null) === 'source-caption');
});

it('starts a camera session and goes live at the default interval', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');

    $session = ScanSession::sole();

    expect($session->source_type)->toBe('camera')
        ->and(scanState()->sessionId)->toBe($session->id)
        ->and(scanState()->scanning)->toBeTrue()
        ->and(scanState()->facing)->toBe('back');

    $component->assertSee('Stop')->assertSee('Back camera')
        ->assertElement('thrifty_camera', fn (array $node) => $node['props']['scanning'] === true
            && $node['props']['facing'] === 'back'
            && $node['props']['interval'] === 2);
});

it('dispatches an analysis with a pre-generated frame run id and the untrimmed criteria', function () {
    app(AppSettings::class)->set(AppSettings::FindCriteria, ' Cast iron ');
    $component = Native::test(Scan::class)->tap('toggle-live');

    captureFrame($component)->assertSee('1/4');

    $entry = scanState()->pending[array_key_last(scanState()->pending)];

    $this->asyncFake->assertDispatched(fn (array $dispatch) => $dispatch['work']['task'] === AnalyzeFrame::class
        && $dispatch['work']['args'] === [scanState()->sessionId, 'frames/frame.jpg', '2026-09-23T10:00:00Z', $entry['frameRunId'], ' Cast iron ']);
    $this->asyncFake->assertShared(Scan::FrameAnalyzedEvent);
    expect(strlen($entry['frameRunId']))->toBe(26);
});

it('plays the shutter and flash for camera frames', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');

    captureFrame($component)
        ->assertNativeCalled('ThriftyCamera.Shutter')
        ->assertElement('column', fn (array $node) => ($node['ref'] ?? null) === 'snapshot-flash');
});

it('asks for an api key instead of dispatching without one', function () {
    app(AppSettings::class)->set(AppSettings::OpenAiApiKey, null);
    $component = Native::test(Scan::class)->tap('toggle-live');

    captureFrame($component)
        ->assertSee('Add your OpenAI API key in Settings to start scanning.')
        ->assertSee('0/4');

    $this->asyncFake->assertNotDispatched();
    Storage::disk('local')->assertMissing('frames/frame.jpg');

    $component->tap('error-open-settings')->assertNavigatedTo('/settings');
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

it('does not snap while at capacity', function () {
    app(AppSettings::class)->set(AppSettings::MaxConcurrentFrames, '1');
    $component = Native::test(Scan::class)->tap('toggle-live');
    captureFrame($component);

    $component->tap('snapshot')->assertNativeNotCalled('ThriftyCamera.Snapshot');

    finishAnalysis($component);
    $component->tap('snapshot')->assertNativeCalled('ThriftyCamera.Snapshot',
        fn (array $params) => $params['directory'] === Storage::disk('local')->path('frames'));
});

it('turns the camera on and then snaps when the camera is off', function () {
    $component = Native::test(Scan::class)
        ->tap('snapshot')
        ->assertNativeNotCalled('ThriftyCamera.Snapshot')
        ->assertSee('Back camera');

    expect(ScanSession::sole()->source_type)->toBe('camera')
        ->and(scanState()->snapshotDueAt)->toBeGreaterThan(0.0);

    scanState()->snapshotDueAt = microtime(true) - 0.1;
    $component->firePolls()->assertNativeCalled('ThriftyCamera.Snapshot');

    expect(scanState()->snapshotDueAt)->toBe(0.0);
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
    failAnalysis($component, AnalysisFailed::class, 'The model returned an invalid response.')
        ->assertSee('The model returned an invalid response.')
        ->assertSee('0/4')
        ->assertMissingElement('pressable', fn (array $node) => ($node['ref'] ?? null) === 'error-open-settings');

    captureFrame($component, name: 'b.jpg');
    finishAnalysis($component)->assertDontSee('The model returned an invalid response.');
});

it('offers settings when an analysis reports a missing key', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');

    captureFrame($component);
    failAnalysis($component, MissingApiKey::class, 'Add your OpenAI API key in Settings to start scanning.')
        ->tap('error-open-settings')
        ->assertNavigatedTo('/settings');
});

it('dismisses the error banner', function () {
    scanState()->fail('Camera access failed');

    Native::test(Scan::class)
        ->assertSee('Camera access failed')
        ->tap('dismiss-error')
        ->assertDontSee('Camera access failed');
});

it('surfaces camera failures and stops scanning', function () {
    Native::test(Scan::class)->tap('toggle-live')
        ->emitNative(CameraFailed::class, ['message' => 'Camera access is off — enable it in Settings.'])
        ->assertSee('Camera access is off — enable it in Settings.')
        ->assertSee('Paused');
});

it('stops live scanning but keeps the session and feed', function () {
    $item = Item::factory()->create(['name' => 'Walkman']);
    $component = Native::test(Scan::class)->tap('toggle-live');
    captureFrame($component);
    finishAnalysis($component, [$item->id]);
    $sessionId = scanState()->sessionId;

    $component->tap('toggle-live')->assertSee('Paused')->assertSee('Walkman');

    expect(ScanSession::find($sessionId)->ended_at)->toBeNull()
        ->and(scanState()->sessionId)->toBe($sessionId);

    $component->tap('toggle-live')->assertSee('Walkman');

    expect(scanState()->sessionId)->toBe($sessionId)
        ->and(scanState()->scanning)->toBeTrue();
});

it('switches cameras into a new session and keeps scanning', function () {
    $component = Native::test(Scan::class)->tap('toggle-live')->call('useFrontCamera');

    expect(ScanSession::count())->toBe(2)
        ->and(ScanSession::query()->whereNull('ended_at')->count())->toBe(1)
        ->and(scanState()->facing)->toBe('front')
        ->and(scanState()->scanning)->toBeTrue();

    $component->assertSee('Front camera')
        ->call('turnCameraOff')
        ->assertSee('Camera off')
        ->assertSee('Paused')
        ->assertMissingElement('row', fn (array $node) => ($node['ref'] ?? null) === 'source-caption');

    expect(ScanSession::query()->whereNull('ended_at')->count())->toBe(0);
});

it('stops scanning and clears the source when the tab is left', function () {
    $item = Item::factory()->create(['name' => 'Walkman']);
    $component = Native::test(Scan::class)->tap('toggle-live');
    captureFrame($component, name: 'a.jpg');
    finishAnalysis($component, [$item->id]);
    captureFrame($component, name: 'b.jpg');
    $inFlight = scanState()->pending[array_key_last(scanState()->pending)];
    $sessionId = scanState()->sessionId;

    $component->instance()->unmount();

    expect(scanState()->scanning)->toBeFalse()
        ->and(scanState()->facing)->toBe('off')
        ->and(scanState()->sessionId)->toBeNull()
        ->and(ScanSession::find($sessionId)->ended_at)->not->toBeNull()
        ->and(scanState()->pending)->toHaveCount(1);

    $run = FrameRun::factory()->create(['id' => $inFlight['frameRunId'], 'frame_path' => null, 'status' => FrameRunStatus::Completed]);
    Item::factory()->create(['frame_run_id' => $run->id, 'name' => 'Eames chair']);
    scanState()->lastRevealAt = 0;

    Native::test(Scan::class)
        ->assertSee('Paused')
        ->assertSee('Camera off')
        ->assertSee('Eames chair')
        ->assertSee('Walkman')
        ->assertSee('0/4')
        ->assertElement('thrifty_camera', fn (array $node) => $node['props']['scanning'] === false && $node['props']['facing'] === 'off');
});

it('opens settings', function () {
    Native::test(Scan::class)->tap('open-settings')->assertNavigatedTo('/settings');
});

it('opens the gallery picker for uploads', function () {
    Native::test(Scan::class)
        ->tap('upload')
        ->assertNativeCalled('Camera.PickMedia', fn (array $params) => $params['mediaType'] === 'all' && $params['id'] === Scan::UploadPickerId);
});

it('imports an uploaded HEIC photo through the plugin and analyzes it once', function () {
    $picked = pickedTempFile('heic');
    $component = pickMedia(Native::test(Scan::class), $picked, 'image')
        ->assertNativeCalled('ThriftyCamera.ImportImage', fn (array $params) => $params['imagePath'] === $picked
            && str_starts_with($params['directory'], Storage::disk('local')->path('frames/import-')))
        ->assertSee('Uploaded photo');

    expect(ScanSession::sole()->source_type)->toBe('image');
    $this->asyncFake->assertNotDispatched();

    importedFrame($component)->assertNativeCalled('ThriftyCamera.Shutter');

    $this->asyncFake->assertDispatchedTimes(1);
    $this->asyncFake->assertDispatched(fn (array $dispatch) => $dispatch['work']['args'][1] === 'frames/photo.jpg');
    expect(Storage::disk('local')->directories('frames'))->toBe([]);
    expect(file_exists($picked))->toBeFalse()
        ->and(scanState()->stillPreviewPath)->toStartWith('previews/');

    Storage::disk('local')->delete('frames/photo.jpg');
    finishAnalysis($component)->assertElement('image', fn (array $node) => str_contains($node['props']['src'] ?? '', 'previews/'));
    Storage::disk('local')->assertExists(scanState()->stillPreviewPath);
});

it('reports a photo that arrives while every slot is busy', function () {
    app(AppSettings::class)->set(AppSettings::MaxConcurrentFrames, '1');
    scanState()->pending['busy'] = ['sessionId' => 's', 'frameRunId' => 'r', 'dispatchedAt' => time()];

    $component = pickMedia(Native::test(Scan::class), pickedTempFile('jpg'), 'image');
    importedFrame($component)->assertSee('Every analysis slot is busy');

    $this->asyncFake->assertNotDispatched();
});

it('cleans up the picked photo when the import fails', function () {
    $picked = pickedTempFile('heic');

    pickMedia(Native::test(Scan::class), $picked, 'image')
        ->emitNative(CameraFailed::class, ['message' => 'This photo could not be read.'])
        ->assertSee('This photo could not be read.');

    expect(file_exists($picked))->toBeFalse();
});

it('ignores a cancelled picker', function () {
    Native::test(Scan::class)
        ->emitNative(MediaSelected::class, ['success' => false, 'cancelled' => true, 'id' => Scan::UploadPickerId])
        ->assertDontSee('could not be loaded');

    expect(ScanSession::count())->toBe(0);
});

it('samples an uploaded video in real time, dropping frames at capacity', function () {
    app(AppSettings::class)->set(AppSettings::MaxConcurrentFrames, '2');
    app(AppSettings::class)->set(AppSettings::ScanIntervalSeconds, '3');

    $component = pickMedia(Native::test(Scan::class), '/tmp/Gallery/clip.mov', 'video');
    $runId = scanState()->videoRunId;

    $component->assertNativeCalled('ThriftyCamera.ExtractVideoFrames', fn (array $params) => $params['videoPath'] === '/tmp/Gallery/clip.mov'
        && $params['intervalSeconds'] === 3
        && $params['runId'] === $runId
        && $params['directory'] === Storage::disk('local')->path('frames'));
    expect(ScanSession::sole()->source_type)->toBe('video');
    $component->assertSee('Uploaded video')->assertSee('Stop');

    captureFrame($component, 'video', 'v1.jpg', $runId);
    captureFrame($component, 'video', 'v2.jpg', $runId);
    captureFrame($component, 'video', 'v3.jpg', $runId);

    $this->asyncFake->assertDispatchedTimes(2);
    Storage::disk('local')->assertMissing('frames/v3.jpg');
    expect(scanState()->stillPreviewPath)->toStartWith('previews/')
        ->and(Storage::disk('local')->files('previews'))->toHaveCount(1);

    $component->emitNative(VideoFramesExtracted::class, ['count' => 3, 'runId' => $runId])->assertSee('Paused');

    expect(scanState()->videoRunId)->toBeNull()
        ->and(ScanSession::sole()->ended_at)->toBeNull();
});

it('ignores frames and completion from another video run', function () {
    $component = pickMedia(Native::test(Scan::class), '/tmp/Gallery/clip.mov', 'video');

    captureFrame($component, 'video', 'stale.jpg', 'old-run');
    $component->emitNative(VideoFramesExtracted::class, ['count' => 1, 'runId' => 'old-run']);

    $this->asyncFake->assertNotDispatched();
    Storage::disk('local')->assertMissing('frames/stale.jpg');
    expect(scanState()->scanning)->toBeTrue();
});

it('cancels video extraction on stop, a new source or leaving the tab', function () {
    $component = pickMedia(Native::test(Scan::class), '/tmp/Gallery/a.mov', 'video');
    $first = scanState()->videoRunId;

    $component->tap('toggle-live')->assertNativeCalled('ThriftyCamera.CancelVideoExtraction', fn (array $params) => $params['runId'] === $first);
    expect(scanState()->videoRunId)->toBeNull();

    pickMedia($component, '/tmp/Gallery/b.mov', 'video');
    $second = scanState()->videoRunId;
    pickMedia($component, pickedTempFile('jpg'), 'image')
        ->assertNativeCalled('ThriftyCamera.CancelVideoExtraction', fn (array $params) => $params['runId'] === $second);

    pickMedia($component, '/tmp/Gallery/c.mov', 'video');
    $third = scanState()->videoRunId;
    $component->instance()->unmount();

    $this->bridge->assertCalled('ThriftyCamera.CancelVideoExtraction', fn (array $params) => $params['runId'] === $third);
});

it('keeps the camera error when a video cannot be read', function () {
    $component = pickMedia(Native::test(Scan::class), '/tmp/Gallery/broken.mov', 'video');

    $component->emitNative(CameraFailed::class, ['message' => 'This video could not be opened.'])
        ->assertSee('Stop')
        ->emitNative(VideoFramesExtracted::class, ['count' => 0, 'runId' => scanState()->videoRunId])
        ->assertSee('This video could not be opened.')
        ->assertSee('Paused');
});

it('reconciles no-find and failed runs by frame run id when Scan comes back', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');
    captureFrame($component, name: 'a.jpg');
    captureFrame($component, name: 'b.jpg');
    [$noFinds, $failed] = array_values(scanState()->pending);

    FrameRun::factory()->create(['id' => $noFinds['frameRunId'], 'frame_path' => null, 'status' => FrameRunStatus::Completed]);
    FrameRun::factory()->create(['id' => $failed['frameRunId'], 'frame_path' => null, 'status' => FrameRunStatus::Failed, 'error' => 'Rate limited']);

    $component->call('onResume')->assertSee('0/4')->assertSee('Rate limited');
    $component->assertNativeNotCalled('ThriftyCamera.Chime');
});

it('settles finished runs on a poll while Scan is showing, after a grace period', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');
    captureFrame($component);
    $entry = scanState()->pending[array_key_last(scanState()->pending)];
    $run = FrameRun::factory()->create(['id' => $entry['frameRunId'], 'status' => FrameRunStatus::Completed, 'completed_at' => now()]);

    scanState()->lastReconcileAt = 0;
    $component->firePolls()->assertSee('1/4');

    $run->update(['completed_at' => now()->subSeconds(Scan::SettleGraceSeconds + 1)]);
    scanState()->lastReconcileAt = 0;
    $component->firePolls()->assertSee('0/4');
});

it('expires analyses that never report back', function () {
    scanState()->pending['lost'] = ['sessionId' => 's', 'frameRunId' => 'lost', 'dispatchedAt' => time() - Scan::PendingExpirySeconds - 1];
    scanState()->pending['recent'] = ['sessionId' => 's', 'frameRunId' => 'recent', 'dispatchedAt' => time()];

    Native::test(Scan::class)->assertSee('1/4');

    expect(array_keys(scanState()->pending))->toBe(['recent']);
});

it('ties each photo import to its own pick', function () {
    $first = pickedTempFile('heic');
    $second = pickedTempFile('heic');
    $component = pickMedia(Native::test(Scan::class), $first, 'image');
    pickMedia($component, $second, 'image');

    importedFrame($component, 0, 'first.jpg');

    $this->asyncFake->assertNotDispatched();
    expect(file_exists($first))->toBeFalse()
        ->and(file_exists($second))->toBeTrue();
    Storage::disk('local')->assertMissing('frames/first.jpg');

    importedFrame($component, 1, 'second.jpg');

    $this->asyncFake->assertDispatched(fn (array $dispatch) => $dispatch['work']['args'][0] === scanState()->sessionId
        && $dispatch['work']['args'][1] === 'frames/second.jpg');
    expect(file_exists($second))->toBeFalse();
});

it('abandons photo imports when the tab is left', function () {
    $picked = pickedTempFile('heic');
    $component = pickMedia(Native::test(Scan::class), $picked, 'image');

    $component->instance()->unmount();

    expect(file_exists($picked))->toBeFalse()
        ->and(scanState()->pendingImports)->toBe([]);
});

it('sweeps stale previews and import directories on mount', function () {
    $disk = Storage::disk('local');
    $disk->put('previews/stale.jpg', 'x');
    $disk->put('previews/current.jpg', 'x');
    $disk->put('frames/import-stale/photo.jpg', 'x');
    $disk->put('frames/kept.jpg', 'x');
    scanState()->stillPreviewPath = 'previews/current.jpg';

    Native::test(Scan::class);

    $disk->assertMissing('previews/stale.jpg');
    $disk->assertExists('previews/current.jpg');
    $disk->assertExists('frames/kept.jpg');
    expect($disk->directories('frames'))->toBe([]);
});

it('plays the shutter and flash for video frames', function () {
    $component = pickMedia(Native::test(Scan::class), '/tmp/Gallery/clip.mov', 'video');

    captureFrame($component, 'video', 'v1.jpg', scanState()->videoRunId)
        ->assertNativeCalled('ThriftyCamera.Shutter')
        ->assertElement('column', fn (array $node) => ($node['ref'] ?? null) === 'snapshot-flash');
});

it('deletes the picked video when its extraction is cancelled', function () {
    $picked = pickedTempFile('mov');
    $component = pickMedia(Native::test(Scan::class), $picked, 'video');

    $component->tap('toggle-live');

    expect(file_exists($picked))->toBeFalse();
});

it('ignores camera failures from another video run', function () {
    $component = pickMedia(Native::test(Scan::class), '/tmp/Gallery/clip.mov', 'video')
        ->emitNative(CameraFailed::class, ['message' => 'Old run failed.', 'runId' => 'old-run'])
        ->assertDontSee('Old run failed.')
        ->assertSee('Stop');

    $component->emitNative(CameraFailed::class, ['message' => 'This video could not be opened.', 'runId' => scanState()->videoRunId])
        ->assertSee('This video could not be opened.');
});

it('does not settle analyses just because the key was cleared after they were sent', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');
    captureFrame($component);
    app(AppSettings::class)->set(AppSettings::OpenAiApiKey, null);

    $component->call('onResume')->assertSee('1/4')->assertDontSee('Add your OpenAI API key');
});

it('settles results delivered while Settings is on top', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');
    captureFrame($component);
    $taskId = array_key_last(scanState()->pending);

    Native::test(Settings::class)->emitNative(Scan::FrameAnalyzedEvent, [
        'id' => $taskId,
        'status' => 'failed',
        'exceptionClass' => MissingApiKey::class,
        'message' => 'Add your OpenAI API key in Settings to start scanning.',
    ]);

    expect(scanState()->pending)->toBe([]);
    $component->call('onResume')->assertSee('0/4')->assertSee('Add your OpenAI API key');
});

it('keeps a watchdog-timed-out analysis until its frame run lands, then shows and chimes its finds', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');
    captureFrame($component);
    $taskId = array_key_last(scanState()->pending);

    failAnalysis($component, 'RuntimeException', 'The async task did not complete within 600 seconds.')
        ->assertSee('1/4')
        ->assertDontSee('did not complete');

    expect(scanState()->pending[$taskId]['timedOutAt'])->toBe(time());

    $run = FrameRun::factory()->create(['id' => scanState()->pending[$taskId]['frameRunId'], 'status' => FrameRunStatus::Completed, 'completed_at' => now()]);
    Item::factory()->create(['frame_run_id' => $run->id, 'name' => 'Late lamp']);
    scanState()->lastReconcileAt = 0;
    scanState()->lastRevealAt = 0;

    $component->firePolls()
        ->assertSee('0/4')
        ->assertSee('Late lamp')
        ->assertNativeCalled('ThriftyCamera.Chime');
});

it('handles video frames and completion delivered while another screen is on top, within the slot limit', function () {
    app(AppSettings::class)->set(AppSettings::MaxConcurrentFrames, '2');
    $picked = pickedTempFile('mov');
    $component = pickMedia(Native::test(Scan::class), $picked, 'video');
    $runId = scanState()->videoRunId;

    $settings = Native::test(Settings::class);
    captureFrame($settings, 'video', 'v1.jpg', $runId);
    captureFrame($settings, 'video', 'v2.jpg', $runId);
    captureFrame($settings, 'video', 'v3.jpg', $runId);
    $settings->emitNative(VideoFramesExtracted::class, ['count' => 3, 'runId' => $runId]);

    $this->asyncFake->assertDispatchedTimes(2);
    Storage::disk('local')->assertMissing('frames/v3.jpg');

    $component->call('onResume')->assertSee('Paused')->assertSee('2/2');

    $this->asyncFake->assertDispatchedTimes(2);
    expect(scanState()->videoRunId)->toBeNull()
        ->and(file_exists($picked))->toBeFalse();
});

it('analyzes only the newest frames of a 90-frame backlog, with one round of feedback', function () {
    $component = pickMedia(Native::test(Scan::class), pickedTempFile('mov'), 'video');
    $runId = scanState()->videoRunId;
    $journal = app(VideoRunJournal::class);
    $disk = Storage::disk('local');

    foreach (range(1, 90) as $index) {
        $disk->put("frames/b{$index}.jpg", 'jpeg');
        $journal->record(new FrameCaptured($disk->path("frames/b{$index}.jpg"), 'video', 960, 540, '2026-09-23T10:00:00Z', $index * 2.0, $runId));
    }

    $component->call('onResume')->assertSee('4/4');

    $analyzed = array_map(fn (array $dispatch) => $dispatch['work']['args'][1], $this->asyncFake->dispatched);
    expect($analyzed)->toBe(['frames/b87.jpg', 'frames/b88.jpg', 'frames/b89.jpg', 'frames/b90.jpg'])
        ->and($disk->files('frames'))->toHaveCount(4)
        ->and($disk->files('previews'))->toHaveCount(1)
        ->and($disk->get(scanState()->stillPreviewPath))->toBe('jpeg');
    $component->assertNativeCalledTimes('ThriftyCamera.Shutter', 1);
});

it('drops a backlog quietly when every slot is busy, still showing the newest frame', function () {
    app(AppSettings::class)->set(AppSettings::MaxConcurrentFrames, '1');
    $component = pickMedia(Native::test(Scan::class), pickedTempFile('mov'), 'video');
    $runId = scanState()->videoRunId;
    scanState()->pending['busy'] = ['sessionId' => 's', 'frameRunId' => 'r', 'dispatchedAt' => time()];
    $disk = Storage::disk('local');

    foreach (range(1, 3) as $index) {
        $disk->put("frames/b{$index}.jpg", "frame {$index}");
        app(VideoRunJournal::class)->record(new FrameCaptured($disk->path("frames/b{$index}.jpg"), 'video', 960, 540, '2026-09-23T10:00:00Z', null, $runId));
    }

    $component->call('onResume')->assertNativeNotCalled('ThriftyCamera.Shutter');

    $this->asyncFake->assertNotDispatched();
    expect($disk->files('frames'))->toBe([])
        ->and($disk->get(scanState()->stillPreviewPath))->toBe('frame 3');
});

it('keeps analyzing video frames while a find is pushed over Scan', function () {
    $component = pickMedia(Native::test(Scan::class), pickedTempFile('mov'), 'video');
    $runId = scanState()->videoRunId;

    $settings = Native::test(Settings::class);
    captureFrame($settings, 'video', 'v1.jpg', $runId);
    captureFrame($settings, 'video', 'v2.jpg', $runId);

    $this->asyncFake->assertDispatchedTimes(2);
    $settings->emitNative(VideoFramesExtracted::class, ['count' => 2, 'runId' => $runId]);

    expect(scanState()->videoRunId)->toBeNull()
        ->and(scanState()->scanning)->toBeFalse();

    $component->call('onResume')->assertSee('Paused')->assertSee('2/4');
    $this->asyncFake->assertDispatchedTimes(2);
});

it('ignores a failure from an earlier photo pick and keeps the current import', function () {
    $first = pickedTempFile('heic');
    $second = pickedTempFile('heic');
    $component = pickMedia(Native::test(Scan::class), $first, 'image');
    pickMedia($component, $second, 'image');

    $component->emitNative(CameraFailed::class, ['message' => 'Photo A could not be read.'])
        ->assertDontSee('Photo A could not be read.');

    expect(file_exists($first))->toBeFalse()
        ->and(file_exists($second))->toBeTrue()
        ->and(scanState()->pendingImports)->toHaveCount(1);

    importedFrame($component, 1, 'second.jpg');
    $this->asyncFake->assertDispatchedTimes(1);
});

it('expires a timed-out analysis measured from the timeout, not the dispatch', function () {
    scanState()->pending['late'] = ['sessionId' => 's', 'frameRunId' => 'late', 'dispatchedAt' => time() - Scan::PendingExpirySeconds - 100, 'timedOutAt' => time() - 60];
    scanState()->pending['gone'] = ['sessionId' => 's', 'frameRunId' => 'gone', 'dispatchedAt' => time() - 2000, 'timedOutAt' => time() - Scan::PendingExpirySeconds - 1];

    Native::test(Scan::class)->assertSee('1/4');

    expect(array_keys(scanState()->pending))->toBe(['late']);
});

it('ends sessions with millisecond precision', function () {
    $this->travelTo(now()->setMicrosecond(123456));

    Native::test(Scan::class)->tap('toggle-live')->call('turnCameraOff');

    expect(ScanSession::sole()->ended_at->format('v'))->toBe('123');
});

it('ignores results for analyses it is not waiting on', function () {
    Native::test(Scan::class)
        ->emitNative(Scan::FrameAnalyzedEvent, ['id' => 'unknown', 'status' => 'finished', 'result' => ['itemIds' => ['x']]])
        ->assertNativeNotCalled('ThriftyCamera.Chime');
});

it('is accessible', function () {
    $component = Native::test(Scan::class)->tap('toggle-live');
    scanState()->liveItemIds = [Item::factory()->create()->id];

    $component->call('dismissError')->assertAccessible();
});
