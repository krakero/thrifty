<?php

use App\Models\Item;
use App\Models\ScanSession;
use App\Queries\HistoryQuery;
use App\Queries\HistoryQueryException;
use Illuminate\Support\Facades\DB;

/**
 * Port of `worker/history.test.ts`: 135 finds, the first ten a day older, all sharing timestamps.
 */
beforeEach(function () {
    $session = ScanSession::factory()->create();

    foreach (range(0, 134) as $index) {
        $id = str_pad((string) $index, 3, '0', STR_PAD_LEFT);
        $date = $index < 10 ? '2026-09-15 12:00:00' : '2026-09-16 12:00:00';

        Item::factory()->for($session)->create([
            'id' => $id,
            'fingerprint' => $id,
            'name' => $index === 0 ? 'Rare Walkman' : "Find {$id}",
            'category' => 'Electronics',
            'brand' => null,
            'model' => null,
            'description' => 'A useful find',
            'condition' => 'Used',
            'value_summary' => '',
            'first_seen_at' => $date,
            'last_seen_at' => $date,
        ]);
    }
});

function historyIds(string $search = '', ?string $cursor = null): array
{
    return (new HistoryQuery)->page($search, $cursor)['items']->pluck('id')->all();
}

it('reaches all entries beyond one page, including timestamp ties', function () {
    $ids = [];
    $cursor = null;

    do {
        $page = (new HistoryQuery)->page('', $cursor);
        expect($page['items']->count())->toBeLessThanOrEqual(HistoryQuery::PAGE_SIZE);
        array_push($ids, ...$page['items']->pluck('id')->all());
        $cursor = $page['nextCursor'];
    } while ($cursor !== null);

    expect($ids)->toHaveCount(135)
        ->and(array_unique($ids))->toHaveCount(135)
        ->and(end($ids))->toBe('000');
});

it('searches older records and matches words across metadata fields', function () {
    expect(historyIds('  WALKMAN electronics used  '))->toBe(['000']);

    DB::table('items')->where('id', '000')->update(['brand' => 'Sony', 'model' => 'WM-2', 'value_summary' => 'Collectible']);

    expect(historyIds('sony wm-2 collectible'))->toBe(['000']);
});

it('treats SQL wildcards and injection text literally', function () {
    expect(historyIds('%'))->toBe([])
        ->and(historyIds("_' OR 1=1 --"))->toBe([])
        ->and(historyIds('missing'))->toBe([]);
});

it('continues after deletion of the cursor item', function () {
    $page = (new HistoryQuery)->page();
    $last = $page['items']->last();

    Item::query()->whereKey($last->id)->delete();

    expect(historyIds('', $page['nextCursor'])[0])->toBe('034');
});

it('rejects invalid cursors', function (string $cursor) {
    (new HistoryQuery)->page('', $cursor);
})->with(['', 'nope', 'null', '{}', '{"id":1,"lastSeenAt":"x"}'])
    ->throws(HistoryQueryException::class, 'Invalid history cursor');

it('rejects oversized searches', function () {
    (new HistoryQuery)->page(str_repeat('x', 501));
})->throws(HistoryQueryException::class, '500 characters');
