<?php

namespace App\NativeComponents;

use App\Agent\Exceptions\MissingApiKey;
use App\Async\AnalyzeFrame;
use App\Enums\FrameRunStatus;
use App\Enums\ScanSource;
use App\Models\AppStat;
use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ScanSession;
use App\Scanning\FrameDispatcher;
use App\Scanning\FrameFiles;
use App\Scanning\FrameResults;
use App\Scanning\LiveScanState;
use App\Scanning\ReceivesFrameAnalyses;
use App\Scanning\Stage;
use App\Scanning\VideoRunDrainer;
use App\Services\AppSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;
use Thrifty\Camera\Events\CameraFailed;
use Thrifty\Camera\Events\CameraStarted;
use Thrifty\Camera\Events\FrameCaptured;
use Thrifty\Camera\Events\VideoFramesExtracted;
use Thrifty\Camera\Facades\ThriftyCamera;

/**
 * The immersive scanner: live camera, snapshots and uploads feeding the valuation agent, with a live finds feed.
 *
 * Mirrors the scan view of the web app's `App.tsx`. Working state lives in {@see LiveScanState}; results arrive
 * through the shared `frame-analyzed` async event, and any delivered while another screen was active are
 * recovered from their FrameRun (each analysis gets a pre-generated FrameRun id).
 */
class Scan extends NativeComponent
{
    use ReceivesFrameAnalyses;

    public const FrameAnalyzedEvent = 'frame-analyzed';

    public const UploadPickerId = 'scan-upload';

    public const RevealIntervalSeconds = FrameResults::RevealIntervalSeconds;

    public const FlashSeconds = 0.32;

    /** How often outstanding analyses are checked against the database while Scan is showing. */
    public const ReconcileIntervalSeconds = 5;

    /** A run this recent may still have its shared result on the way; leave it to that event while Scan is showing. */
    public const SettleGraceSeconds = 10;

    /** Give up on an analysis whose result never arrived a little after its watchdog would have fired. */
    public const PendingExpirySeconds = AnalyzeFrame::TimeoutSeconds + 30;

    public function mount(): void
    {
        $state = $this->state();
        app(FrameFiles::class)->sweep(array_filter([$state->stillPreviewPath]), array_keys($state->pendingImports));
        app(VideoRunDrainer::class)->listenGlobally();
        app(VideoRunDrainer::class)->drain();
        $this->reconcile(settleRecent: true);
    }

    public function onResume(): void
    {
        $this->state()->clearResolvedKeyError();
        app(VideoRunDrainer::class)->drain();
        $this->reconcile(settleRecent: true);
    }

    /**
     * Leaving the Scan tab stops the media and clears the source, as the web app does when its view leaves Scan.
     * Analyses already in flight still finish; their results are recovered when Scan mounts again.
     */
    public function unmount(): void
    {
        $this->stopMedia();
        $this->abandonImports();
        $this->turnCameraOffState();
        $this->endSession();

        parent::unmount();
    }

    // ── Controls ─────────────────────────────────────

    /**
     * Live starts once the camera is actually running: at once when it already is, otherwise on CameraStarted.
     */
    public function toggleLiveScan(): void
    {
        $state = $this->state();

        if ($state->scanning || $state->pendingCameraAction === 'live') {
            $state->pendingCameraAction = null;
            $this->stopScan();

            return;
        }

        if ($this->cameraReady()) {
            $state->scanning = true;

            return;
        }

        $this->requestCamera($this->defaultFacing(), 'live');
    }

    /**
     * Capture the current preview, turning the camera on first when needed (the web's `ensureCamera` then capture).
     */
    public function takeSnapshot(): void
    {
        if (! $this->cameraReady()) {
            $this->requestCamera($this->defaultFacing(), 'snap');

            return;
        }

        $this->snapshotIfFree();
    }

    public function upload(): void
    {
        Camera::pickImages('all')->id(self::UploadPickerId);
    }

    public function useBackCamera(): void
    {
        $this->selectCamera(LiveScanState::FacingBack);
    }

