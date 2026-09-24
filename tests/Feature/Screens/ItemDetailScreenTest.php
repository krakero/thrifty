<?php

use App\Enums\ValuationSourceType;
use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ValuationSource;
use App\NativeComponents\AgentActivity;
use App\NativeComponents\ItemDetail;
use App\NativeComponents\Layouts\StackLayout;
use App\NativeComponents\Scan;
use App\Scanning\LiveScanState;
use App\Support\LocalTime;
use App\Support\PriceText;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    Storage::fake('local');
    LocalTime::useTimezone('America/New_York');
});

afterEach(function () {
    LocalTime::useTimezone(null);
});

/**
 * A frame run whose 4×3 JPEG is on disk, with two finds in it.
 *
 * @return array{0: FrameRun, 1: Item, 2: Item}
 */
function frameWithTwoFinds(): array
{
    $image = imagecreatetruecolor(4, 3);
    ob_start();
    imagejpeg($image);
    Storage::disk('local')->put('frames/frame.jpg', ob_get_clean());

    $run = FrameRun::factory()->create(['frame_path' => 'frames/frame.jpg']);
    $lamp = Item::factory()->for($run)->create([
        'name' => 'Brass lamp', 'category' => 'Lighting', 'confidence' => 0.91, 'seen_count' => 3,
        'brand' => 'Stiffel', 'model' => null, 'condition' => 'Good',
        'estimated_low_cents' => 2000, 'estimated_high_cents' => 3500, 'retail_price_cents' => 12000,
        'observed_price_cents' => 500, 'active_price_cents' => 4200, 'sold_price_cents' => 3000,
        'value_summary' => 'Sells steadily. See [a guide](https://example.com/guide).',
        'box_x_min' => 100, 'box_y_min' => 125, 'box_x_max' => 600, 'box_y_max' => 800,
        'thumbnail_path' => 'thumbs/lamp.jpg',
        'first_seen_at' => '2026-09-16 12:00:00',
        'last_seen_at' => '2026-09-16 16:30:00',
    ]);
    $chair = Item::factory()->for($run)->create([
        'name' => 'Oak chair', 'thumbnail_path' => 'thumbs/chair.jpg', 'last_seen_at' => '2026-09-16 16:29:00',
        'box_x_min' => 700, 'box_y_min' => 0, 'box_x_max' => 1000, 'box_y_max' => 400,
    ]);

    return [$run, $lamp, $chair];
}

function itemDetail(Item|string $item, string $from = 'history')
{
    return Native::test(ItemDetail::class, ['id' => $item instanceof Item ? $item->id : $item], ['from' => $from], StackLayout::class);
}

it('shows the find, its frame mates, prices and facts', function () {
    [, $lamp] = frameWithTwoFinds();

    itemDetail($lamp)
        ->assertNavTitle('Find')
        ->assertSee('2 items found in this frame')
        ->assertSee('Oak chair')
        ->assertSee('Lighting · 91% confidence')
        ->assertSee('Brass lamp')
        ->assertSee(PriceText::keepTogether('Seen 3×'))
        ->assertSee(PriceText::keepTogether('$20–$35'))
        ->assertSee('$120')
        ->assertSee('$42')
        ->assertSee('$30')
        ->assertSee('Sells steadily. See a guide.')
        ->assertSee('Stiffel')
        ->assertSee('3 times')
        ->assertSee('Sep 16, 2026, 8:00 AM')
        ->assertSee('Sep 16, 2026, 12:30 PM')
        ->assertSee('Agent activity');
});

/**
 * The flex-grow sequence of a box layer: the vertical children, then the band's children, with the box marked.
 *
 * @return array{vertical: list<float|string>, horizontal: list<float|string>}
 */
