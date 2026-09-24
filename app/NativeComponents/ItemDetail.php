<?php

namespace App\NativeComponents;

use App\Actions\DeleteItems;
use App\Icons\Android;
use App\Icons\Ios;
use App\Models\Item;
use App\Models\ValuationSource;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Native\Mobile\Edge\Layouts\Builders\NavAction;
use Native\Mobile\Edge\Layouts\Builders\NavBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Dialog;
use Thrifty\Camera\Facades\ThriftyCamera;

/**
 * One find: the annotated frame it was seen in, its valuation and the evidence behind it.
 */
class ItemDetail extends NativeComponent
{
    /** Web results linked from the value summary, as Markdown links. */
    private const MARKDOWN_LINK = '/\[([^\]]+)]\((https?:\/\/[^)]+)\)/';

    private const MAX_SOURCES = 8;

    public string $itemId = '';

    /** Which tab opened this find: 'scan' or 'history'. */
    public string $from = 'history';

    public ?string $error = null;

    public function mount(): void
    {
        $this->itemId = (string) $this->param('id');
        $this->from = $this->data('from') === 'scan' ? 'scan' : 'history';
    }

    public function navTitle(): string
    {
        return 'Find';
    }

    public function navigationOptions(): ?NavBarOptions
    {
        if ($this->item() === null) {
            return null;
        }

        return NavBarOptions::make()
            ->action(NavAction::make('share')->icon(ios: Ios::SquareAndArrowUp, android: Android::Share)->a11yLabel('Share find as image')->press('share'))
            ->action(NavAction::make('delete')->icon(ios: Ios::Trash, android: Android::Delete)->a11yLabel('Delete find')->destructive()->press('confirmDelete'));
    }

    /**
     * Switch to another find from the same frame (a box or a list entry).
     */
    public function selectItem(string $itemId): void
    {
        if ($this->frameItems()->contains('id', $itemId)) {
            $this->itemId = $itemId;
        }
    }

    public function openActivity(): void
    {
        $this->navigate("/finds/{$this->itemId}/activity?from={$this->from}", ['from' => $this->from]);
    }

    public function openSource(int $index): void
    {
        $url = $this->marketEvidence()[$index]['url'] ?? null;

        if ($url !== null) {
            Browser::inApp($url);
        }
    }

    public function share(): void
    {
        $item = $this->item();

        if ($item === null) {
            return;
        }

        $frame = $this->frameImage($item);

        ThriftyCamera::shareFindCard([
            'title' => $item->name,
            'subtitle' => $item->category.' · '.$this->confidence($item).' confidence',
            'imagePath' => $frame['path'],
            'box' => $frame['annotated'] ? $item->boundingBox() : null,
            'rows' => collect($this->priceRows($item))
                ->reject(fn (array $row): bool => $row['value'] === '—')
                ->map(fn (array $row): array => ['label' => $row['label'], 'value' => $row['value']])
                ->push(['label' => 'Condition', 'value' => $item->condition])
                ->values()
                ->all(),
            'summary' => $this->plainSummary($item),
        ]);
    }

    public function confirmDelete(): void
    {
        $item = $this->item();

        if ($item === null) {
            return;
        }

        Dialog::alert('Delete this find?', "{$item->name} will be removed from your history.", [
            ['label' => 'Cancel', 'style' => 'cancel'],
            ['label' => 'Delete', 'style' => 'destructive'],
        ])
            ->id("delete-find-{$item->id}")
            ->buttonPressed(function (ButtonPressed $event): void {
                if ($event->label === 'Delete') {
                    $this->deleteFind();
                }
            });
    }

    public function deleteFind(): void
    {
        $item = $this->item();

        if ($item === null) {
            $this->back();

            return;
        }

        try {
            app(DeleteItems::class)->one($item);
        } catch (\Throwable $exception) {
            report($exception);
            $this->error = 'Could not delete this find.';

            return;
        }

        $this->back();
    }

    public function dismissError(): void
    {
        $this->error = null;
    }

