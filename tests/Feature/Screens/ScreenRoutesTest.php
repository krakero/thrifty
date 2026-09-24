<?php

use App\Models\FrameRun;
use App\Models\Item;
use App\NativeComponents\AgentActivity;
use App\NativeComponents\History;
use App\NativeComponents\ItemDetail;
use App\NativeComponents\Scan;
use App\NativeComponents\Settings;
use Illuminate\Support\Facades\Storage;
use Native\Mobile\Testing\Native;

/**
 * The tabs root the iOS renderer receives: per-tab NavigationStacks need `nav_title` on every publish, and a
 * pushed screen stays in its tab when `current_uri` is under the tab's URL.
 *
 * @return array<string, mixed>
 */
function tabsRootProps(array $tree): array
{
    expect($tree['type'])->toBe('native_root_tabs');

    return $tree['props'] ?? [];
}

it('hands the launch URL over to the Scan tab', function () {
    Native::visit('/')
        ->assertReplacedWith('/scan')
        ->followNavigation()
        ->assertScreen(Scan::class)
        ->assertTabActive('Scan');
});

it('renders the tab roots in native chrome with their nav bars hidden but present', function (string $uri, string $screen, string $tab) {
    $page = Native::visit($uri)->assertScreen($screen)->assertHasTab('Scan')->assertHasTab('History')->assertTabActive($tab);
    $props = tabsRootProps($page->tree());

    expect($props['nav_title'] ?? '')->not->toBe('')
        ->and($props['hide_nav_bar'] ?? false)->toBeTrue()
        ->and($props['current_uri'] ?? null)->toBe($uri);
})->with([
    ['/scan', Scan::class, 'Scan'],
    ['/history', History::class, 'History'],
]);

it('pushes finds, their activity and settings inside the tab that opened them', function (string $tab, string $label) {
    Storage::fake('local');
    $item = Item::factory()->for(FrameRun::factory()->state(['model' => 'gpt-5.6-luna']))->create(['name' => 'Routed lamp']);

    $find = Native::visit("/{$tab}/finds/{$item->id}")
        ->assertScreen(ItemDetail::class)
        ->assertNavTitle('Find')
        ->assertTabActive($label)
        ->assertSet('from', $tab)
        ->assertSee('Routed lamp');
    expect(tabsRootProps($find->tree())['hide_nav_bar'] ?? false)->toBeFalse();

    Native::visit("/{$tab}/finds/{$item->id}/activity")
        ->assertScreen(AgentActivity::class)
        ->assertNavTitle('Agent activity')
        ->assertTabActive($label)
        ->assertSet('from', $tab)
        ->assertSee('gpt-5.6-luna');

    Native::visit("/{$tab}/settings")
        ->assertScreen(Settings::class)
        ->assertNavTitle('Settings')
        ->assertTabActive($label);

    $find->tap('agent-activity')->assertNavigatedTo("/{$tab}/finds/{$item->id}/activity");
})->with([['history', 'History'], ['scan', 'Scan']]);

it('keeps the History screen and its loaded pages alive under a pushed find', function () {
    Item::factory()->count(130)->create();

    $history = Native::visit('/history')->tap('load-more');
    $instance = $history->instance();
    expect($history->get('itemIds'))->toHaveCount(130);

    $find = Item::query()->latestSeen()->skip(120)->first();

    $detail = $history->tap("item-card-{$find->id}")
        ->assertNavigatedTo("/history/finds/{$find->id}")
        ->followNavigation()
        ->assertScreen(ItemDetail::class)
        ->assertTabActive('History');

    $back = $detail->goBack()->assertScreen(History::class)->assertTabActive('History');

    expect($back->instance())->toBe($instance)
        ->and($back->get('itemIds'))->toHaveCount(130);
});

it('opens settings inside the History tab and comes back to the same screen', function () {
    $history = Native::visit('/history');
    $instance = $history->instance();

    $settings = $history->tap('open-settings')->followNavigation()->assertScreen(Settings::class)->assertTabActive('History');

    expect($settings->goBack()->instance())->toBe($instance);
});
