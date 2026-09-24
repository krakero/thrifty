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
     * The watchdog starts when the task is queued, and the iOS pool assigns its four slots round-robin, so a task can
     * wait behind up to three others that each use their full {@see FrameAnalyzer::BudgetSeconds} budget
     * (plus {@see FrameAnalyzer::WorstCaseOverrunSeconds}).
     */
    public const TimeoutSeconds = 600;

    public static function dispatch(mixed ...$args): PendingAsyncTask
    {
        return parent::dispatch(...$args)->timeout(self::TimeoutSeconds);
    }

    /**
     * @param  string  $framePath  Frame JPEG, relative to the `local` disk.
     * @param  string  $capturedAt  ISO-8601 capture time.
     * @param  string  $frameRunId  Pre-generated ULID; the FrameRun is saved with this id on success and on failure.
     * @param  string  $findCriteria  The find criteria as they were when the frame was captured.
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
    public function handle(string $scanSessionId, string $framePath, string $capturedAt, string $frameRunId, string $findCriteria): array
    {
        return app(FrameAnalyzer::class)->analyze($scanSessionId, $framePath, $capturedAt, $frameRunId, $findCriteria);
    }
}