function boxLayerGrows(array $tree, string $itemId): array
{
    $find = function (array $node) use (&$find, $itemId): ?array {
        if (($node['ref'] ?? null) === "box-layer-{$itemId}") {
            return $node;
        }

        foreach ($node['children'] ?? [] as $child) {
            if (($found = $find($child)) !== null) {
                return $found;
            }
        }

        return null;
    };
    $describe = fn (array $node): float|string => ($node['children'][0]['ref'] ?? null) === "box-{$itemId}"
        ? 'box:'.$node['layout']['flex_grow']
        : ($node['type'] === 'row' ? 'band:'.$node['layout']['flex_grow'] : $node['layout']['flex_grow']);

    $layer = $find($tree);
    $band = collect($layer['children'])->firstWhere('type', 'row');

    return [
        'vertical' => array_map($describe, $layer['children']),
        'horizontal' => array_map($describe, $band['children']),
    ];
}

it('draws a box for every find in the frame, split by flex-grow ratios', function () {
    [, $lamp, $chair] = frameWithTwoFinds();

    $screen = itemDetail($lamp);

    expect(boxLayerGrows($screen->tree(), $lamp->id))->toBe([
        'vertical' => [125.0, 'band:675', 200.0],
        'horizontal' => [100.0, 'box:500', 400.0],
    ]);

    $screen->assertElement('pressable', fn (array $node) => ($node['ref'] ?? null) === "box-{$chair->id}")
        ->assertElement('column', fn (array $node) => ($node['layout']['flex_grow'] ?? null) === 500.0
            && ($node['children'][0]['style']['border_width'] ?? null) === 4.0
            && ($node['children'][0]['ref'] ?? null) === "box-{$lamp->id}");
});

it('leaves out zero-size spacers for boxes touching the frame edge', function () {
    [, $lamp, $chair] = frameWithTwoFinds();

    expect(boxLayerGrows(itemDetail($lamp)->tree(), $chair->id))->toBe([
        'vertical' => ['band:400', 600.0],
        'horizontal' => [700.0, 'box:300'],
    ]);
});

it('draws a zero-size box as a sliver instead of a zero-grow slot', function () {
    [$run, $lamp] = frameWithTwoFinds();
    $point = Item::factory()->for($run)->create(['box_x_min' => 1000, 'box_y_min' => 0, 'box_x_max' => 1000, 'box_y_max' => 0]);

    expect(boxLayerGrows(itemDetail($lamp)->tree(), $point->id))->toBe([
        'vertical' => ['band:1', 999.0],
        'horizontal' => [999.0, 'box:1'],
    ]);
});

it('clamps and orders box edges', function () {
    expect(ItemDetail::boxRatios(['xMin' => 0, 'yMin' => 500, 'xMax' => 0, 'yMax' => 500]))
        ->toBe(['top' => 500, 'height' => 1, 'bottom' => 499, 'left' => 0, 'width' => 1, 'right' => 999])
        ->and(ItemDetail::boxRatios(['xMin' => 900, 'yMin' => -20, 'xMax' => 1200, 'yMax' => 500]))
        ->toBe(['top' => 0, 'height' => 500, 'bottom' => 500, 'left' => 900, 'width' => 100, 'right' => 0])
        ->and(ItemDetail::boxRatios(['xMin' => 600, 'yMin' => 800, 'xMax' => 100, 'yMax' => 200]))
        ->toBe(['top' => 200, 'height' => 600, 'bottom' => 200, 'left' => 100, 'width' => 500, 'right' => 400]);
});

it('switches the selected find from a box or the frame list', function () {
    [, $lamp, $chair] = frameWithTwoFinds();
    $stranger = Item::factory()->create();

    itemDetail($lamp)
        ->tap("box-{$chair->id}")
        ->assertSet('itemId', $chair->id)
        ->tap("frame-item-{$lamp->id}")
        ->assertSet('itemId', $lamp->id)
        ->call('selectItem', $stranger->id)
        ->assertSet('itemId', $lamp->id);
});

it('falls back to the thumbnail without boxes when the frame is missing', function () {
    $item = Item::factory()->create(['name' => 'Lonely vase']);

    itemDetail($item)
        ->assertSee('1 item found in this frame')
        ->assertSee('Lonely vase')
        ->assertMissingElement('pressable', fn (array $node) => ($node['ref'] ?? null) === "box-{$item->id}");
});

