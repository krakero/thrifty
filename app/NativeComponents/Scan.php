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
use App\Scanning\FrameFiles;
use App\Scanning\LiveScanState;
use App\Services\AppSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;
use Thrifty\Camera\Events\CameraFailed;
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
    public const FrameAnalyzedEvent = 'frame-analyzed';

    public const UploadPickerId = 'scan-upload';

    public const RevealIntervalSeconds = 0.5;

    public const FlashSeconds = 0.32;

    /** How long a camera turned on by Snap gets to start its preview before the snapshot is taken. */
    public const SnapshotWarmupSeconds = 1.0;

    /** How often outstanding analyses are checked against the database while Scan is showing. */
    public const ReconcileIntervalSeconds = 5;

    /** A run this recent may still have its shared result on the way; leave it to that event while Scan is showing. */
    public const SettleGraceSeconds = 10;

    /** Give up on an analysis whose result never arrived a little after its watchdog would have fired. */
    public const PendingExpirySeconds = AnalyzeFrame::TimeoutSeconds + 30;

    public function mount(): void
    {
        $this->reconcile(settleRecent: true);
    }

    public function onResume(): void
    {
        $this->reconcile(settleRecent: true);
    }

    /**
     * Leaving the Scan tab stops the media and clears the source, as the web app does when its view leaves Scan.
     * Analyses already in flight still finish; their results are recovered when Scan mounts again.
     */
    public function unmount(): void
    {
        $this->stopMedia();
        $this->state()->facing = LiveScanState::FacingOff;
        $this->endSession();

        parent::unmount();
    }

    // ── Controls ─────────────────────────────────────

    public function toggleLiveScan(): void
    {
        $state = $this->state();

        if ($state->scanning) {
            $this->stopScan();

            return;
        }

        $this->ensureCamera();
        $state->scanning = true;
    }

    /**
     * Capture the current preview. With the camera off it is turned on first and the snapshot follows once the
     * preview has had a moment to start (the plugin can only capture from a running session).
     */
    public function takeSnapshot(): void
    {
        $state = $this->state();
        $cameraWasOff = $state->facing === LiveScanState::FacingOff;
        $this->ensureCamera();

        if ($this->atCapacity()) {
            return;
        }

        if ($cameraWasOff) {
            $state->snapshotDueAt = microtime(true) + self::SnapshotWarmupSeconds;

            return;
        }

        ThriftyCamera::snapshot(FrameFiles::framesDirectory());
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
        $state = $this->state();
        $files = app(FrameFiles::class);
        $framePath = FrameFiles::relativePath($path);

        $accepted = match ($source) {
            'image' => $state->source === ScanSource::Image->value && $state->pickedMediaPath !== null,
            'video' => $state->source === ScanSource::Video->value && $runId !== null && $runId === $state->videoRunId,
            default => $state->source === ScanSource::Camera->value,
        };

        if (! $accepted || $state->sessionId === null) {
            $files->delete($framePath);

            return;
        }

        if ($source === 'image') {
            $files->deletePicked($state->pickedMediaPath);
            $state->pickedMediaPath = null;
        }

        if ($source === 'image' || $source === 'video') {
            $this->showStill($framePath);
        }

        if ($this->atCapacity()) {
            $files->delete($framePath);

            if ($source === 'image') {
                $state->fail('Every analysis slot is busy. Try the photo again in a moment.');
            }

            return;
        }

        if ($source !== 'video') {
            $state->flashAt = microtime(true);
            ThriftyCamera::shutter();
        }

        $this->analyze($state->sessionId, $framePath, $capturedAt);
    }

    /**
     * Sent on every exit from a video extraction: the end of the video, a failure, or a cancel.
     */
    #[On(VideoFramesExtracted::class)]
    public function videoFramesExtracted(int $count, ?string $runId = null): void
    {
        $state = $this->state();

        if ($runId === null || $runId !== $state->videoRunId) {
            return;
        }

        $state->videoRunId = null;
        $state->scanning = false;
        app(FrameFiles::class)->deletePicked($state->pickedMediaPath);
        $state->pickedMediaPath = null;

        if ($count === 0 && $state->error === null) {
            $state->fail('No frames could be read from this video.');
        }
    }

    #[On(CameraFailed::class)]
    public function cameraFailed(string $message): void
    {
        $state = $this->state();
        $state->snapshotDueAt = 0.0;

        if ($state->source === ScanSource::Image->value && $state->pickedMediaPath !== null) {
            app(FrameFiles::class)->deletePicked($state->pickedMediaPath);
            $state->pickedMediaPath = null;
        } elseif ($state->videoRunId === null) {
            // A failed video extraction is still followed by VideoFramesExtracted, which winds it down.
            $state->scanning = false;
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

    /**
     * Shared delivery for every {@see AnalyzeFrame} dispatch, so results land even after a tab switch.
     *
     * @param  array<string, mixed>|null  $result
     */
    #[On(self::FrameAnalyzedEvent)]
    public function frameAnalyzed(string $id, string $status, mixed $result = null, ?string $exceptionClass = null, ?string $message = null): void
    {
        $state = $this->state();

        if (! isset($state->pending[$id])) {
            return;
        }

        unset($state->pending[$id]);

        if ($status === 'finished' && is_array($result)) {
            $this->acceptResult(array_values(array_filter($result['itemIds'] ?? [], 'is_string')));
        } else {
            $state->fail(
                $message ?: 'Frame analysis failed',
                is_a((string) $exceptionClass, MissingApiKey::class, true),
            );
        }
    }

    // ── Rendering ────────────────────────────────────

    public function render(): View
    {
        $state = $this->state();
        $now = microtime(true);
        $this->takeDueSnapshot($now);

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
            'snapshotPending' => $state->snapshotDueAt > 0,
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
        return $this->state()->inFlight() >= app(AppSettings::class)->maxConcurrentFrames();
    }

    /**
     * Keep the running camera session, or turn the camera on and start a new one (the web's `ensureCamera`).
     */
    private function ensureCamera(): void
    {
        $state = $this->state();

        if ($state->facing !== LiveScanState::FacingOff && $state->source === ScanSource::Camera->value && $state->sessionId !== null) {
            return;
        }

        $this->openCamera($state->facing === LiveScanState::FacingOff ? LiveScanState::FacingBack : $state->facing);
    }

    private function openCamera(string $facing): void
    {
        $this->stopMedia();

        $state = $this->state();
        $state->facing = $facing;
        $state->sourceLabel = $facing === LiveScanState::FacingFront ? 'Front camera' : 'Back camera';
        $this->beginSession(ScanSource::Camera);
    }

    private function selectCamera(string $facing): void
    {
        $state = $this->state();

        if ($facing === LiveScanState::FacingOff) {
            $this->stopMedia();
            $state->facing = LiveScanState::FacingOff;
            $state->sourceLabel = 'Camera off';
            $this->endSession();

            return;
        }

        $resumeScanning = $state->scanning;
        $this->openCamera($facing);
        $state->scanning = $resumeScanning;
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
        $state->snapshotDueAt = 0.0;
        $this->cancelVideo();
        $this->clearStill();
    }

    private function cancelVideo(): void
    {
        $state = $this->state();

        if ($state->videoRunId !== null) {
            ThriftyCamera::cancelVideoExtraction($state->videoRunId);
            $state->videoRunId = null;
        }
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
            ScanSession::query()->whereKey($state->sessionId)->whereNull('ended_at')->update(['ended_at' => now()]);
        }

        $state->sessionId = null;
    }

    private function loadImage(string $absolutePath): void
    {
        $this->stopMedia();
        $state = $this->state();
        $state->facing = LiveScanState::FacingOff;
        $state->sourceLabel = 'Uploaded photo';
        $this->beginSession(ScanSource::Image, basename($absolutePath));
        $state->pickedMediaPath = $absolutePath;

        ThriftyCamera::importImage($absolutePath, FrameFiles::framesDirectory());
    }

    private function loadVideo(string $absolutePath): void
    {
        $this->stopMedia();
        $state = $this->state();
        $state->facing = LiveScanState::FacingOff;
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

    private function showStill(string $framePath): void
    {
        $state = $this->state();
        $previous = $state->stillPreviewPath;
        $state->stillPreviewPath = app(FrameFiles::class)->copyToPreview($framePath) ?? $previous;

        if ($previous !== $state->stillPreviewPath) {
            app(FrameFiles::class)->delete($previous);
        }
    }

    private function clearStill(): void
    {
        $state = $this->state();
        app(FrameFiles::class)->delete($state->stillPreviewPath);
        $state->stillPreviewPath = null;
    }

    private function takeDueSnapshot(float $now): void
    {
        $state = $this->state();

        if ($state->snapshotDueAt <= 0 || $now < $state->snapshotDueAt) {
            return;
        }

        $state->snapshotDueAt = 0.0;

        if ($state->facing !== LiveScanState::FacingOff && ! $this->atCapacity()) {
            ThriftyCamera::snapshot(FrameFiles::framesDirectory());
        }
    }

    /**
     * Without an API key every analysis fails before it records anything, so fail here instead of dispatching.
     */
    private function analyze(string $sessionId, string $framePath, string $capturedAt): void
    {
        $state = $this->state();
        $settings = app(AppSettings::class);

        if ($settings->openAiApiKey() === null) {
            app(FrameFiles::class)->delete($framePath);
            $state->fail((new MissingApiKey)->getMessage(), needsApiKey: true);

            return;
        }

        $frameRunId = (string) Str::ulid();
        $task = AnalyzeFrame::dispatch(
            $sessionId,
            $framePath,
            $capturedAt,
            $frameRunId,
            (string) $settings->get(AppSettings::FindCriteria, ''),
        )->shared(self::FrameAnalyzedEvent);

        $state->pending[$task->getId()] = ['sessionId' => $sessionId, 'frameRunId' => $frameRunId, 'dispatchedAt' => time()];

        if (! $task->start()) {
            unset($state->pending[$task->getId()]);
            $state->fail('Frame analysis could not be started.');
        }
    }

    /**
     * @param  list<string>  $itemIds
     */
    private function acceptResult(array $itemIds): void
    {
        $state = $this->state();

        if ($itemIds !== []) {
            $state->enqueueReveal($itemIds);
            $state->revealDue(microtime(true), self::RevealIntervalSeconds);
            ThriftyCamera::chime();
        }

        $state->clearError();
    }

    /**
     * Settle analyses whose shared result was delivered while another screen was active, by their FrameRun.
     *
     * The agent saves the FrameRun under the pre-generated id on success and on every failure, so a run row means
     * the analysis is over. While Scan is showing (`$settleRecent` false) a just-finished run is left for its
     * shared event, which is moments away and also plays the chime. Anything that never reports back is dropped
     * once it has outlived the task's watchdog.
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

        foreach ($state->pending as $taskId => $entry) {
            $run = $runs->get($entry['frameRunId']);

            if ($run === null) {
                if (time() - $entry['dispatchedAt'] > self::PendingExpirySeconds) {
                    unset($state->pending[$taskId]);
                }

                continue;
            }

            if (! $settleRecent && $run->completed_at !== null && $run->completed_at->greaterThan($graceCutoff)) {
                continue;
            }

            unset($state->pending[$taskId]);

            if ($run->status === FrameRunStatus::Failed) {
                $state->fail($run->error ?: 'Frame analysis failed');

                continue;
            }

            $state->enqueueReveal(Item::query()->where('frame_run_id', $run->id)->pluck('id')->all());
            $state->clearError();
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
