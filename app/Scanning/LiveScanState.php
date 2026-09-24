<?php

namespace App\Scanning;

use App\Enums\ScanSource;
use Illuminate\Container\Attributes\Singleton;

/**
 * The Scan screen's working state, held for the life of the app runtime.
 *
 * The Scan component is unmounted when the user switches tabs and is not the active
 * component while a pushed screen (a find, Settings) is on top, so anything that must
 * outlive one component instance — in-flight analyses, the live feed, the current
 * session — lives here instead of on the component.
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

    /** For video sessions: whether the plugin has finished emitting frames. */
    public bool $videoExtracted = false;

    /** Absolute path of the uploaded photo shown behind the overlay. */
    public ?string $stillPreviewPath = null;

    /**
     * Analyses in flight, keyed by async task id.
     *
     * @var array<string, array{sessionId: string, framePath: string, dispatchedAt: int}>
     */
    public array $pending = [];

    /**
     * Uploaded frames waiting for a free analysis slot.
     *
     * @var list<array{sessionId: string, framePath: string, capturedAt: string}>
     */
    public array $queue = [];

    /** @var list<string> Item ids in the live feed, newest first. */
    public array $liveItemIds = [];

    /**
     * Item ids waiting to be revealed in the feed, oldest first (the web app's stream queue).
     *
     * @var list<string>
     */
    public array $revealQueue = [];

    public float $lastRevealAt = 0.0;

    public float $flashAt = 0.0;

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

    public function hasWorkFor(string $sessionId): bool
    {
        foreach ($this->pending as $entry) {
            if ($entry['sessionId'] === $sessionId) {
                return true;
            }
        }

        foreach ($this->queue as $entry) {
            if ($entry['sessionId'] === $sessionId) {
                return true;
            }
        }

        return false;
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