    public function render(): View
    {
        $item = $this->item();

        if ($item === null) {
            return view('native.item-detail', ['item' => null]);
        }

        $frameItems = $this->frameItems();

        return view('native.item-detail', [
            'item' => $item,
            'frameItems' => $frameItems,
            'frame' => $this->frameImage($item),
            'priceRows' => $this->priceRows($item),
            'evidence' => $this->marketEvidence(),
            'summary' => $this->plainSummary($item),
            'confidence' => $this->confidence($item),
        ]);
    }

    private function item(): ?Item
    {
        return Item::query()->with('frameRun')->find($this->itemId);
    }

    /**
     * Every find whose latest sighting was the same frame, newest first; just this find when no frame was recorded.
     *
     * @return Collection<int, Item>
     */
    private function frameItems(): Collection
    {
        $item = $this->item();

        if ($item === null) {
            return new Collection;
        }

        if ($item->frame_run_id === null) {
            return new Collection([$item]);
        }

        return Item::query()->where('frame_run_id', $item->frame_run_id)->latestSeen()->get();
    }

    /**
     * The full frame when it's still on disk (boxes are drawn over it), otherwise the find's own thumbnail.
     *
     * @return array{path: string, annotated: bool, aspectRatio: float}
     */
    private function frameImage(Item $item): array
    {
        $disk = Storage::disk('local');
        $framePath = $item->frameRun?->frame_path;
        $annotated = $framePath !== null && $disk->exists($framePath);
        $path = $disk->path($annotated ? $framePath : $item->thumbnail_path);

        $size = is_file($path) ? @getimagesize($path) : false;
        $aspectRatio = $size !== false && $size[1] > 0 ? $size[0] / $size[1] : 4 / 3;

        return ['path' => $path, 'annotated' => $annotated, 'aspectRatio' => round($aspectRatio, 4)];
    }

    /**
     * @return list<array{label: string, value: string, tone: string}>
     */
    private function priceRows(Item $item): array
    {
        return [
            ['label' => 'Estimated resale', 'value' => Money::resaleRange($item), 'tone' => 'accent'],
            ['label' => 'Tag price', 'value' => Money::format($item->observed_price_cents, $item->currency), 'tone' => 'primary'],
            ['label' => 'Estimated retail', 'value' => Money::format($item->retail_price_cents, $item->currency), 'tone' => 'plain'],
            ['label' => 'Active listings', 'value' => Money::format($item->active_price_cents, $item->currency), 'tone' => 'plain'],
            ['label' => 'Sold', 'value' => Money::format($item->sold_price_cents, $item->currency), 'tone' => 'plain'],
        ];
    }

    /**
     * Stored comparables (newest first, at most eight) plus any web links in the value summary, like the web app's
     * `collectMarketEvidence()`.
     *
     * @return list<array{type: string, title: string, url: string|null, price: string}>
     */
    private function marketEvidence(): array
    {
        $item = $this->item();

        if ($item === null) {
            return [];
        }

        $evidence = ValuationSource::query()
            ->where('item_id', $item->id)
            ->orderByDesc('captured_at')
            ->limit(self::MAX_SOURCES)
            ->get()
            ->map(fn (ValuationSource $source): array => [
                'type' => $source->source_type->value,
                'title' => $source->title,
                'url' => $source->url,
                'price' => Money::format($source->price_cents, $source->currency),
            ])
            ->all();

        $knownUrls = array_filter(array_column($evidence, 'url'));

        preg_match_all(self::MARKDOWN_LINK, $item->value_summary, $matches, PREG_SET_ORDER);

        foreach ($matches as [, $title, $url]) {
            if (in_array($url, $knownUrls, true)) {
                continue;
            }

            $evidence[] = ['type' => 'web', 'title' => $title !== '' ? $title : 'Web result', 'url' => $url, 'price' => '—'];
            $knownUrls[] = $url;
        }

        return $evidence;
    }

    /**
     * The value summary with Markdown links reduced to their titles (the links are listed under the evidence).
     */
    private function plainSummary(Item $item): string
    {
        return preg_replace(self::MARKDOWN_LINK, '$1', $item->value_summary) ?? $item->value_summary;
    }

    private function confidence(Item $item): string
    {
        return (int) round($item->confidence * 100).'%';
    }
}
