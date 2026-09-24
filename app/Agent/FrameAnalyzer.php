<?php

namespace App\Agent;

use App\Agent\Exceptions\AnalysisFailed;
use App\Agent\Exceptions\MissingApiKey;
use App\Enums\FrameRunStatus;
use App\Enums\ScanSource;
use App\Models\AppStat;
use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ScanSession;
use App\Models\ValuationSource;
use App\Services\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Analyzes one frame end to end: agent run, deduplicated persistence, thumbnails, stats and the audit trail.
 *
 * Port of the Worker's `/api/analyze` handler.
 */
class FrameAnalyzer
{
    /** Candidates whose fingerprints overlap at least this much with a saved find are treated as the same find. */
    public const FingerprintMatchThreshold = 0.72;

    /**
     * Wall-clock budget for one frame, from the frame read through the final write.
     */
    public const BudgetSeconds = 120;

    /**
     * Held back from the agent so crops and the save still fit inside the budget.
     */
    public const PersistReserveSeconds = 15;

    /**
     * How far a run can pass its budget: the write retry stops at the deadline, but the attempt in progress and the
     * failed-run record may each wait out SQLite's busy_timeout (5s).
     */
    public const WorstCaseOverrunSeconds = 15;

    private const WriteAttempts = 6;

    private const DatabaseDateFormat = 'Y-m-d H:i:s.v';

    public function __construct(
        private AppSettings $settings,
        private FrameAgent $agent,
        private Thumbnailer $thumbnailer,
        private ConcurrencyErrorDetector $concurrencyErrors,
    ) {}

    /**
     * @return array{
     *     frameRunId: string,
     *     itemIds: list<string>,
     *     newItemIds: list<string>,
     *     stats: array{framesProcessed: int, itemsIdentified: int, searchesPerformed: int, modelCalls: int},
     *     run: array{latencyMs: int, modelCalls: int, searchesPerformed: int}
     * }
     *
     * @throws AnalysisFailed
     */
    public function analyze(string $scanSessionId, string $framePath, string $capturedAt, string $frameRunId, string $findCriteria): array
    {
        $started = hrtime(true);
        $deadline = Deadline::in(self::BudgetSeconds);

        if (FrameRun::query()->whereKey($frameRunId)->exists()) {
            throw new AnalysisFailed('This frame was already analyzed.');
        }

        $disk = Storage::disk('local');
        $apiKey = $this->settings->openAiApiKey();

        if (! $apiKey) {
            $disk->delete($framePath);

            throw new MissingApiKey;
        }

        $findCriteria = mb_substr($findCriteria, 0, 1000);
        $capturedAt = self::parseCapturedAt($capturedAt);
        $thumbnailPaths = [];

        try {
            $frameBytes = $disk->exists($framePath) ? $disk->get($framePath) : null;

            if (! $frameBytes) {
                throw new AnalysisFailed('The captured frame could not be read.');
            }

            $this->withWriteRetry(fn () => ScanSession::query()->insertOrIgnore([
                'id' => $scanSessionId,
                'source_type' => ScanSource::Camera->value,
                'started_at' => $capturedAt->format(self::DatabaseDateFormat),
            ]), $deadline);

            $result = $this->agent->run(
                $apiKey,
                'data:'.self::mimeType($framePath).';base64,'.base64_encode($frameBytes),
                $scanSessionId,
                $findCriteria,
                $this->settings->ebayCredentials(),
                $deadline->withReserve(self::PersistReserveSeconds),
            );

            $thumbnailPaths = $this->writeThumbnails($frameBytes, $result['analysis']['items'], $frameRunId, $disk);
            $proposedItemIds = array_map(fn (): string => (string) Str::ulid(), $result['analysis']['items']);

            $saved = $this->withWriteRetry(
                fn (): array => $this->persist($result, $thumbnailPaths, $proposedItemIds, $frameRunId, $scanSessionId, $framePath, $capturedAt, $started),
                $deadline,
            );
        } catch (Throwable $exception) {
            $failure = self::userFacingFailure($exception);
            $disk->delete([$framePath, ...array_filter($thumbnailPaths)]);
            $this->recordFailure($failure, $frameRunId, $scanSessionId, $capturedAt, $findCriteria, $started, $deadline);

            throw $failure;
        }

        [$itemIds, $newItemIds, $stats, $replacedThumbnails, $previousFrameRunIds] = $saved;

        $this->releaseUnreferencedFiles(
            [...($itemIds === [] ? [$framePath] : []), ...array_filter($thumbnailPaths)],
            $replacedThumbnails,
            $previousFrameRunIds,
            $disk,
        );

        return [
            'frameRunId' => $frameRunId,
            'itemIds' => array_values(array_unique($itemIds)),
            'newItemIds' => $newItemIds,
            'stats' => [
                'framesProcessed' => $stats->frames_processed,
                'itemsIdentified' => $stats->items_identified,
                'searchesPerformed' => $stats->searches_performed,
                'modelCalls' => $stats->model_calls,
            ],
            'run' => [
                'latencyMs' => self::elapsedMs($started),
                'modelCalls' => $result['modelCalls'],
                'searchesPerformed' => $result['searchesPerformed'],
            ],
        ];
    }

