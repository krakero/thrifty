<?php

namespace App\Actions;

use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ValuationSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes finds along with their valuation sources and any image files nothing else references.
 *
 * A frame image is kept while any remaining item or frame run still points at it, so the agent
 * activity audit and other finds from the same frame keep their pictures.
 */
class DeleteItems
{
    public function one(Item $item): void
    {
        $path = $item->thumbnail_path;

        DB::transaction(function () use ($item): void {
            ValuationSource::query()->where('item_id', $item->id)->delete();
            $item->delete();
        });

        $this->deleteUnreferencedFiles([$path]);
    }

    public function all(): void
    {
        $paths = Item::query()->distinct()->pluck('thumbnail_path')->all();

        DB::transaction(function (): void {
            ValuationSource::query()->delete();
            Item::query()->delete();
        });

        $this->deleteUnreferencedFiles($paths);
    }

    /**
     * @param  list<string>  $paths  Paths relative to the `local` disk.
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
}
