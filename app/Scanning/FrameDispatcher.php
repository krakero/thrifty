<?php

namespace App\Scanning;

use App\Agent\Exceptions\MissingApiKey;
use App\Async\AnalyzeFrame;
use App\NativeComponents\Scan;
use App\Services\AppSettings;
use Illuminate\Support\Str;
use Thrifty\Camera\Facades\ThriftyCamera;

/**
 * Starts frame analyses within the concurrency limit and records them as in flight.
 */
class FrameDispatcher
{
    public function __construct(private LiveScanState $state, private AppSettings $settings, private FrameFiles $files) {}

    public function freeSlots(): int
    {
        return max(0, $this->settings->maxConcurrentFrames() - $this->state->inFlight());
    }

    public function atCapacity(): bool
    {
        return $this->freeSlots() === 0;
    }

    /**
     * Dispatch one frame. Without an API key every analysis fails before it records anything, so that fails
     * here instead of dispatching; a dispatched analysis therefore always had a key when it was sent.
     */
    public function analyze(string $sessionId, string $framePath, string $capturedAt): void
    {
        if ($this->settings->openAiApiKey() === null) {
            $this->files->delete($framePath);
            $this->state->fail((new MissingApiKey)->getMessage(), needsApiKey: true);

            return;
        }

        $frameRunId = (string) Str::ulid();
        $task = AnalyzeFrame::dispatch(
            $sessionId,
            $framePath,
            $capturedAt,
            $frameRunId,
            (string) $this->settings->get(AppSettings::FindCriteria, ''),
        )->shared(Scan::FrameAnalyzedEvent);

        $this->state->pending[$task->getId()] = ['sessionId' => $sessionId, 'frameRunId' => $frameRunId, 'dispatchedAt' => time()];

        if (! $task->start()) {
            unset($this->state->pending[$task->getId()]);
            $this->state->fail('Frame analysis could not be started.');
        }
    }

    /**
     * The capture feedback: the flash on the stage plus the shutter click and haptic.
     */
    public function captureFeedback(): void
    {
        $this->state->flashAt = microtime(true);
        ThriftyCamera::shutter();
    }
}
