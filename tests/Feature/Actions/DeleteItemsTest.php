<?php

use App\Actions\DeleteItems;
use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ValuationSource;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

it('deletes one item, its sources and its unreferenced image', function () {
    Storage::disk('local')->put('frames/a.jpg', 'a');
    $item = Item::factory()->create(['thumbnail_path' => 'frames/a.jpg']);
    ValuationSource::factory()->for($item)->count(2)->create();
    $other = Item::factory()->create();

    app(DeleteItems::class)->one($item);

    expect(Item::find($item->id))->toBeNull()
        ->and(Item::find($other->id))->not->toBeNull()
        ->and(ValuationSource::count())->toBe(0);
    Storage::disk('local')->assertMissing('frames/a.jpg');
});

it('keeps an image while another item still uses it', function () {
    Storage::disk('local')->put('frames/shared.jpg', 'x');
    [$first, $second] = Item::factory()->count(2)->create(['thumbnail_path' => 'frames/shared.jpg']);

    app(DeleteItems::class)->one($first);
    Storage::disk('local')->assertExists('frames/shared.jpg');

    app(DeleteItems::class)->one($second);
    Storage::disk('local')->assertMissing('frames/shared.jpg');
});

it('keeps an image while a frame run still references it', function () {
    Storage::disk('local')->put('frames/run.jpg', 'x');
    FrameRun::factory()->create(['frame_path' => 'frames/run.jpg']);
    $item = Item::factory()->create(['thumbnail_path' => 'frames/run.jpg']);

    app(DeleteItems::class)->one($item);

    Storage::disk('local')->assertExists('frames/run.jpg');
});

it('deletes all items and their orphaned images', function () {
    Storage::disk('local')->put('frames/a.jpg', 'a');
    Storage::disk('local')->put('frames/b.jpg', 'b');
    Storage::disk('local')->put('frames/kept.jpg', 'k');
    FrameRun::factory()->create(['frame_path' => 'frames/kept.jpg']);
    Item::factory()->count(2)->create(['thumbnail_path' => 'frames/a.jpg']);
    Item::factory()->create(['thumbnail_path' => 'frames/b.jpg']);
    ValuationSource::factory()->for(Item::factory()->create(['thumbnail_path' => 'frames/kept.jpg']))->create();

    app(DeleteItems::class)->all();

    expect(Item::count())->toBe(0)
        ->and(ValuationSource::count())->toBe(0)
        ->and(FrameRun::count())->toBe(1);
    Storage::disk('local')->assertMissing(['frames/a.jpg', 'frames/b.jpg']);
    Storage::disk('local')->assertExists('frames/kept.jpg');
});