    public function useFrontCamera(): void
    {
        $this->selectCamera(LiveScanState::FacingFront);
    }

    public function turnCameraOff(): void
    {
        $this->selectCamera(LiveScanState::FacingOff);
    }

    public function dismissError(): void
    {
        $this->state()->clearError();
    }

    public function openSettings(): void
    {
        $this->navigate('/settings');
    }

    // ── Native events ────────────────────────────────

    #[On(FrameCaptured::class)]
    public function frameCaptured(
        string $path,
        string $source,
        int $width,
        int $height,
        string $capturedAt,
        ?float $videoSeconds = null,
        ?string $runId = null,
    ): void {
        if ($source === 'video') {
            $this->videoEventArrived($runId, $path);

            return;
        }

        $state = $this->state();
        $files = app(FrameFiles::class);
        $framePath = FrameFiles::relativePath($path);

        if ($source === 'image') {
            $framePath = $this->adoptImport($framePath);

            if ($framePath === null) {
                return;
            }
        }

        $accepted = $source === 'image'
            ? $state->source === ScanSource::Image->value
            : $state->source === ScanSource::Camera->value;

        if (! $accepted || $state->sessionId === null) {
            $files->delete($framePath);

            return;
        }

        if ($source === 'image') {
            app(Stage::class)->show($framePath);
        }

        if ($this->atCapacity()) {
            $files->delete($framePath);

            if ($source === 'image') {
                $state->fail('Every analysis slot is busy. Try the photo again in a moment.');
            }

            return;
        }

        app(FrameDispatcher::class)->captureFeedback();
        app(FrameDispatcher::class)->analyze($state->sessionId, $framePath, $capturedAt);
    }

    /**
     * Sent on every exit from a video extraction: the end of the video, a failure, or a cancel.
     */
    #[On(VideoFramesExtracted::class)]
    public function videoFramesExtracted(int $count, ?string $runId = null): void
    {
        $this->videoEventArrived($runId);
    }

    /**
     * The requested camera is running: begin its session (the web begins one only after `getUserMedia` succeeds)
     * and carry out whatever was waiting on it. Also sent when the preview reappears or the app returns to the
     * foreground, which just confirms the running camera.
     */
    #[On(CameraStarted::class)]
    public function cameraStarted(string $facing): void
    {
        $state = $this->state();

        if ($state->facing === LiveScanState::FacingOff) {
            return;
        }

        $state->cameraRunning = true;

        if ($state->source !== ScanSource::Camera->value || $state->sessionId === null) {
            $state->sourceLabel = $state->facing === LiveScanState::FacingFront ? 'Front camera' : 'Back camera';
            $this->beginSession(ScanSource::Camera);
        }

        $action = $state->pendingCameraAction;
        $state->pendingCameraAction = null;

        match ($action) {
            'live' => $state->scanning = true,
            'snap' => $this->snapshotIfFree(),
            default => null,
        };
    }

    /**
     * Failures from an earlier (cancelled or replaced) video run are ignored, and so are photo import failures
     * that belong to an earlier pick. A camera failure turns the camera selector back to Off, like the web's
     * `selectCamera` catch.
     */
    #[On(CameraFailed::class)]
    public function cameraFailed(string $message, ?string $runId = null): void
    {
        $state = $this->state();

        if ($runId !== null) {
            $this->videoEventArrived($runId);

            return;
        }

        if ($state->source === ScanSource::Image->value) {
            $this->photoImportFailed($message);

            return;
        }

        if ($state->facing !== LiveScanState::FacingOff) {
            $state->scanning = false;
            $this->turnCameraOffState();

            if ($state->source === ScanSource::Camera->value) {
                $this->endSession();
            }
        }

        $state->fail($message);
    }

