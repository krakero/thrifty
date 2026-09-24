<?php

namespace App\Scanning;

use App\Enums\ScanSource;
use Illuminate\Container\Attributes\Singleton;

/**
 * The Scan screen's working state, held for the life of the app runtime.
 *
 * Scan is not the active component while a pushed screen (a find, Settings) is on top, and async results are
 * only delivered to the active component, so the in-flight analyses and live feed live here rather than on
 * one component instance. Leaving the Scan tab resets the source, like the web app (see `Scan::unmount()`).
 */
#[Singleton]
class LiveScanState
{
    public const LiveFeedLimit = 100;

    public const FacingBack = 'back';

    public const FacingFront = 'front';

    public const FacingOff = 'off';

    public ?string $sessionId = null;

    /** The current (or last) session's source: a {@see ScanSource} value. */
    public ?string $source = null;

    public string $sourceLabel = 'Camera ready';

    public string $facing = self::FacingOff;

    public bool $scanning = false;

    /** The running video extraction, whose frames are the only video frames accepted. */
    public ?string $videoRunId = null;

    /** Absolute path of the picked gallery video, removed once it has been played or cancelled. */
    public ?string $pickedMediaPath = null;

    /**
     * Photo imports in progress, keyed by the token naming their import directory (`frames/import-{token}`),
     * so each imported frame is tied to its own pick and session.
     *
     * @var array<string, array{pickedPath: string, sessionId: string}>
     */
    public array $pendingImports = [];

    /** The uploaded photo or latest video frame shown on the stage, relative to the `local` disk. */
    public ?string $stillPreviewPath = null;

    /** When a snapshot requested while the camera was off should be taken (microtime), or 0. */
    public float $snapshotDueAt = 0.0;

    /**
     * Analyses in flight, keyed by async task id.
     *
     * `timedOutAt` marks an analysis the vendor watchdog gave up on; it still finishes and is settled from its FrameRun.
     *
     * @var array<string, array{sessionId: string, frameRunId: string, dispatchedAt: int, timedOutAt?: int}>
     */
    public array $pending = [];

    /** @var list<string> Item ids in the live feed, newest first. */
    public array $liveItemIds = [];

    /**
     * Item ids waiting to be revealed in the feed, oldest first (the web app's stream queue).
     *
     * @var list<string>
     */
    public array $revealQueue = [];

    public float $lastRevealAt = 0.0;

    public float $lastReconcileAt = 0.0;

    public float $flashAt = 0.0;

    /** Whether the app-lifetime listener that drains video runs during pushes is registered. */
    public bool $drainsVideoGlobally = false;

    public ?string $error = null;

    public bool $errorNeedsApiKey = false;

    /**
     * @param  list<string>  $itemIds
     */
    public function enqueueReveal(array $itemIds): void
    {
        foreach ($itemIds as $itemId) {
            $this->revealQueue[] = $itemId;
        }
    }

    /**
     * Move the next queued item to the top of the feed, at most once per interval.
     *
     * Mirrors the web app's 500ms stream reveal: a repeat item jumps back to the top.
     */
    public function revealDue(float $now, float $intervalSeconds): bool
    {
        if ($this->revealQueue === [] || $now - $this->lastRevealAt < $intervalSeconds) {
            return false;
        }

        $itemId = array_shift($this->revealQueue);
        $this->liveItemIds = array_slice(
            [$itemId, ...array_values(array_filter($this->liveItemIds, fn (string $id): bool => $id !== $itemId))],
            0,
            self::LiveFeedLimit,
        );
        $this->lastRevealAt = $now;

        return true;
    }

    public function inFlight(): int
    {
        return count($this->pending);
    }

    public function fail(string $message, bool $needsApiKey = false): void
    {
        $this->error = $message;
        $this->errorNeedsApiKey = $needsApiKey;
    }

    public function clearError(): void
    {
        $this->error = null;
        $this->errorNeedsApiKey = false;
    }
}
