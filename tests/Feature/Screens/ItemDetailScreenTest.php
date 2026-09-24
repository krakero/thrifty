<?php

use App\Enums\ValuationSourceType;
use App\Models\FrameRun;
use App\Models\Item;
use App\Models\ValuationSource;
use App\NativeComponents\ItemDetail;
use App\NativeComponents\Layouts\StackLayout;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Testing\Native;

beforeEach(function () {
    Storage::fake('local');
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
        'last_seen_at' => now(),
    ]);
    $chair = Item::factory()->for($run)->create(['name' => 'Oak chair', 'last_seen_at' => now()->subMinute()]);

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
        ->assertSee('Seen 3×')
        ->assertSee('$20–$35')
        ->assertSee('$120')
        ->assertSee('$42')
        ->assertSee('$30')
        ->assertSee('Sells steadily. See a guide.')
        ->assertSee('Stiffel')
        ->assertSee('3 times')
        ->assertSee('Agent activity');
});

it('draws a box for every find in the frame, sized as percentages', function () {
    [, $lamp, $chair] = frameWithTwoFinds();

    $screen = itemDetail($lamp)
        ->assertElement('pressable', fn (array $node) => ($node['ref'] ?? null) === "box-{$lamp->id}")
        ->assertElement('pressable', fn (array $node) => ($node['ref'] ?? null) === "box-{$chair->id}");

    expect(json_encode($screen->tree()))->toContain('"12.5%"', '"67.5%"', '"50%"');
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
        ->tap('source-1')
        ->assertNativeCalledTimes('Browser.OpenInApp', 1)
        ->tap('source-2')
        ->assertNativeCalled('Browser.OpenInApp', fn (array $params) => $params['url'] === 'https://example.com/guide');
});

it('shares the find as an image card', function () {
    [, $lamp] = frameWithTwoFinds();

    itemDetail($lamp)
        ->press('share')
        ->assertNativeCalled('ThriftyCamera.ShareFindCard', fn (array $card) => $card['title'] === 'Brass lamp'
            && $card['subtitle'] === 'Lighting · 91% confidence'
            && str_ends_with($card['imagePath'], 'frames/frame.jpg')
            && $card['box'] === ['xMin' => 100, 'yMin' => 125, 'xMax' => 600, 'yMax' => 800]
            && $card['rows'][0] === ['label' => 'Estimated resale', 'value' => '$20–$35']
            && $card['summary'] === 'Sells steadily. See a guide.');
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