    /**
     * @param  list<array{path?: string, type?: string, mimeType?: string}>  $files
     */
    #[On(MediaSelected::class)]
    public function mediaSelected(bool $success, array $files = [], ?string $error = null, bool $cancelled = false, ?string $id = null): void
    {
        if (($id !== null && $id !== self::UploadPickerId) || $cancelled) {
            return;
        }

        $file = $files[0] ?? null;

        if (! $success || ! is_array($file) || empty($file['path'])) {
            $this->state()->fail($error ?: 'The selected file could not be loaded.');

            return;
        }

        $isVideo = ($file['type'] ?? null) === 'video' || str_starts_with((string) ($file['mimeType'] ?? ''), 'video/');

        $isVideo ? $this->loadVideo($file['path']) : $this->loadImage($file['path']);
    }

    // ── Rendering ────────────────────────────────────

    public function render(): View
    {
        $state = $this->state();
        $now = microtime(true);

        if ($state->pending !== [] && $now - $state->lastReconcileAt >= self::ReconcileIntervalSeconds) {
            $this->reconcile(settleRecent: false);
        }

        $state->revealDue($now, self::RevealIntervalSeconds);
        $settings = app(AppSettings::class);

        return view('native.scan', [
            'state' => $state,
            'stats' => AppStat::current(),
            'maxConcurrentFrames' => $settings->maxConcurrentFrames(),
            'scanIntervalSeconds' => $settings->scanIntervalSeconds(),
            'framesDirectory' => FrameFiles::framesDirectory(),
            'stillPreview' => $state->stillPreviewPath ? FrameFiles::absolutePath($state->stillPreviewPath) : null,
            'liveItems' => $this->liveItems($state->liveItemIds),
            'flashing' => $now - $state->flashAt < self::FlashSeconds,
            'revealing' => $state->revealQueue !== [],
            'cameraStarting' => $state->pendingCameraAction !== null,
            'cameraLabel' => match ($state->facing) {
                LiveScanState::FacingBack => 'Back camera',
                LiveScanState::FacingFront => 'Front camera',
                default => 'Camera off',
            },
        ]);
    }

    // ── Internals ────────────────────────────────────

    private function state(): LiveScanState
    {
        return app(LiveScanState::class);
    }

    private function atCapacity(): bool
    {
        return app(FrameDispatcher::class)->atCapacity();
    }

    /**
     * The camera is running with a camera session to capture into.
     */
    private function cameraReady(): bool
    {
        $state = $this->state();

        return $state->facing !== LiveScanState::FacingOff
            && $state->cameraRunning
            && $state->source === ScanSource::Camera->value
            && $state->sessionId !== null;
    }

    private function defaultFacing(): string
    {
        $facing = $this->state()->facing;

        return $facing === LiveScanState::FacingOff ? LiveScanState::FacingBack : $facing;
    }

    /**
     * Ask the plugin for a camera. Nothing else changes until it answers with CameraStarted or CameraFailed.
     */
    private function requestCamera(string $facing, ?string $then): void
    {
        $state = $this->state();

        if ($state->facing !== $facing || $state->source !== ScanSource::Camera->value) {
            $this->stopMedia();
            $state->facing = $facing;
            $state->cameraRunning = false;
        }

        $state->pendingCameraAction = $then;

        // Already running on this camera: the plugin has nothing new to start, so act on it now.
        if ($state->cameraRunning) {
            $this->cameraStarted($facing);
        }
    }

    private function selectCamera(string $facing): void
    {
        $state = $this->state();

        if ($facing === LiveScanState::FacingOff) {
            $this->stopMedia();
            $this->turnCameraOffState();
            $state->sourceLabel = 'Camera off';
            $this->endSession();

            return;
        }

        if ($facing === $state->facing) {
            return;
        }

        $resumeScanning = $state->scanning;
        $this->endSession();
        $this->requestCamera($facing, $resumeScanning ? 'live' : 'open');
    }

    private function turnCameraOffState(): void
    {
        $state = $this->state();
        $state->facing = LiveScanState::FacingOff;
        $state->cameraRunning = false;
        $state->pendingCameraAction = null;
    }

    private function snapshotIfFree(): void
    {
        if (! $this->atCapacity()) {
            ThriftyCamera::snapshot(FrameFiles::framesDirectory());
        }
    }

