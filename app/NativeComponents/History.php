<?php

namespace App\NativeComponents;

use App\Models\AppStat;
use App\Models\Item;
use App\Queries\HistoryQuery;
use App\Queries\HistoryQueryException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
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

    public function mount(): void
    {
        $this->reload();
    }

    public function onResume(): void
    {
        $this->reload();
    }

    public function updatedSearch(): void
    {
        $this->reload();
    }

    public function refresh(): void
    {
        $this->reload();
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

    private function reload(): void
    {
        $this->itemIds = [];
        $this->nextCursor = null;
        $this->loadPage(null);
    }

    private function loadPage(?string $cursor): void
    {
        try {
            $page = (new HistoryQuery)->page($this->search, $cursor);
        } catch (HistoryQueryException $exception) {
            $this->fail($exception->getMessage(), $cursor !== null);

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->fail('Could not load saved finds.', $cursor !== null);

            return;
        }

        $this->itemIds = array_values(array_unique([...$this->itemIds, ...$page['items']->modelKeys()]));
        $this->nextCursor = $page['nextCursor'];
        $this->error = null;
        $this->failedLoadingMore = false;
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
        if ($this->itemIds === []) {
            return new Collection;
        }

        return Item::query()->whereKey($this->itemIds)->latestSeen()->get();
    }
}
