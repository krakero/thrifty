<?php

namespace App\NativeComponents;

use App\Models\AppStat;
use App\Models\Item;
use App\Queries\HistoryQuery;
use App\Queries\HistoryQueryException;
use App\Scanning\FrameResults;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Throwable;

/**
 * Every saved find, newest first, with search and "Load more" pagination.
 */
class History extends NativeComponent
{
    public string $search = '';

    /** @var list<string> */
    public array $itemIds = [];

    public ?string $nextCursor = null;

    public ?string $error = null;

    /** Whether the last failure was while loading a further page (Retry then loads that page again). */
    public bool $failedLoadingMore = false;

    /**
     * The loaded finds in display order, so a render doesn't re-query them.
     *
     * @var Collection<int, Item>|null
     */
    private ?Collection $items = null;

    public function mount(): void
    {
        $this->reload();
    }

    /**
     * Coming back (from a find, Settings or the Scan tab) re-fetches as many finds as were loaded, like the web app
     * invalidating every loaded page, so the list keeps its length while picking up new, changed and deleted finds.
     */
    public function onResume(): void
    {
        $this->reload(keepLoaded: true);
    }

    /**
     * A frame finished analysing while History is showing (the Scan tab dispatches it as a shared event), so pick up its
     * finds straight away, like the web app refreshing history after every frame.
     *
     * @param  array<string, mixed>|null  $result
     */
    #[On(Scan::FrameAnalyzedEvent)]
    public function frameAnalyzed(string $id, string $status, mixed $result = null, ?string $exceptionClass = null, ?string $message = null): void
    {
        app(FrameResults::class)->handle($id, $status, $result, $exceptionClass, $message);

        if ($status === 'finished' && is_array($result) && ($result['itemIds'] ?? []) !== []) {
            $this->reload(keepLoaded: true);
        }
    }

    public function updatedSearch(): void
    {
        $this->reload();
    }

    public function refresh(): void
    {
        $this->reload(keepLoaded: true);
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->reload();
    }

    public function loadMore(): void
    {
        if ($this->nextCursor !== null) {
            $this->loadPage($this->nextCursor);
        }
    }

    public function retry(): void
    {
        $this->failedLoadingMore && $this->nextCursor !== null ? $this->loadMore() : $this->reload();
    }

    public function dismissError(): void
    {
        $this->error = null;
    }

    public function openSettings(): void
    {
        $this->navigate('/settings');
    }

    public function render(): View
    {
        return view('native.history', [
            'items' => $this->loadedItems(),
            'stats' => AppStat::current(),
        ]);
    }

    private function reload(bool $keepLoaded = false): void
    {
        $target = $keepLoaded ? max(count($this->itemIds), HistoryQuery::PAGE_SIZE) : HistoryQuery::PAGE_SIZE;

        $this->itemIds = [];
        $this->items = new Collection;
        $this->nextCursor = null;

        if (! $this->loadPage(null)) {
            return;
        }

        while ($this->nextCursor !== null && count($this->itemIds) < $target && $this->loadPage($this->nextCursor)) {
            //
        }
    }

    private function loadPage(?string $cursor): bool
    {
        try {
            $page = (new HistoryQuery)->page($this->search, $cursor);
        } catch (HistoryQueryException $exception) {
            $this->fail($exception->getMessage(), $cursor !== null);

            return false;
        } catch (Throwable $exception) {
            report($exception);
            $this->fail('Could not load saved finds.', $cursor !== null);

            return false;
        }

        $this->items = $this->loadedItems()->concat($page['items'])->unique('id')->values();
        $this->itemIds = $this->items->modelKeys();
        $this->nextCursor = $page['nextCursor'];
        $this->error = null;
        $this->failedLoadingMore = false;

        return true;
    }

    private function fail(string $message, bool $whileLoadingMore): void
    {
        $this->error = $message;
        $this->failedLoadingMore = $whileLoadingMore;
    }

    /**
     * @return Collection<int, Item>
     */
    private function loadedItems(): Collection
    {
        if ($this->items === null || $this->items->count() !== count($this->itemIds)) {
            $this->items = $this->itemIds === []
                ? new Collection
                : Item::query()->whereKey($this->itemIds)->latestSeen()->get();
        }

        return $this->items;
    }
}
