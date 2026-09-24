<?php

namespace App\NativeComponents;

use App\Agent\Exceptions\AnalysisFailed;
use App\Agent\Exceptions\MissingApiKey;
use App\Async\AnalyzeFrame;
use App\Enums\FrameRunStatus;
use App\Enums\ScanSource;
use App\Models\AppStat;
use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ScanSession;
use App\Scanning\FrameImporter;
use App\Scanning\LiveScanState;
use App\Services\AppSettings;
use Illuminate\Support\Collection;
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
 * Mirrors the scan view of the web app's `App.tsx`. Working state lives in {@see LiveScanState} so in-flight
 * analyses survive tab switches; results arrive through the shared `frame-analyzed` async event and are
 * reconciled from the database whenever the screen comes back into view.
 */
class Scan extends NativeComponent
{
    public const FrameAnalyzedEvent = 'frame-analyzed';

    public const UploadPickerId = 'scan-upload';

    public const RevealIntervalSeconds = 0.5;

    public const FlashSeconds = 0.32;

    /** Give up on an analysis whose result never arrived a little after its watchdog would have fired. */
    public const PendingExpirySeconds = AnalyzeFrame::TimeoutSeconds + 30;

    public function mount(): void
    {
        $this->reconcile();
    }

    public function onResume(): void
    {
        $this->reconcile();
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
     * Capture the current preview. With the camera off this only turns it on: the plugin can't snapshot a
     * preview that hasn't started yet.
     */
    public function takeSnapshot(): void
    {
        $cameraWasOff = $this->state()->facing === LiveScanState::FacingOff;
        $this->ensureCamera();

        if ($cameraWasOff || $this->atCapacity()) {
            return;
        }

        ThriftyCamera::snapshot(FrameImporter::directory());
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
    public function frameCaptured(string $path, string $source, int $width, int $height, string $capturedAt, ?float $videoSeconds = null): void
    {
        $state = $this->state();
        $framePath = FrameImporter::relativePath($path);

        if ($source === 'video') {
            if ($state->source !== ScanSource::Video->value || $state->sessionId === null) {
                app(FrameImporter::class)->delete($framePath);

                return;
            }

            $state->queue[] = ['sessionId' => $state->sessionId, 'framePath' => $framePath, 'capturedAt' => $capturedAt];
            $this->pumpQueue();

            return;
        }

        if ($state->source !== ScanSource::Camera->value || $state->sessionId === null || $this->atCapacity()) {
            app(FrameImporter::class)->delete($framePath);

            return;
        }

        $state->flashAt = microtime(true);
        $this->analyze($state->sessionId, $framePath, $capturedAt);
    }

    #[On(VideoFramesExtracted::class)]
    public function videoFramesExtracted(int $count): void
    {
        $state = $this->state();

        if ($state->source !== ScanSource::Video->value) {
            return;
        }

        $state->videoExtracted = true;

        if ($count === 0 && $state->error === null) {
            $state->fail('No frames could be read from this video.');
        }

        $this->finishUploadIfDrained();
    }

    #[On(CameraFailed::class)]
    public function cameraFailed(string $message): void
    {
        $state = $this->state();

        // A failed video extraction is still followed by VideoFramesExtracted, which winds the session down.
        if ($state->source !== ScanSource::Video->value) {
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
        if ($id !== null && $id !== self::UploadPickerId) {
            return;
        }

        if ($cancelled) {
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
            $this->acceptResult($result);
        } else {
            $state->fail(
                $message ?: 'Frame analysis failed',
                is_a((string) $exceptionClass, MissingApiKey::class, true),
            );
        }

        $this->pumpQueue();
        $this->finishUploadIfDrained();
    }

    // ── Rendering ────────────────────────────────────

    public function render(): View
    {
        $state = $this->state();
        $now = microtime(true);
        $state->revealDue($now, self::RevealIntervalSeconds);
        $settings = app(AppSettings::class);

        return view('native.scan', [
            'state' => $state,
            'stats' => AppStat::current(),
            'maxConcurrentFrames' => $settings->maxConcurrentFrames(),
            'scanIntervalSeconds' => $settings->scanIntervalSeconds(),
            'framesDirectory' => FrameImporter::directory(),
            'liveItems' => $this->liveItems($state->liveItemIds),
            'flashing' => $now - $state->flashAt < self::FlashSeconds,
            'revealing' => $state->revealQueue !== [],
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
    private function ensureCamera(): string
    {
        $state = $this->state();

        if ($state->facing !== LiveScanState::FacingOff && $state->source === ScanSource::Camera->value && $state->sessionId !== null) {
            return $state->sessionId;
        }

        return $this->openCamera($state->facing === LiveScanState::FacingOff ? LiveScanState::FacingBack : $state->facing);
    }

    private function openCamera(string $facing): string
    {
        $this->stopMedia();

        $state = $this->state();
        $state->facing = $facing;
        $state->sourceLabel = $facing === LiveScanState::FacingFront ? 'Front camera' : 'Back camera';

        return $this->beginSession(ScanSource::Camera);
    }

    private function selectCamera(string $facing): void
    {
        $state = $this->state();

        if ($facing === LiveScanState::FacingOff) {
            $this->stopMedia();
            $state->sourceLabel = 'Camera off';

            return;
        }

        $resumeScanning = $state->scanning;
        $this->openCamera($facing);
        $state->scanning = $resumeScanning;
    }

    private function stopScan(): void
    {
        $state = $this->state();
        $state->scanning = false;

        if ($state->source === ScanSource::Video->value) {
            $this->dropQueuedFrames();
        }

        $this->endSession();
    }

    /**
     * Stop whatever is feeding frames: the camera, a video being processed, or a still preview.
     */
    private function stopMedia(): void
    {
        $state = $this->state();
        $state->scanning = false;
        $state->facing = LiveScanState::FacingOff;
        $state->stillPreviewPath = null;
        $this->dropQueuedFrames();
        $this->endSession();
    }

    private function beginSession(ScanSource $source, ?string $sourceName = null): string
    {
        $session = ScanSession::query()->create([
            'source_type' => $source->value,
            'source_name' => $sourceName,
            'started_at' => now(),
        ]);

        $state = $this->state();
        $state->sessionId = $session->id;
        $state->source = $source->value;
        $state->videoExtracted = false;
        $state->liveItemIds = [];
        $state->revealQueue = [];

        return $session->id;
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

        try {
            $framePath = app(FrameImporter::class)->importImage($absolutePath);
        } catch (AnalysisFailed $e) {
            $state->fail($e->getMessage());

            return;
        }

        $state->stillPreviewPath = FrameImporter::directory().'/'.basename($framePath);
        $state->sourceLabel = 'Uploaded photo';
        $state->flashAt = microtime(true);
        $sessionId = $this->beginSession(ScanSource::Image, basename($absolutePath));
        $state->queue[] = ['sessionId' => $sessionId, 'framePath' => $framePath, 'capturedAt' => now()->toIso8601String()];
        $this->pumpQueue();
    }

    private function loadVideo(string $absolutePath): void
    {
        $this->stopMedia();
        $state = $this->state();
        $state->sourceLabel = 'Uploaded video';
        $this->beginSession(ScanSource::Video, basename($absolutePath));
        $state->scanning = true;

        ThriftyCamera::extractVideoFrames(
            $absolutePath,
            app(AppSettings::class)->scanIntervalSeconds(),
            FrameImporter::directory(),
        );
    }

    /**
     * Dispatch queued upload frames while analysis slots are free.
     */
    private function pumpQueue(): void
    {
        $state = $this->state();

        while ($state->queue !== [] && ! $this->atCapacity()) {
            $next = array_shift($state->queue);
            $this->analyze($next['sessionId'], $next['framePath'], $next['capturedAt']);
        }
    }

    private function analyze(string $sessionId, string $framePath, string $capturedAt): void
    {
        $state = $this->state();
        $task = AnalyzeFrame::dispatch($sessionId, $framePath, $capturedAt)->shared(self::FrameAnalyzedEvent);
        $state->pending[$task->getId()] = ['sessionId' => $sessionId, 'framePath' => $framePath, 'dispatchedAt' => time()];

        if (! $task->start()) {
            unset($state->pending[$task->getId()]);
            $state->fail('Frame analysis could not be started.');
        }
    }

    /**
     * @param  array{itemIds?: list<string>, newItemIds?: list<string>}  $result
     */
    private function acceptResult(array $result): void
    {
        $state = $this->state();
        $itemIds = array_values(array_filter($result['itemIds'] ?? [], 'is_string'));

        if ($itemIds !== []) {
            $state->enqueueReveal($itemIds);
            $state->revealDue(microtime(true), self::RevealIntervalSeconds);
            ThriftyCamera::chime();
        }

        $state->clearError();
    }

    /**
     * A photo or video session is over once every frame has been analyzed.
     */
    private function finishUploadIfDrained(): void
    {
        $state = $this->state();

        if ($state->sessionId === null || $state->hasWorkFor($state->sessionId)) {
            return;
        }

        $finished = match ($state->source) {
            ScanSource::Image->value => true,
            ScanSource::Video->value => $state->videoExtracted,
            default => false,
        };

        if ($finished) {
            $state->scanning = false;
            $this->endSession();
        }
    }

    private function dropQueuedFrames(): void
    {
        $state = $this->state();

        foreach ($state->queue as $entry) {
            app(FrameImporter::class)->delete($entry['framePath']);
        }

        $state->queue = [];
    }

    /**
     * Settle analyses whose shared result was delivered while another screen was active.
     *
     * Agent runs record a FrameRun whether they succeed or fail, so the database says what happened. Failures
     * that never reach the agent (a missing API key, an unreadable frame) leave no FrameRun: without a key every
     * outstanding analysis has failed, and anything else is dropped once it outlives the task's watchdog.
     */
    private function reconcile(): void
    {
        $state = $this->state();

        if ($state->pending === []) {
            return;
        }

        if (app(AppSettings::class)->openAiApiKey() === null) {
            $state->pending = [];
            $state->fail((new MissingApiKey)->getMessage(), needsApiKey: true);
            $this->dropQueuedFrames();
            $this->finishUploadIfDrained();

            return;
        }

        $runs = FrameRun::query()
            ->whereIn('frame_path', array_column($state->pending, 'framePath'))
            ->get()
            ->keyBy('frame_path');

        foreach ($state->pending as $taskId => $entry) {
            $run = $runs->get($entry['framePath']);

            if ($run === null) {
                if (time() - $entry['dispatchedAt'] > self::PendingExpirySeconds) {
                    unset($state->pending[$taskId]);
                }

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
        $this->pumpQueue();
        $this->finishUploadIfDrained();
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
