<?php

namespace App\Scanning;

use App\Enums\ScanSource;
use Illuminate\Support\Facades\Event;
use Thrifty\Camera\Events\CameraFailed;
use Thrifty\Camera\Events\FrameCaptured;
use Thrifty\Camera\Events\VideoFramesExtracted;
use Thrifty\Camera\Facades\ThriftyCamera;

/**
 * Consumes the current video run from the camera plugin's event journal.
 *
 * Video events reach whichever screen is active and are journaled per run, so the run is drained from the journal
 * rather than from each event. A drain may find a backlog (frames journaled while nothing drained them): like the
 * web app, which samples in real time and skips ticks at capacity, only the newest frames that fit the free
 * analysis slots are analyzed and the rest are discarded quietly, with one round of capture feedback.
 */
class VideoRunDrainer
{
    public function __construct(
        private LiveScanState $state,
        private FrameDispatcher $dispatcher,
        private FrameFiles $files,
        private Stage $stage,
    ) {}

    /**
     * Keep draining while a find or Settings is pushed over Scan, as the web app keeps analyzing under its modals.
     * Plugin events are dispatched through Laravel for every screen (after the plugin journals them), so one
     * listener for the app's lifetime covers it. Idempotent.
     */
    public function listenGlobally(): void
    {
        if ($this->state->drainsVideoGlobally) {
            return;
        }

        $this->state->drainsVideoGlobally = true;

        Event::listen(
            [FrameCaptured::class, VideoFramesExtracted::class, CameraFailed::class],
            function (FrameCaptured|VideoFramesExtracted|CameraFailed $event): void {
                if ($event->runId !== null && $event->runId === app(LiveScanState::class)->videoRunId) {
                    app(self::class)->drain();
                }
            },
        );
    }

    public function drain(): void
    {
        $runId = $this->state->videoRunId;

        if ($runId === null) {
            return;
        }

        $frames = [];
        $failure = null;
        $extracted = null;

        foreach (ThriftyCamera::takeVideoEvents($runId) as $event) {
            match (true) {
                $event instanceof FrameCaptured => $frames[] = $event,
                $event instanceof CameraFailed => $failure = $event,
                $event instanceof VideoFramesExtracted => $extracted = $event,
            };
        }

        $this->analyzeNewest($frames);

        if ($failure !== null) {
            $this->state->fail($failure->message);
        }

        if ($extracted !== null) {
            $this->finish($extracted->count);
        }
    }

    /**
     * @param  list<FrameCaptured>  $frames  Oldest first.
     */
    private function analyzeNewest(array $frames): void
    {
        if ($frames === []) {
            return;
        }

        $sessionId = $this->state->sessionId;
        $usable = $sessionId !== null && $this->state->source === ScanSource::Video->value;
        $freeSlots = $usable ? $this->dispatcher->freeSlots() : 0;
        $keep = $freeSlots > 0 ? array_slice($frames, -$freeSlots) : [];
        $keptPaths = array_map(fn (FrameCaptured $frame): string => $frame->path, $keep);

        if ($usable) {
            $this->stage->show(FrameFiles::relativePath($frames[array_key_last($frames)]->path));
        }

        foreach ($frames as $frame) {
            if (! in_array($frame->path, $keptPaths, true)) {
                $this->files->delete(FrameFiles::relativePath($frame->path));
            }
        }

        if ($keep === []) {
            return;
        }

        $this->dispatcher->captureFeedback();

        foreach ($keep as $frame) {
            $this->dispatcher->analyze((string) $sessionId, FrameFiles::relativePath($frame->path), $frame->capturedAt);
        }
    }

    private function finish(int $count): void
    {
        $this->state->videoRunId = null;
        $this->state->scanning = false;
        $this->files->deletePicked($this->state->pickedMediaPath);
        $this->state->pickedMediaPath = null;

        if ($count === 0 && $this->state->error === null) {
            $this->state->fail('No frames could be read from this video.');
        }
    }
}
