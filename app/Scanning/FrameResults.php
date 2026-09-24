<?php

namespace App\Scanning;

use App\Agent\Exceptions\MissingApiKey;
use Thrifty\Camera\Facades\ThriftyCamera;

/**
 * Applies a frame analysis outcome to the live scan: settles the in-flight entry, streams finds into the feed
 * and chimes, or surfaces the failure.
 */
class FrameResults
{
    public const RevealIntervalSeconds = 0.5;

    /** The message the vendor watchdog uses when a task outlives its timeout. */
    public const WatchdogMessagePrefix = 'The async task did not complete within';

    public function __construct(private LiveScanState $state) {}

    /**
     * Handle a shared `frame-analyzed` delivery.
     *
     * A watchdog timeout doesn't mean the analysis failed: the task keeps running and saves its FrameRun, but its
     * late completion is discarded. The entry stays pending (flagged) until reconcile finds that run or it expires.
     *
     * @param  array<string, mixed>|null  $result
     */
    public function handle(string $id, string $status, mixed $result = null, ?string $exceptionClass = null, ?string $message = null): void
    {
        if (! isset($this->state->pending[$id])) {
            return;
        }

        if ($status !== 'finished' && $this->isWatchdogTimeout($exceptionClass, $message)) {
            $this->state->pending[$id]['timedOutAt'] = time();

            return;
        }

        unset($this->state->pending[$id]);

        if ($status === 'finished' && is_array($result)) {
            $this->accept(array_values(array_filter($result['itemIds'] ?? [], 'is_string')));

            return;
        }

        $this->state->fail(
            $message ?: 'Frame analysis failed',
            is_a((string) $exceptionClass, MissingApiKey::class, true),
        );
    }

    /**
     * @param  list<string>  $itemIds
     */
    public function accept(array $itemIds, bool $chime = true): void
    {
        if ($itemIds !== []) {
            $this->state->enqueueReveal($itemIds);
            $this->state->revealDue(microtime(true), self::RevealIntervalSeconds);

            if ($chime) {
                ThriftyCamera::chime();
            }
        }

        $this->state->clearError();
    }

    private function isWatchdogTimeout(?string $exceptionClass, ?string $message): bool
    {
        return in_array($exceptionClass, ['RuntimeException', \RuntimeException::class], true)
            && str_starts_with((string) $message, self::WatchdogMessagePrefix);
    }
}
