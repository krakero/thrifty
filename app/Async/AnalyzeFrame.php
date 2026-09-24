<?php

namespace App\Async;

use App\Agent\Exceptions\AnalysisFailed;
use App\Agent\Exceptions\MissingApiKey;
use App\Agent\FrameAnalyzer;
use Native\Mobile\AsyncTask;
use Native\Mobile\PendingAsyncTask;

/**
 * Runs the valuation agent over one captured frame on a background PHP thread and persists the finds.
 *
 * `AnalyzeFrame::dispatch($sessionId, $framePath, $capturedAt)->finished(...)->failed(...)`
 */
class AnalyzeFrame extends AsyncTask
{
    /**
     * Agent runs routinely take 10-60s+ (several model turns plus web research), well past the 60s default.
     */
    public const TimeoutSeconds = 240;

    public static function dispatch(mixed ...$args): PendingAsyncTask
    {
        return parent::dispatch(...$args)->timeout(self::TimeoutSeconds);
    }

    /**
     * @param  string  $framePath  Frame JPEG, relative to the `local` disk.
     * @param  string  $capturedAt  ISO-8601 capture time.
     * @return array{
     *     frameRunId: string,
     *     itemIds: list<string>,
     *     newItemIds: list<string>,
     *     stats: array{framesProcessed: int, itemsIdentified: int, searchesPerformed: int, modelCalls: int},
     *     run: array{latencyMs: int, modelCalls: int, searchesPerformed: int}
     * }
     *
     * @throws MissingApiKey
     * @throws AnalysisFailed
     */
    public function handle(string $scanSessionId, string $framePath, string $capturedAt): array
    {
        return app(FrameAnalyzer::class)->analyze($scanSessionId, $framePath, $capturedAt);
    }
}
