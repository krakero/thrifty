<?php

use App\Models\FrameRun;
use App\Models\Item;
use App\NativeComponents\AgentActivity;
use App\NativeComponents\History;
use App\NativeComponents\ItemDetail;
use Native\Mobile\Testing\Native;

it('renders history as a tab', function () {
    Item::factory()->create(['name' => 'Routed radio']);

    Native::visit('/history')
        ->assertScreen(History::class)
        ->assertHasTab('Scan')
        ->assertTabActive('History')
        ->assertSee('Routed radio');
});

it('renders a find in the stack layout and follows the card from history', function () {
    $item = Item::factory()->create(['name' => 'Routed lamp', 'estimated_low_cents' => null, 'estimated_high_cents' => null]);

    Native::visit("/finds/{$item->id}")
        ->assertScreen(ItemDetail::class)
        ->assertNavTitle('Find')
        ->assertNavBarVisible()
        ->assertSee('Routed lamp')
        ->assertSee('Value pending');

    Native::visit('/history')
        ->tap("item-card-{$item->id}")
        ->followNavigation()
        ->assertScreen(ItemDetail::class)
        ->assertSet('itemId', $item->id)
        ->assertSet('from', 'history');
});

it('renders agent activity from the find', function () {
    $item = Item::factory()->for(FrameRun::factory()->state(['model' => 'gpt-5.6-luna']))->create();

    Native::visit("/finds/{$item->id}", ['from' => 'scan'])
        ->tap('agent-activity')
        ->followNavigation()
        ->assertScreen(AgentActivity::class)
        ->assertNavTitle('Agent activity')
        ->assertSet('from', 'scan')
        ->assertSee('gpt-5.6-luna');

    Native::visit("/finds/{$item->id}/activity")
        ->assertScreen(AgentActivity::class)
        ->assertSee('Agent instructions');
});
