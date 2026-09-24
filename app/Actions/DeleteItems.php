<?php

namespace App\Actions;

use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ValuationSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes finds along with their valuation sources and the images nothing references any more.
 *
 * A frame run whose finds are all gone keeps its row (for stats and the audit trail) but loses its frame:
 * `frame_path` is cleared and the file deleted, like the web app deleting frame objects with their finds.
 * An image file is only removed once no item thumbnail and no frame run points at it.
 */
class DeleteItems
{
    /** Thumbnails younger than this may belong to an analysis that is still saving, so the sweep leaves them. */
    public const ORPHAN_SWEEP_MIN_AGE_SECONDS = 600;

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
        $this->sweepOrphanedThumbnails();
    }

    public function all(): void
    {
        $paths = DB::transaction(function (): array {
            $thumbnails = Item::query()->distinct()->pluck('thumbnail_path')->all();
            $frameRunIds = Item::query()->whereNotNull('frame_run_id')->distinct()->pluck('frame_run_id')->all();

            ValuationSource::query()->delete();
            Item::query()->delete();

            return [...$thumbnails, ...$this->releaseFrames($frameRunIds)];
        });

        $this->deleteUnreferencedFiles($paths);
        $this->sweepOrphanedThumbnails();
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
     * Remove thumbnails no item points at, such as one written by an analysis that finished saving just as its find was
     * deleted. Recent files are skipped because an analysis may still be committing them.
     */
    private function sweepOrphanedThumbnails(): void
    {
        $disk = Storage::disk('local');
        $cutoff = now()->getTimestamp() - self::ORPHAN_SWEEP_MIN_AGE_SECONDS;

        $candidates = array_values(array_filter(
            $disk->files('thumbs'),
            fn (string $path): bool => $disk->lastModified($path) <= $cutoff,
        ));

        $this->deleteUnreferencedFiles($candidates);
    }
}