    /**
     * Save the finds, their comparables, the frame run and the stats in one short transaction.
     *
     * Safe to retry: ids and thumbnail files are prepared by the caller, so a rolled-back attempt leaves nothing behind.
     *
     * @param  array{analysis: array{items: list<array<string, mixed>>}, modelCalls: int, searchesPerformed: int, audit: array<string, mixed>}  $result
     * @param  list<?string>  $thumbnailPaths  Written crop per candidate, or null to fall back to the full frame.
     * @param  list<string>  $proposedItemIds  Id per candidate if it turns out to be a new find.
     * @return array{0: list<string>, 1: list<string>, 2: AppStat, 3: list<string>, 4: list<string>} Saved item ids (one per saved candidate),
     *                                                                                               new find ids, the updated totals, thumbnails replaced on repeats, and frame runs repeats moved away from.
     */
    private function persist(array $result, array $thumbnailPaths, array $proposedItemIds, string $frameRunId, string $scanSessionId, string $framePath, CarbonImmutable $capturedAt, int $started): array
    {
        return DB::transaction(function () use ($result, $thumbnailPaths, $proposedItemIds, $frameRunId, $scanSessionId, $framePath, $capturedAt, $started): array {
            $knownFingerprints = Item::query()->select(['id', 'fingerprint'])->latestSeen()->limit(250)->get()
                ->map(fn (Item $item): array => ['id' => $item->id, 'fingerprint' => $item->fingerprint])
                ->all();
            $itemIds = [];
            $newItemIds = [];
            $replacedThumbnails = [];
            $previousFrameRunIds = [];

            foreach ($result['analysis']['items'] as $index => $candidate) {
                $proposedFingerprint = self::proposedFingerprint($candidate);

                if ($proposedFingerprint === '') {
                    continue;
                }

                $fingerprint = self::matchKnownFingerprint($candidate, $proposedFingerprint, $knownFingerprints) ?? $proposedFingerprint;
                $item = Item::query()->where('fingerprint', $fingerprint)->first();
                $duplicate = $item !== null;
                $item ??= (new Item)->forceFill(['id' => $proposedItemIds[$index], 'first_seen_at' => $capturedAt, 'seen_count' => 0]);
                $thumbnailPath = $thumbnailPaths[$index] ?? $framePath;

                if ($duplicate) {
                    $replacedThumbnails[] = $item->thumbnail_path;
                    $previousFrameRunIds[] = $item->frame_run_id;
                }

                $item->forceFill([
                    'scan_session_id' => $duplicate ? $item->scan_session_id : $scanSessionId,
                    'frame_run_id' => $frameRunId,
                    'fingerprint' => $fingerprint,
                    'name' => $candidate['name'],
                    'category' => $candidate['category'],
                    'brand' => $candidate['brand'],
                    'model' => $candidate['model'],
                    'description' => $candidate['description'],
                    'condition' => $candidate['condition'],
                    'confidence' => $candidate['confidence'],
                    'observed_price_cents' => $candidate['observedPriceCents'],
                    'currency' => $candidate['currency'],
                    'estimated_low_cents' => $candidate['estimatedLowCents'],
                    'estimated_high_cents' => $candidate['estimatedHighCents'],
                    'retail_price_cents' => $candidate['retailPriceCents'],
                    'active_price_cents' => $candidate['activePriceCents'],
                    'sold_price_cents' => $candidate['soldPriceCents'],
                    'value_summary' => $candidate['valueSummary'],
                    'thumbnail_path' => $thumbnailPath,
                    'box_x_min' => $candidate['boundingBox']['xMin'],
                    'box_y_min' => $candidate['boundingBox']['yMin'],
                    'box_x_max' => $candidate['boundingBox']['xMax'],
                    'box_y_max' => $candidate['boundingBox']['yMax'],
                    'raw_json' => $candidate,
                    'last_seen_at' => $capturedAt,
                    'seen_count' => $item->seen_count + 1,
                ])->save();

                if (! $duplicate) {
                    $knownFingerprints[] = ['id' => $item->id, 'fingerprint' => $fingerprint];
                    $newItemIds[] = $item->id;
                }

                foreach ($candidate['comparables'] as $comparable) {
                    ValuationSource::query()->create([
                        'item_id' => $item->id,
                        'source_type' => $comparable['type'],
                        'title' => $comparable['title'],
                        'url' => $comparable['url'],
                        'price_cents' => $comparable['priceCents'],
                        'currency' => $comparable['currency'],
                        'captured_at' => $capturedAt,
                    ]);
                }

                $itemIds[] = $item->id;
            }

            FrameRun::query()->forceCreate([
                'id' => $frameRunId,
                'scan_session_id' => $scanSessionId,
                'frame_path' => $itemIds === [] ? null : $framePath,
                'captured_at' => $capturedAt,
                'completed_at' => now(),
                'latency_ms' => self::elapsedMs($started),
                'item_count' => count($itemIds),
                'model_calls' => $result['modelCalls'],
                'searches_performed' => $result['searchesPerformed'],
                'model' => FrameAgent::Model,
                'instructions' => $result['audit']['instructions'],
                'input_json' => $result['audit']['input'],
                'events_json' => $result['audit']['events'],
                'raw_responses_json' => $result['audit']['rawResponses'],
                'output_json' => $result['audit']['output'],
                'usage_json' => $result['audit']['usage'],
                'status' => FrameRunStatus::Completed,
                'error' => null,
            ]);

            AppStat::record(1, count($itemIds), $result['searchesPerformed'], $result['modelCalls']);

            return [$itemIds, $newItemIds, AppStat::current(), array_values(array_diff($replacedThumbnails, [$framePath])), array_values(array_filter(array_diff($previousFrameRunIds, [$frameRunId])))];
        });
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private static function proposedFingerprint(array $candidate): string
    {
        return Normalize::normalizeFingerprint($candidate['fingerprint'] ?: $candidate['name']);
    }

    /**
     * The agent's `previousMatchId` wins; otherwise the closest saved fingerprint above the overlap threshold.
     *
     * @param  array<string, mixed>  $candidate
     * @param  list<array{id: string, fingerprint: string}>  $knownFingerprints
     */
    private static function matchKnownFingerprint(array $candidate, string $proposedFingerprint, array $knownFingerprints): ?string
    {
        if ($candidate['previousMatchId']) {
            foreach ($knownFingerprints as $known) {
                if ($known['id'] === $candidate['previousMatchId']) {
                    return $known['fingerprint'];
                }
            }
        }

        $bestScore = 0.0;
        $bestFingerprint = null;

        foreach ($knownFingerprints as $known) {
            $score = Normalize::fingerprintSimilarity($proposedFingerprint, $known['fingerprint']);

            if ($score >= self::FingerprintMatchThreshold && $score > $bestScore) {
                $bestScore = $score;
                $bestFingerprint = $known['fingerprint'];
            }
        }

        return $bestFingerprint;
    }

    /**
     * Mirror the Worker's failure path (worker/index.ts:350-377): a failed frame run with the audit input and one more
     * processed frame. Like the web, model calls and searches are recorded as 0 even when requests were already sent,
     * and events, raw responses and usage are left empty.
     */
    private function recordFailure(AnalysisFailed $failure, string $frameRunId, string $scanSessionId, CarbonImmutable $capturedAt, string $findCriteria, int $started, Deadline $deadline): void
    {
        try {
            $this->withWriteRetry(fn () => DB::transaction(function () use ($failure, $frameRunId, $scanSessionId, $capturedAt, $findCriteria, $started): void {
                ScanSession::query()->insertOrIgnore([
                    'id' => $scanSessionId,
                    'source_type' => ScanSource::Camera->value,
                    'started_at' => $capturedAt->format(self::DatabaseDateFormat),
                ]);

                FrameRun::query()->forceCreate([
                    'id' => $frameRunId,
                    'scan_session_id' => $scanSessionId,
                    'frame_path' => null,
                    'captured_at' => $capturedAt,
                    'completed_at' => now(),
                    'latency_ms' => self::elapsedMs($started),
                    'item_count' => 0,
                    'model_calls' => 0,
                    'searches_performed' => 0,
                    'model' => FrameAgent::Model,
                    'instructions' => FrameAgent::Instructions,
                    'input_json' => FrameAgent::auditInput($findCriteria),
                    'events_json' => [],
                    'raw_responses_json' => [],
                    'output_json' => null,
                    'usage_json' => null,
                    'status' => FrameRunStatus::Failed,
                    'error' => mb_substr($failure->getMessage(), 0, 1000),
                ]);

                AppStat::record(1, 0, 0, 0);
            }), $deadline);
        } catch (Throwable $recordingFailure) {
            report($recordingFailure);
        }
    }

    /**
     * An {@see AnalysisFailed} whose message is safe to show: database and filesystem errors never leak SQL or paths.
     */
    private static function userFacingFailure(Throwable $exception): AnalysisFailed
    {
        if ($exception instanceof AnalysisFailed) {
            return $exception;
        }

        report($exception);

        return new AnalysisFailed("Couldn't save this frame's finds. Try again.", 0, $exception);
    }

    /**
     * Crop every find once, before the write transaction, into files named after this frame run.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @return list<?string> Disk path per candidate, or null when no crop could be made.
     */
    private function writeThumbnails(string $frameBytes, array $candidates, string $frameRunId, Filesystem $disk): array
    {
        $persistable = array_filter($candidates, fn (array $candidate): bool => self::proposedFingerprint($candidate) !== '');
        $crops = array_fill(0, count($candidates), null);

        foreach ($this->thumbnailer->cropAll($frameBytes, array_values(array_column($persistable, 'boundingBox'))) as $position => $bytes) {
            $crops[array_keys($persistable)[$position]] = $bytes;
        }

        $paths = [];

        foreach ($crops as $index => $bytes) {
            $path = $bytes === null ? null : "thumbs/{$frameRunId}-{$index}.jpg";

            if ($path !== null) {
                $disk->put($path, $bytes);
            }

            $paths[] = $path;
        }

        return $paths;
    }

    /**
     * After a successful save, delete files nothing references any more: the frame when nothing was found, thumbnails
     * replaced by a repeat, and the frames of earlier runs whose finds have all moved to this one.
     *
     * @param  list<string>  $frames
     * @param  list<string>  $thumbnails
     * @param  list<string>  $frameRunIds
     */
    private function releaseUnreferencedFiles(array $frames, array $thumbnails, array $frameRunIds, Filesystem $disk): void
    {
        try {
            $orphanedRuns = FrameRun::query()
                ->whereIn('id', array_unique($frameRunIds))
                ->whereNotNull('frame_path')
                ->whereNotExists(fn ($query) => $query->select(DB::raw(1))->from('items')->whereColumn('items.frame_run_id', 'frame_runs.id'))
                ->get(['id', 'frame_path']);

            if ($orphanedRuns->isNotEmpty()) {
                $this->withWriteRetry(fn () => FrameRun::query()->whereIn('id', $orphanedRuns->pluck('id'))->update(['frame_path' => null]));
            }

            $candidates = array_values(array_unique([...$frames, ...$thumbnails, ...$orphanedRuns->pluck('frame_path')->all()]));

            if ($candidates === []) {
                return;
            }

            $referenced = Item::query()->whereIn('thumbnail_path', $candidates)->pluck('thumbnail_path')
                ->merge(FrameRun::query()->whereIn('frame_path', $candidates)->pluck('frame_path'))
                ->all();

            $disk->delete(array_values(array_diff($candidates, $referenced)));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Run a write, retrying with backoff while another analysis thread holds the SQLite write lock, but never past the
     * frame's deadline (the first attempt always runs).
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function withWriteRetry(callable $write, ?Deadline $deadline = null): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $write();
            } catch (Throwable $exception) {
                $retryable = $attempt < self::WriteAttempts
                    && ! $deadline?->expired()
                    && $this->concurrencyErrors->causedByConcurrencyError($exception);

                if (! $retryable) {
                    throw $exception;
                }

                usleep(random_int(50, 150) * 1000 * $attempt);
            }
        }
    }

    private static function parseCapturedAt(string $capturedAt): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($capturedAt)->utc();
        } catch (Throwable) {
            return CarbonImmutable::now('UTC');
        }
    }

    private static function mimeType(string $framePath): string
    {
        return match (strtolower(pathinfo($framePath, PATHINFO_EXTENSION))) {
            'webp' => 'image/webp',
            'png' => 'image/png',
            'heic' => 'image/heic',
            default => 'image/jpeg',
        };
    }

    private static function elapsedMs(int $started): int
    {
        return (int) round((hrtime(true) - $started) / 1_000_000);
    }
}
