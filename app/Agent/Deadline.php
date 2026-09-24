<?php

namespace App\Agent;

use Carbon\CarbonImmutable;

/**
 * A wall-clock budget shared by every model turn, retry and tool call of one frame's agent run.
 */
final class Deadline
{
    public function __construct(private CarbonImmutable $expiresAt) {}

    public static function in(int $seconds): self
    {
        return new self(CarbonImmutable::now()->addSeconds($seconds));
    }

    public function remainingSeconds(): float
    {
        return max(0.0, CarbonImmutable::now()->diffInMilliseconds($this->expiresAt, false) / 1000);
    }

    public function expired(): bool
    {
        return $this->remainingSeconds() < 1;
    }

    /**
     * A per-request timeout that never runs past the deadline.
     */
    public function cap(float $seconds): float
    {
        return max(1.0, min($seconds, $this->remainingSeconds()));
    }
}