it('lists comparables with their type and opens linked ones in the in-app browser', function () {
    [, $lamp] = frameWithTwoFinds();
    ValuationSource::factory()->for($lamp)->create([
        'source_type' => ValuationSourceType::Sold, 'title' => 'Sold on eBay', 'url' => 'https://ebay.com/itm/1', 'price_cents' => 2800, 'captured_at' => now(),
    ]);
    ValuationSource::factory()->for($lamp)->create([
        'source_type' => ValuationSourceType::Retail, 'title' => 'Retail listing', 'url' => null, 'price_cents' => 11900, 'captured_at' => now()->subMinute(),
    ]);

    Native::fakeBridge()->respondTo('Browser.OpenInApp', ['success' => true]);

    itemDetail($lamp)
        ->assertSee('Sold comps & web results')
        ->assertSee('Sold on eBay')
        ->assertSee('$28')
        ->assertSee('Retail listing')
        ->assertSee('a guide')
        ->tap('source-0')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params) => $params['url'] === 'https://ebay.com/itm/1')
        ->assertMissingElement('pressable', fn (array $node) => ($node['ref'] ?? null) === 'source-1')
        ->assertNativeCalledTimes('Browser.OpenInApp', 1)
        ->tap('source-2')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params) => $params['url'] === 'https://example.com/guide');
});

it('shares the find as an image card with every box, the facts and the top comparables', function () {
    [, $lamp, $chair] = frameWithTwoFinds();
    foreach (range(1, 4) as $index) {
        ValuationSource::factory()->for($lamp)->create([
            'source_type' => ValuationSourceType::Sold, 'title' => "Comp {$index}", 'price_cents' => 1000 * $index, 'captured_at' => now()->subMinutes($index),
        ]);
    }

    itemDetail($lamp)
        ->press('share')
        ->assertNativeCalled('ThriftyCamera.ShareFindCard', function (array $card) use ($lamp) {
            $labels = array_column($card['rows'], 'value', 'label');

            return $card['title'] === 'Brass lamp'
                && $card['subtitle'] === 'Lighting · 91% confidence'
                && str_ends_with($card['imagePath'], 'frames/frame.jpg')
                && $card['box'] === ['xMin' => 100, 'yMin' => 125, 'xMax' => 600, 'yMax' => 800]
                && $card['boxes'] === [
                    ['xMin' => 100, 'yMin' => 125, 'xMax' => 600, 'yMax' => 800, 'selected' => true],
                    ['xMin' => 700, 'yMin' => 0, 'xMax' => 1000, 'yMax' => 400, 'selected' => false],
                ]
                && $card['rows'][0] === ['label' => 'Estimated resale', 'value' => '$20–$35']
                && $labels['Brand'] === 'Stiffel'
                && $labels['Model'] === 'Unknown'
                && $labels['Seen'] === '3 times'
                && $labels['Sold · Comp 1'] === '$10'
                && $labels['Sold · Comp 3'] === '$30'
                && ! isset($labels['Sold · Comp 4'])
                && str_starts_with($card['summary'], $lamp->description)
                && str_ends_with($card['summary'], 'Sells steadily. See a guide.');
        });
});

it('shares without boxes when only the thumbnail is left', function () {
    $item = Item::factory()->create(['thumbnail_path' => 'thumbs/x.jpg']);

    itemDetail($item)
        ->press('share')
        ->assertNativeCalled('ThriftyCamera.ShareFindCard', fn (array $card) => $card['box'] === null
            && $card['boxes'] === []
            && str_ends_with($card['imagePath'], 'thumbs/x.jpg'));
});

it('deletes the find after confirmation and goes back', function () {
    [, $lamp, $chair] = frameWithTwoFinds();

    $screen = itemDetail($lamp)
        ->press('confirmDelete')
        ->assertNativeCalled('Dialog.Alert', fn (array $params) => $params['title'] === 'Delete this find?');

    $screen->emitNative(ButtonPressed::class, ['index' => 0, 'label' => 'Cancel', 'id' => "delete-find-{$lamp->id}"]);
    expect(Item::find($lamp->id))->not->toBeNull();

    $screen->press('confirmDelete')
        ->emitNative(ButtonPressed::class, ['index' => 1, 'label' => 'Delete', 'id' => "delete-find-{$lamp->id}"])
        ->assertWentBack();

    expect(Item::find($lamp->id))->toBeNull()
        ->and(Item::find($chair->id))->not->toBeNull();
    Storage::disk('local')->assertExists('frames/frame.jpg');
});