    /**
     * The web's `stopScan`: stop sampling but keep the session, so the next Live or Snap continues the same feed.
     */
    private function stopScan(): void
    {
        $this->state()->scanning = false;
        $this->cancelVideo();
    }

    /**
     * The web's `stopMedia`: stop whatever is feeding frames and clear the stage.
     */
    private function stopMedia(): void
    {
        $state = $this->state();
        $state->scanning = false;
        $this->cancelVideo();
        app(Stage::class)->clear();
    }

    private function cancelVideo(): void
    {
        $state = $this->state();

        if ($state->videoRunId !== null) {
            ThriftyCamera::cancelVideoExtraction($state->videoRunId);
            ThriftyCamera::forgetVideoRun($state->videoRunId);
            $state->videoRunId = null;
        }

        // The run's final VideoFramesExtracted is ignored once it's no longer current, so clean up here.
        app(FrameFiles::class)->deletePicked($state->pickedMediaPath);
        $state->pickedMediaPath = null;
    }

    private function beginSession(ScanSource $source, ?string $sourceName = null): void
    {
        $this->endSession();

        $session = ScanSession::query()->create([
            'source_type' => $source->value,
            'source_name' => $sourceName,
            'started_at' => now(),
        ]);

        $state = $this->state();
        $state->sessionId = $session->id;
        $state->source = $source->value;
        $state->liveItemIds = [];
        $state->revealQueue = [];
    }

    private function endSession(): void
    {
        $state = $this->state();

        if ($state->sessionId !== null) {
            // Through the model, so ended_at keeps the model's millisecond date format.
            ScanSession::query()->whereKey($state->sessionId)->whereNull('ended_at')->first()?->update(['ended_at' => now()]);
        }

        $state->sessionId = null;
    }

    private function loadImage(string $absolutePath): void
    {
        $this->stopMedia();
        $state = $this->state();
        $this->turnCameraOffState();
        $state->sourceLabel = 'Uploaded photo';
        $this->beginSession(ScanSource::Image, basename($absolutePath));

        $token = strtolower((string) Str::ulid());
        $state->pendingImports[$token] = ['pickedPath' => $absolutePath, 'sessionId' => (string) $state->sessionId];

        ThriftyCamera::importImage($absolutePath, FrameFiles::importDirectory($token));
    }

    /**
     * Tie an imported frame to its pick: it's kept only while that pick's session is current.
     *
     * @return string|null The frame's final path, or null when it was discarded.
     */
    private function adoptImport(string $framePath): ?string
    {
        $state = $this->state();
        $files = app(FrameFiles::class);
        $token = FrameFiles::importToken($framePath);
        $import = $token !== null ? ($state->pendingImports[$token] ?? null) : null;

        if ($token !== null) {
            unset($state->pendingImports[$token]);
        }

        $files->deletePicked($import['pickedPath'] ?? null);

        if ($import === null || $import['sessionId'] !== $state->sessionId) {
            $files->delete($framePath);

            if ($token !== null) {
                $files->deleteImportDirectory($token);
            }

            return null;
        }

        return $files->adoptImportedFrame($framePath);
    }

    /**
     * Drop photo imports still in progress (all of them, or one session's) along with their picked originals.
     */
    private function abandonImports(?string $onlySessionId = null): void
    {
        $state = $this->state();

        foreach ($state->pendingImports as $token => $import) {
            if ($onlySessionId === null || $import['sessionId'] === $onlySessionId) {
                app(FrameFiles::class)->deletePicked($import['pickedPath']);
                app(FrameFiles::class)->deleteImportDirectory($token);
                unset($state->pendingImports[$token]);
            }
        }
    }

