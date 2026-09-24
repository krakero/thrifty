<?php

use App\Actions\DeleteItems;
use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ValuationSource;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * The production layout: a full frame on the run, and a cropped `thumbs/{id}.jpg` per find.
 *
 * @return array{0: FrameRun, 1: list<Item>}
 */
function frameRunWithFinds(string $frame, int $count): array
{
    Storage::disk('local')->put($frame, 'frame');
    $run = FrameRun::factory()->create(['frame_path' => $frame]);

    $items = collect(range(1, $count))->map(function () use ($run) {
        $item = Item::factory()->for($run)->create();
        $item->update(['thumbnail_path' => "thumbs/{$item->id}.jpg"]);
        Storage::disk('local')->put($item->thumbnail_path, 'thumb');

        return $item;
    })->all();

    return [$run, $items];
}

it('deletes one find, its sources and its thumbnail but keeps a frame others still use', function () {
    [$run, [$first, $second]] = frameRunWithFinds('frames/a.jpg', 2);
    ValuationSource::factory()->for($first)->count(2)->create();

    app(DeleteItems::class)->one($first);

    expect(Item::find($first->id))->toBeNull()
        ->and(Item::find($second->id))->not->toBeNull()
        ->and(ValuationSource::count())->toBe(0)
        ->and($run->fresh()->frame_path)->toBe('frames/a.jpg');
    Storage::disk('local')->assertMissing("thumbs/{$first->id}.jpg");
    Storage::disk('local')->assertExists(['frames/a.jpg', "thumbs/{$second->id}.jpg"]);
});

it('deletes the frame and clears the run once its last find is gone', function () {
    [$run, [$first, $second]] = frameRunWithFinds('frames/a.jpg', 2);

    app(DeleteItems::class)->one($first);
    app(DeleteItems::class)->one($second);

    expect($run->fresh())->not->toBeNull()
        ->and($run->fresh()->frame_path)->toBeNull();
    Storage::disk('local')->assertMissing(['frames/a.jpg', "thumbs/{$second->id}.jpg"]);
});

it('keeps a frame used as a thumbnail when cropping was unavailable', function () {
    Storage::disk('local')->put('frames/shared.jpg', 'x');
    $run = FrameRun::factory()->create(['frame_path' => 'frames/shared.jpg']);
    [$first, $second] = Item::factory()->for($run)->count(2)->create(['thumbnail_path' => 'frames/shared.jpg']);

    app(DeleteItems::class)->one($first);
    Storage::disk('local')->assertExists('frames/shared.jpg');

    app(DeleteItems::class)->one($second);
    Storage::disk('local')->assertMissing('frames/shared.jpg');
});

it('ignores a find that was already deleted', function () {
    [, [$item]] = frameRunWithFinds('frames/a.jpg', 1);
    $stale = Item::find($item->id);

    app(DeleteItems::class)->one($item);
    app(DeleteItems::class)->one($stale);

    expect(Item::count())->toBe(0);
});

it('deletes all finds, their thumbnails and frames, keeping the runs', function () {
    [$runA] = frameRunWithFinds('frames/a.jpg', 2);
    [$runB, [$item]] = frameRunWithFinds('frames/b.jpg', 1);
    ValuationSource::factory()->for($item)->create();
    Storage::disk('local')->put('frames/pending.jpg', 'captured, analysis in flight');

    app(DeleteItems::class)->all();

    expect(Item::count())->toBe(0)
        ->and(ValuationSource::count())->toBe(0)
        ->and(FrameRun::count())->toBe(2)
        ->and($runA->fresh()->frame_path)->toBeNull()
        ->and($runB->fresh()->frame_path)->toBeNull()
        ->and(Storage::disk('local')->files('thumbs'))->toBe([]);
    Storage::disk('local')->assertMissing(['frames/a.jpg', 'frames/b.jpg']);
    Storage::disk('local')->assertExists('frames/pending.jpg');
});

it('sweeps old orphaned thumbnails but leaves recent ones for in-flight saves', function () {
    [, [$item]] = frameRunWithFinds('frames/a.jpg', 1);
    $disk = Storage::disk('local');
    $disk->put('thumbs/old-orphan.jpg', 'x');
    touch($disk->path('thumbs/old-orphan.jpg'), now()->subHour()->getTimestamp());
    $disk->put('thumbs/fresh-orphan.jpg', 'x');
    $disk->put('thumbs/kept.jpg', 'x');
    touch($disk->path('thumbs/kept.jpg'), now()->subHour()->getTimestamp());
    Item::factory()->create(['thumbnail_path' => 'thumbs/kept.jpg']);

    app(DeleteItems::class)->one($item);

    $disk->assertMissing('thumbs/old-orphan.jpg');
    $disk->assertExists(['thumbs/fresh-orphan.jpg', 'thumbs/kept.jpg']);
});
