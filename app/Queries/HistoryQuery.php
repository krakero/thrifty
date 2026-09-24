<?php

namespace App\Queries;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * All saved finds, newest first, with multi-word search and keyset pagination (a port of `worker/history.ts`).
 */
class HistoryQuery
{
    public const PAGE_SIZE = 100;

    public const MAX_SEARCH_LENGTH = 500;

    /**
     * Every word must appear somewhere in this concatenation; each can match a different field.
     */
    private const SEARCHABLE_TEXT = "lower(
        name || ' ' || category || ' ' || coalesce(brand, '') || ' ' || coalesce(model, '') || ' ' ||
        description || ' ' || condition || ' ' || value_summary || ' ' || fingerprint || ' ' || currency || ' ' ||
        first_seen_at || ' ' || last_seen_at
    )";

    /**
     * @return array{items: Collection<int, Item>, nextCursor: string|null}
     *
     * @throws HistoryQueryException
     */
    public function page(string $search = '', ?string $cursor = null): array
    {
        $search = trim($search);

        if (mb_strlen($search) > self::MAX_SEARCH_LENGTH) {
            throw new HistoryQueryException('Search must be 500 characters or fewer.');
        }

        $rows = Item::query()
            ->tap(fn (Builder $query) => $this->applySearch($query, $search))
            ->tap(fn (Builder $query) => $this->applyCursor($query, $this->decodeCursor($cursor)))
            ->latestSeen()
            ->limit(self::PAGE_SIZE + 1)
            ->get();

        $items = $rows->take(self::PAGE_SIZE)->values();
        $last = $items->last();

        return [
            'items' => $items,
            'nextCursor' => $rows->count() > self::PAGE_SIZE && $last !== null
                ? json_encode(['lastSeenAt' => $last->getRawOriginal('last_seen_at'), 'id' => $last->id])
                : null,
        ];
    }

    /**
     * `instr` treats `%`, `_` and quotes literally, unlike `LIKE`.
     *
     * @param  Builder<Item>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        foreach (preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY) as $term) {
            $query->whereRaw('instr('.self::SEARCHABLE_TEXT.', lower(?)) > 0', [$term]);
        }
    }

    /**
     * @param  Builder<Item>  $query
     * @param  array{lastSeenAt: string, id: string}|null  $cursor
     */
    private function applyCursor(Builder $query, ?array $cursor): void
    {
        if ($cursor === null) {
            return;
        }

        $query->where(fn (Builder $query) => $query
            ->where('last_seen_at', '<', $cursor['lastSeenAt'])
            ->orWhere(fn (Builder $query) => $query
                ->where('last_seen_at', $cursor['lastSeenAt'])
                ->where('id', '<', $cursor['id'])));
    }

    /**
     * @return array{lastSeenAt: string, id: string}|null
     *
     * @throws HistoryQueryException
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        $parsed = json_decode($cursor, true);

        if (! is_array($parsed) || ! is_string($parsed['lastSeenAt'] ?? null) || ! is_string($parsed['id'] ?? null) || $parsed['id'] === '') {
            throw new HistoryQueryException('Invalid history cursor.');
        }

        return ['lastSeenAt' => $parsed['lastSeenAt'], 'id' => $parsed['id']];
    }
}