it('opens agent activity keeping the origin', function () {
    [, $lamp] = frameWithTwoFinds();

    itemDetail($lamp, 'scan')
        ->tap('agent-activity')
        ->assertNavigatedTo("/finds/{$lamp->id}/activity?from=scan");
});

it('handles an unknown find', function () {
    itemDetail('missing')
        ->assertSee('Find not found.')
        ->tap('go-back')
        ->assertWentBack();
});

it('shares and deletes using the find as it is now, not as last rendered', function () {
    [$run, $lamp] = frameWithTwoFinds();
    $screen = itemDetail($lamp);

    Storage::disk('local')->put('frames/newer.jpg', 'x');
    $newer = FrameRun::factory()->create(['frame_path' => 'frames/newer.jpg']);
    $lamp->update(['frame_run_id' => $newer->id, 'name' => 'Brass lamp (seen again)']);

    $screen->instance()->share();
    $screen->assertNativeCalled('ThriftyCamera.ShareFindCard', fn (array $card) => $card['title'] === 'Brass lamp (seen again)'
        && str_ends_with($card['imagePath'], 'frames/newer.jpg')
        && count($card['boxes']) === 1);

    $screen->instance()->confirmDelete();
    $screen->assertNativeCalled('Dialog.Alert', fn (array $params) => str_contains($params['message'], 'Brass lamp (seen again)'));
});

it('settles a scan analysis that finishes while a find is on top of Scan, streaming it into the feed with a chime', function () {
    [, $lamp] = frameWithTwoFinds();
    $newFind = Item::factory()->create();
    $state = app(LiveScanState::class);
    $state->pending['task-1'] = ['sessionId' => 'session', 'frameRunId' => 'run', 'dispatchedAt' => time()];

    itemDetail($lamp, 'scan')
        ->emitNative(Scan::FrameAnalyzedEvent, ['id' => 'task-1', 'status' => 'finished', 'result' => ['itemIds' => [$newFind->id]]])
        ->assertNativeCalled('ThriftyCamera.Chime');

    expect($state->pending)->toBe([])
        ->and([...$state->liveItemIds, ...$state->revealQueue])->toContain($newFind->id);
});

it('lets agent activity settle scan analyses too', function () {
    $state = app(LiveScanState::class);
    $state->pending['task-2'] = ['sessionId' => 'session', 'frameRunId' => 'run', 'dispatchedAt' => time()];

    Native::test(AgentActivity::class, ['id' => Item::factory()->create()->id], [], StackLayout::class)
        ->emitNative(Scan::FrameAnalyzedEvent, ['id' => 'task-2', 'status' => 'finished', 'result' => ['itemIds' => []]])
        ->assertNativeNotCalled('ThriftyCamera.Chime');

    expect($state->pending)->toBe([]);
});

it('stacks boxes largest first so smaller boxes stay tappable, even under a selected large box', function () {
    [$run, $lamp, $chair] = frameWithTwoFinds();
    $wholeFrame = Item::factory()->for($run)->create(['box_x_min' => 0, 'box_y_min' => 0, 'box_x_max' => 1000, 'box_y_max' => 1000]);

    $layers = function ($screen): array {
        $refs = [];
        $walk = function (array $node) use (&$walk, &$refs): void {
            if (str_starts_with($node['ref'] ?? '', 'box-layer-')) {
                $refs[] = substr($node['ref'], strlen('box-layer-'));
            }
            foreach ($node['children'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($screen->tree());

        return $refs;
    };

    $expected = [$wholeFrame->id, $lamp->id, $chair->id];

    expect($layers(itemDetail($wholeFrame)))->toBe($expected)
        ->and($layers(itemDetail($chair)))->toBe($expected);

    itemDetail($wholeFrame)
        ->tap("box-{$chair->id}")
        ->assertSet('itemId', $chair->id);
});
