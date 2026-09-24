<?php

use App\Models\AppStat;
use App\Models\Item;
use App\NativeComponents\History;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Scan;
use App\Scanning\LiveScanState;
use Illuminate\Support\Facades\DB;
use Native\Mobile\Testing\Native;

it('shows the heading, stats and saved finds newest first', function () {
    AppStat::current()->update(['frames_processed' => 1234, 'items_identified' => 5, 'searches_performed' => 7, 'model_calls' => 9]);
    Item::factory()->create(['name' => 'Older lamp', 'last_seen_at' => now()->subDay()]);
    Item::factory()->create(['name' => 'Newer radio', 'last_seen_at' => now()]);

    $screen = Native::test(History::class, layout: TabsLayout::class)
        ->assertSee('All-time finds')
        ->assertSee('History')
        ->assertSee('1,234')
        ->assertSee('Searches')
        ->assertSee('Older lamp')
        ->assertSee('Newer radio');

    expect($screen->get('itemIds'))->toHaveCount(2)
        ->and(Item::find($screen->get('itemIds')[0])->name)->toBe('Newer radio');
});

it('shows the empty state', function () {
    Native::test(History::class)
        ->assertSee('No saved finds yet.');
});

it('searches across words and clears the search', function () {
    Item::factory()->create(['name' => 'Sony Walkman', 'category' => 'Electronics']);
    Item::factory()->create(['name' => 'Oak chair', 'category' => 'Furniture']);

    Native::test(History::class)
        ->set('search', 'walkman electronics')
        ->assertSee('Sony Walkman')
        ->assertDontSee('Oak chair')
        ->set('search', 'nothing-like-this')
        ->assertSee('No finds match your search.')
        ->tap('clear-search')
        ->assertSet('search', '')
        ->assertSee('Oak chair');
});

it('shows an error banner for oversized searches', function () {
    Native::test(History::class)
        ->set('search', str_repeat('x', 501))
        ->assertSee('Search must be 500 characters or fewer.')
        ->tap('dismiss-error')
        ->assertSet('error', null);
});

it('loads more finds a page at a time', function () {
    Item::factory()->count(105)->create();

    $screen = Native::test(History::class)->assertSee('Load more finds');
    expect($screen->get('itemIds'))->toHaveCount(100);

    $screen->tap('load-more')
        ->assertDontSee('Load more finds')
        ->assertSet('nextCursor', null);
    expect($screen->get('itemIds'))->toHaveCount(105);
});

it('retries a failed page load', function () {
    Item::factory()->count(101)->create();

    $screen = Native::test(History::class)
        ->set('nextCursor', 'not-json')
        ->tap('load-more')
        ->assertSee('Invalid history cursor.')
        ->assertSet('failedLoadingMore', true);

    $screen->set('nextCursor', null)
        ->tap('retry-history')
        ->assertSet('error', null);
    expect($screen->get('itemIds'))->toHaveCount(100);
});

it('picks up new finds on refresh, pull-to-refresh and resume', function () {
    $screen = Native::test(History::class)->assertSee('No saved finds yet.');

    Item::factory()->create(['name' => 'Brass lamp']);
    $screen->tap('refresh-history')->assertSee('Brass lamp');

    Item::factory()->create(['name' => 'Record player']);
    $screen->call('refresh')->assertSee('Record player');

    Item::factory()->create(['name' => 'Vintage camera']);
    $screen->instance()->onResume();
    $screen->call('dismissError')->assertSee('Vintage camera');
});

it('opens settings and item details', function () {
    $item = Item::factory()->create();

    Native::test(History::class)
        ->tap('open-settings')
        ->assertNavigatedTo('/settings');

    Native::test(History::class)
        ->tap("item-card-{$item->id}")
        ->assertNavigatedTo("/finds/{$item->id}?from=history");
});

