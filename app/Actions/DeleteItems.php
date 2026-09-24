<?php

namespace App\Actions;

use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ValuationSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes finds along with their valuation sources and the images nothing references any more.
 *
 * A frame run whose finds are all gone keeps its row (for stats and the audit trail) but loses its frame:
 * `frame_path` is cleared and the file deleted, like the web app deleting frame objects with their finds.
 * An image file is only removed once no item thumbnail and no frame run points at it.
 */
class DeleteItems
{
    /** Files younger than this may belong to an analysis that is still running or saving, so sweeps leave them. */
    public const ORPHAN_SWEEP_MIN_AGE_SECONDS = 600;

    /** The thumbnail sweep after a single delete runs at most this often per PHP context. */
    public const THUMBNAIL_SWEEP_INTERVAL_SECONDS = 60;

    private static ?int $lastThumbnailSweepAt = null;

    public function one(Item $item): void
    {
        $paths = DB::transaction(function () use ($item): array {
            $stored = Item::query()->whereKey($item->getKey())->first(['id', 'thumbnail_path', 'frame_run_id']);

            if ($stored === null) {
                return [];
            }

            ValuationSource::query()->where('item_id', $stored->id)->delete();
            $stored->delete();

            return [$stored->thumbnail_path, ...$this->releaseFrames(array_filter([$stored->frame_run_id]))];
        });

        $this->deleteUnreferencedFiles($paths);

        if (self::$lastThumbnailSweepAt === null || now()->getTimestamp() - self::$lastThumbnailSweepAt >= self::THUMBNAIL_SWEEP_INTERVAL_SECONDS) {
            $this->sweepOrphans('thumbs');
        }
    }

    /**
     * Deletes every find, clears every run's frame (including runs whose release failed or predates frame cleanup) and
     * sweeps the image folders for files nothing references. Recent files are left for analyses still in flight.
     */
    public function all(): void
    {
        $paths = DB::transaction(function (): array {
            $thumbnails = Item::query()->distinct()->pluck('thumbnail_path')->all();

            ValuationSource::query()->delete();
            Item::query()->delete();

            $frames = FrameRun::query()->whereNotNull('frame_path')->distinct()->pluck('frame_path')->all();
            FrameRun::query()->whereNotNull('frame_path')->update(['frame_path' => null]);

            return [...$thumbnails, ...$frames];
        });

        $this->deleteUnreferencedFiles($paths);
        $this->sweepOrphans('thumbs');
        $this->sweepOrphans('frames');
    }

    /**
     * Forget when the thumbnail sweep last ran (tests).
     */
    public static function resetSweepThrottle(): void
    {
        self::$lastThumbnailSweepAt = null;
    }

    /**
     * Clear `frame_path` on the given runs that no item references any more, returning the released paths.
     *
     * @param  array<int, string>  $frameRunIds
     * @return list<string>
     */
    private function releaseFrames(array $frameRunIds): array
    {
        $released = [];

        foreach (array_chunk(array_values(array_unique($frameRunIds)), 500) as $chunk) {
            $stillUsed = Item::query()->whereIn('frame_run_id', $chunk)->distinct()->pluck('frame_run_id')->all();

            $runs = FrameRun::query()
                ->whereIn('id', array_diff($chunk, $stillUsed))
                ->whereNotNull('frame_path')
                ->get(['id', 'frame_path']);

            if ($runs->isEmpty()) {
                continue;
            }

            FrameRun::query()->whereKey($runs->modelKeys())->update(['frame_path' => null]);
            array_push($released, ...$runs->pluck('frame_path')->all());
        }

        return $released;
    }

    /**
     * @param  array<int, string|null>  $paths  Paths relative to the `local` disk.
     */
    private function deleteUnreferencedFiles(array $paths): void
    {
        foreach (array_chunk(array_values(array_unique(array_filter($paths))), 500) as $chunk) {
            $referenced = Item::query()->whereIn('thumbnail_path', $chunk)->pluck('thumbnail_path')
                ->merge(FrameRun::query()->whereIn('frame_path', $chunk)->pluck('frame_path'))
                ->all();

            $orphaned = array_values(array_diff($chunk, $referenced));

            if ($orphaned !== []) {
                Storage::disk('local')->delete($orphaned);
            }
        }
    }

    /**
     * Remove files in a folder that no item or frame run points at, such as a thumbnail written by an analysis that
     * finished saving just as its find was deleted. Recent files are skipped because an analysis may still be using
     * them, and files that vanish mid-sweep (the analyzer cleaning up) are ignored.
     */
    private function sweepOrphans(string $directory): void
    {
        if ($directory === 'thumbs') {
            self::$lastThumbnailSweepAt = now()->getTimestamp();
        }

        $disk = Storage::disk('local');
        $cutoff = now()->getTimestamp() - self::ORPHAN_SWEEP_MIN_AGE_SECONDS;

        try {
            $files = $disk->files($directory);
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $candidates = array_values(array_filter($files, function (string $path) use ($disk, $cutoff): bool {
            try {
                return $disk->lastModified($path) <= $cutoff;
            } catch (Throwable) {
                return false;
            }
        }));

        try {
            $this->deleteUnreferencedFiles($candidates);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