    /**
     * The plugin reports a failed photo import without saying which pick it was. Imports finish in the order they
     * were picked, so the failure belongs to the oldest one still outstanding; it's shown only when that pick is the
     * current one, and a later pick is left to finish.
     */
    private function photoImportFailed(string $message): void
    {
        $state = $this->state();
        $token = array_key_first($state->pendingImports);

        if ($token === null) {
            return;
        }

        $import = $state->pendingImports[$token];
        app(FrameFiles::class)->deletePicked($import['pickedPath']);
        app(FrameFiles::class)->deleteImportDirectory($token);
        unset($state->pendingImports[$token]);

        if ($import['sessionId'] === $state->sessionId) {
            $state->fail($message);
        }
    }

    private function loadVideo(string $absolutePath): void
    {
        $this->stopMedia();
        $state = $this->state();
        $this->turnCameraOffState();
        $state->sourceLabel = 'Uploaded video';
        $this->beginSession(ScanSource::Video, basename($absolutePath));
        $state->pickedMediaPath = $absolutePath;
        $state->scanning = true;

        $state->videoRunId = ThriftyCamera::extractVideoFrames(
            $absolutePath,
            app(AppSettings::class)->scanIntervalSeconds(),
            FrameFiles::framesDirectory(),
        );
    }

    /**
     * Video events for the current run are consumed from the plugin's journal (see {@see VideoRunDrainer}).
     */
    private function videoEventArrived(?string $runId, ?string $framePath = null): void
    {
        if ($runId !== null && $runId === $this->state()->videoRunId) {
            app(VideoRunDrainer::class)->drain();

            return;
        }

        // A run that is no longer current (cancelled or replaced): discard what it sends.
        if ($framePath !== null) {
            app(FrameFiles::class)->delete(FrameFiles::relativePath($framePath));
        }

        if ($runId !== null) {
            ThriftyCamera::forgetVideoRun($runId);
        }
    }

    /**
     * Settle analyses whose shared result never reached Scan, by their FrameRun.
     *
     * The agent saves the FrameRun under the pre-generated id on success and on every failure except a missing API
     * key, so a run row means the analysis is over. (Scan never dispatches without a key, and a MissingApiKey result
     * is settled by whichever screen receives it, see {@see ReceivesFrameAnalyses}.)
     * While Scan is showing (`$settleRecent` false) a just-finished run is left for its shared event, which is
     * moments away and also plays the chime; a watchdog-timed-out entry gets no such event, so it settles at once
     * and chimes here. Anything that never reports back is dropped once it has outlived the task's watchdog, or, for
     * a timed-out entry, the same span again from the moment it timed out.
     */
    private function reconcile(bool $settleRecent): void
    {
        $state = $this->state();
        $state->lastReconcileAt = microtime(true);

        if ($state->pending === []) {
            return;
        }

        $runs = FrameRun::query()
            ->whereIn('id', array_column($state->pending, 'frameRunId'))
            ->get()
            ->keyBy('id');
        $graceCutoff = now()->subSeconds(self::SettleGraceSeconds);
        $results = app(FrameResults::class);

        foreach ($state->pending as $taskId => $entry) {
            $run = $runs->get($entry['frameRunId']);
            $timedOut = isset($entry['timedOutAt']);

            if ($run === null) {
                if (time() - ($entry['timedOutAt'] ?? $entry['dispatchedAt']) > self::PendingExpirySeconds) {
                    unset($state->pending[$taskId]);
                }

                continue;
            }

            if (! $settleRecent && ! $timedOut && $run->completed_at !== null && $run->completed_at->greaterThan($graceCutoff)) {
                continue;
            }

            unset($state->pending[$taskId]);

            if ($run->status === FrameRunStatus::Failed) {
                $state->fail($run->error ?: 'Frame analysis failed');

                continue;
            }

            $results->accept(Item::query()->where('frame_run_id', $run->id)->pluck('id')->all(), chime: $timedOut);
        }

        $state->revealDue(microtime(true), self::RevealIntervalSeconds);
    }

    /**
     * @param  list<string>  $itemIds
     * @return Collection<int, Item>
     */
    private function liveItems(array $itemIds): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        $items = Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');

        return collect($itemIds)->map(fn (string $id): ?Item => $items->get($id))->filter()->values();
    }
}