it('keeps every loaded page when returning to history', function () {
    Item::factory()->count(150)->create(['last_seen_at' => now()->subHour()]);

    $screen = Native::test(History::class)->tap('load-more');
    expect($screen->get('itemIds'))->toHaveCount(150);

    $deleted = Item::query()->latestSeen()->first();
    $deleted->delete();
    Item::factory()->create(['name' => 'Fresh find', 'last_seen_at' => now()]);

    $screen->instance()->onResume();
    $screen->call('dismissError')->assertSee('Fresh find');

    expect($screen->get('itemIds'))->toHaveCount(150)
        ->not->toContain($deleted->id)
        ->and($screen->get('nextCursor'))->toBeNull();
});

it('renders loaded finds without re-querying them', function () {
    Item::factory()->count(3)->create();
    $screen = Native::test(History::class);

    $itemQueries = 0;
    DB::listen(function ($query) use (&$itemQueries) {
        $itemQueries += str_contains($query->sql, 'from "items"') ? 1 : 0;
    });

    $screen->call('dismissError');

    expect($itemQueries)->toBe(0);
});

it('picks up finds from frames that finish while history is showing', function () {
    $screen = Native::test(History::class)->assertSee('No saved finds yet.');

    $find = Item::factory()->create(['name' => 'Background find']);

    $screen->emitNative(Scan::FrameAnalyzedEvent, ['id' => 'task-1', 'status' => 'failed', 'message' => 'Timed out'])
        ->assertDontSee('Background find')
        ->emitNative(Scan::FrameAnalyzedEvent, ['id' => 'task-2', 'status' => 'finished', 'result' => ['itemIds' => []]])
        ->assertDontSee('Background find')
        ->emitNative(Scan::FrameAnalyzedEvent, ['id' => 'task-3', 'status' => 'finished', 'result' => ['itemIds' => [$find->id]]])
        ->assertSee('Background find');
});

it('settles scan analyses that finish while History is showing', function () {
    $newFind = Item::factory()->create();
    $state = app(LiveScanState::class);
    $state->pending['task-1'] = ['sessionId' => 'session', 'frameRunId' => 'run', 'dispatchedAt' => time()];

    Native::test(History::class)
        ->emitNative(Scan::FrameAnalyzedEvent, ['id' => 'task-1', 'status' => 'finished', 'result' => ['itemIds' => [$newFind->id]]])
        ->assertSee($newFind->name)
        ->assertNativeCalled('ThriftyCamera.Chime');

    expect($state->pending)->toBe([]);
});

it('shows compact stats with the full numbers for VoiceOver', function () {
    AppStat::current()->update(['frames_processed' => 1_234_567, 'items_identified' => 131, 'searches_performed' => 48_213, 'model_calls' => 7_500]);

    Native::test(History::class)
        ->assertSee('1.2M')
        ->assertSee('131')
        ->assertSee('48k')
        ->assertSee('7.5k')
        ->assertElement('row', fn (array $node) => ($node['ref'] ?? null) === 'history-stats'
            && str_contains($node['props']['a11y_label'] ?? '', '1,234,567 frames'));
});

it('shows the shared scan error and offers settings for key errors', function () {
    $scan = app(LiveScanState::class);
    $scan->fail('Add your OpenAI API key in Settings to start scanning.', true);

    Native::test(History::class)
        ->assertSee('Add your OpenAI API key in Settings to start scanning.')
        ->tap('scan-error-settings')
        ->assertNavigatedTo('/settings');

    Native::test(History::class)
        ->tap('dismiss-scan-error')
        ->assertDontSee('Add your OpenAI API key');

    expect($scan->error)->toBeNull();
});

it('keeps every History control at least 44pt', function () {
    AppStat::current();
    $screen = Native::test(History::class)->set('search', 'x')->set('error', 'Boom');

    foreach (['refresh-history', 'open-settings', 'clear-search', 'dismiss-error'] as $ref) {
        $screen->assertElement('thrifty_pressable', fn (array $node) => ($node['ref'] ?? null) === $ref
            && ($node['layout']['width'] ?? 0) >= 44 && ($node['layout']['height'] ?? 0) >= 44);
    }
});
