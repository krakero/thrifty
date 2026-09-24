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

    private const WriteAttempts = 6;

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
        $disk = Storage::disk('local');
        $apiKey = $this->settings->openAiApiKey();

        if (! $apiKey) {
            $disk->delete($framePath);

            throw new MissingApiKey;
        }

        $frameBytes = $disk->exists($framePath) ? $disk->get($framePath) : null;

        if (! $frameBytes) {
            throw new AnalysisFailed('The captured frame could not be read.');
        }

        $findCriteria = mb_substr($findCriteria, 0, 1000);
        $capturedAt = self::parseCapturedAt($capturedAt);

        $this->withWriteRetry(fn () => ScanSession::query()->insertOrIgnore([
            'id' => $scanSessionId,
            'source_type' => ScanSource::Camera->value,
            'started_at' => $capturedAt,
        ]));

        try {
            $result = $this->agent->run(
                $apiKey,
                'data:'.self::mimeType($framePath).';base64,'.base64_encode($frameBytes),
                $scanSessionId,
                $findCriteria,
                $this->settings->ebayCredentials(),
            );
        } catch (Throwable $exception) {
            $this->recordFailure($exception, $frameRunId, $scanSessionId, $framePath, $capturedAt, $findCriteria, $started, $disk);

            throw $exception instanceof AnalysisFailed ? $exception : new AnalysisFailed($exception->getMessage() ?: 'Frame analysis failed', 0, $exception);
        }

        $thumbnails = array_map(
            fn (array $candidate): ?string => $this->thumbnailer->crop($frameBytes, $candidate['boundingBox']),
            $result['analysis']['items'],
        );

        [$itemIds, $newItemIds] = $this->withWriteRetry(
            fn (): array => $this->persist($result, $thumbnails, $frameRunId, $scanSessionId, $framePath, $capturedAt, $started, $disk),
        );

        if ($itemIds === []) {
            $disk->delete($framePath);
        }

        $stats = AppStat::current();

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
     * @param  array{analysis: array{items: list<array<string, mixed>>}, modelCalls: int, searchesPerformed: int, audit: array<string, mixed>}  $result
     * @param  list<?string>  $thumbnails  Cropped JPEG bytes per candidate.
     * @return array{0: list<string>, 1: list<string>} All saved item ids (one per saved candidate) and the ids of new finds.
     */
    private function persist(array $result, array $thumbnails, string $frameRunId, string $scanSessionId, string $framePath, CarbonImmutable $capturedAt, int $started, Filesystem $disk): array
    {
        return DB::transaction(function () use ($result, $thumbnails, $frameRunId, $scanSessionId, $framePath, $capturedAt, $started, $disk): array {
            $knownFingerprints = Item::query()->select(['id', 'fingerprint'])->latestSeen()->limit(250)->get()
                ->map(fn (Item $item): array => ['id' => $item->id, 'fingerprint' => $item->fingerprint])
                ->all();
            $itemIds = [];
            $newItemIds = [];

            foreach ($result['analysis']['items'] as $index => $candidate) {
                $proposedFingerprint = Normalize::normalizeFingerprint($candidate['fingerprint'] ?: $candidate['name']);

                if ($proposedFingerprint === '') {
                    continue;
                }

                $fingerprint = self::matchKnownFingerprint($candidate, $proposedFingerprint, $knownFingerprints) ?? $proposedFingerprint;
                $item = Item::query()->where('fingerprint', $fingerprint)->first();
                $duplicate = $item !== null;
                $item ??= (new Item)->forceFill(['id' => (string) Str::ulid(), 'first_seen_at' => $capturedAt, 'seen_count' => 0]);

                $thumbnailPath = $framePath;

                if ($thumbnails[$index] !== null) {
                    $thumbnailPath = "thumbs/{$item->id}.jpg";
                    $disk->put($thumbnailPath, $thumbnails[$index]);
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

            return [$itemIds, $newItemIds];
        });
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

    private function recordFailure(Throwable $exception, string $frameRunId, string $scanSessionId, string $framePath, CarbonImmutable $capturedAt, string $findCriteria, int $started, Filesystem $disk): void
    {
        $disk->delete($framePath);

        try {
            $this->withWriteRetry(fn () => DB::transaction(function () use ($exception, $frameRunId, $scanSessionId, $capturedAt, $findCriteria, $started): void {
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
                    'error' => mb_substr($exception->getMessage() ?: 'Frame analysis failed', 0, 1000),
                ]);

                AppStat::record(1, 0, 0, 0);
            }));
        } catch (Throwable $recordingFailure) {
            report($recordingFailure);
        }
    }

    /**
     * Run a write, retrying with backoff while another analysis thread holds the SQLite write lock.
     *
     * @template T
     *
     * @param  callable(): T  $write
     * @return T
     */
    private function withWriteRetry(callable $write): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $write();
            } catch (Throwable $exception) {
                if ($attempt >= self::WriteAttempts || ! $this->concurrencyErrors->causedByConcurrencyError($exception)) {
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
