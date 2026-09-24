<?php

namespace App\Agent;

use App\Agent\Exceptions\AnalysisFailed;
use App\Agent\Exceptions\MissingApiKey;
use App\Services\AppSettings;

/**
 * Analyzes one frame end to end: agent run, persistence, stats and the audit trail.
 */
class FrameAnalyzer
{
    public function __construct(private AppSettings $settings) {}

    /**
     * @return array<string, mixed>
     */
    public function analyze(string $scanSessionId, string $framePath, string $capturedAt): array
    {
        if (! $this->settings->openAiApiKey()) {
            throw new MissingApiKey;
        }

        throw new AnalysisFailed('Frame analysis is not available yet.');
    }
}
