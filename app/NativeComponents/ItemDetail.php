<?php

namespace App\NativeComponents;

use App\Actions\DeleteItems;
use App\Icons\Android;
use App\Icons\Ios;
use App\Models\Item;
use App\Models\ValuationSource;
use App\Scanning\ReceivesFrameAnalyses;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\PriceText;
use Closure;
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
 *
 * The web app's "Search full frame with Google Lens" link is deliberately not ported: Lens needs a public image URL, and
 * frames never leave the device.
 */
class ItemDetail extends NativeComponent
{
    use ReceivesFrameAnalyses;

    private const MAX_SOURCES = 8;

    private const SHARED_COMPARABLES = 3;

    /** The smallest box drawn, in 0–1000 units, so a zero-size box still gets a positive flex-grow. */
    private const MIN_BOX_RATIO = 1;

    public string $itemId = '';

    /** Which tab opened this find: 'scan' or 'history'. */
    public string $from = 'history';

    public ?string $error = null;

    /**
     * Query results for the current render, keyed by item id then by name.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

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
        $this->memo = [];
        $item = $this->item();

        if ($item === null) {
            return;
        }

        $frame = $this->frameImage($item);
        $boxes = $frame['annotated']
            ? $this->frameItems()
                ->filter(fn (Item $frameItem): bool => $frameItem->boundingBox() !== null)
                ->map(fn (Item $frameItem): array => [...$frameItem->boundingBox(), 'selected' => $frameItem->id === $item->id])
                ->values()
                ->all()
            : [];

        ThriftyCamera::shareFindCard([
            'title' => $item->name,
            'subtitle' => $item->category.' · '.$this->confidence($item).' confidence',
            'imagePath' => $frame['path'],
            'box' => $frame['annotated'] ? $item->boundingBox() : null,
            'boxes' => $boxes,
            'rows' => $this->shareRows($item),
            'summary' => trim($item->description."\n\n".$this->plainSummary($item)),
        ]);
    }

    /**
     * Everything the detail screen shows, as label/value rows: prices, facts and the top comparables.
     *
     * @return list<array{label: string, value: string}>
     */
    private function shareRows(Item $item): array
    {
        $prices = collect($this->priceRows($item))
            ->reject(fn (array $row): bool => $row['value'] === '—')
            ->map(fn (array $row): array => ['label' => $row['label'], 'value' => $row['value']]);

        $facts = collect([
            'Brand' => $item->brand ?? 'Unknown',
            'Model' => $item->model ?? 'Unknown',
            'Condition' => $item->condition,
            'Seen' => $item->seen_count.' '.str('time')->plural($item->seen_count),
        ])->map(fn (string $value, string $label): array => ['label' => $label, 'value' => $value])->values();

        $comparables = collect($this->marketEvidence())
            ->take(self::SHARED_COMPARABLES)
            ->map(fn (array $comparable): array => ['label' => ucfirst($comparable['type']).' · '.$comparable['title'], 'value' => $comparable['price']]);

        return $prices->concat($facts)->concat($comparables)->values()->all();
    }

    public function confirmDelete(): void
    {
        $this->memo = [];
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
        $this->memo = [];
        $item = $this->item();

        if ($item === null) {
            $this->back();

            return;
        }

        try {
            app(DeleteItems::class)->one($item);
            $this->memo = [];
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
        $this->memo = [];
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
            'frameChips' => $frameItems->sortBy(fn (Item $frameItem): int => $frameItem->id === $item->id ? 0 : 1)->values(),
            'evidence' => $this->marketEvidence(),
            'summary' => $this->plainSummary($item),
            'confidence' => $this->confidence($item),
            'firstSeen' => LocalTime::format($item->first_seen_at),
            'lastSeen' => LocalTime::format($item->last_seen_at),
        ]);
    }

    /**
     * Normalized 0–1000 box edges as flex-grow ratios: the space before, the box, and the space after, on each axis.
     *
     * @param  array{xMin: int, yMin: int, xMax: int, yMax: int}  $box
     * @return array{top: int, height: int, bottom: int, left: int, width: int, right: int}
     */
    public static function boxRatios(array $box): array
    {
        $clamp = fn (int $value): int => max(0, min(1000, $value));
        [$top, $bottom] = [$clamp(min($box['yMin'], $box['yMax'])), $clamp(max($box['yMin'], $box['yMax']))];
        [$left, $right] = [$clamp(min($box['xMin'], $box['xMax'])), $clamp(max($box['xMin'], $box['xMax']))];

        if ($bottom - $top < self::MIN_BOX_RATIO) {
            [$top, $bottom] = $top + self::MIN_BOX_RATIO <= 1000 ? [$top, $top + self::MIN_BOX_RATIO] : [1000 - self::MIN_BOX_RATIO, 1000];
        }

        if ($right - $left < self::MIN_BOX_RATIO) {
            [$left, $right] = $left + self::MIN_BOX_RATIO <= 1000 ? [$left, $left + self::MIN_BOX_RATIO] : [1000 - self::MIN_BOX_RATIO, 1000];
        }

        return [
            'top' => $top, 'height' => $bottom - $top, 'bottom' => 1000 - $bottom,
            'left' => $left, 'width' => $right - $left, 'right' => 1000 - $right,
        ];
    }

    /**
     * @param  array{xMin: int, yMin: int, xMax: int, yMax: int}  $box
     */
    public static function boxArea(array $box): int
    {
        $ratios = self::boxRatios($box);

        return $ratios['width'] * $ratios['height'];
    }

    private function item(): ?Item
    {
        return $this->remember('item', fn (): ?Item => Item::query()->with('frameRun')->find($this->itemId));
    }

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $resolve
     * @return TValue
     */
    private function remember(string $key, Closure $resolve): mixed
    {
        if (! array_key_exists($key, $this->memo[$this->itemId] ?? [])) {
            $this->memo[$this->itemId][$key] = $resolve();
        }

        return $this->memo[$this->itemId][$key];
    }

    /**
     * Every find whose latest sighting was the same frame, newest first; just this find when no frame was recorded.
     *
     * @return Collection<int, Item>
     */
    private function frameItems(): Collection
    {
        return $this->remember('frameItems', function (): Collection {
            $item = $this->item();

            if ($item === null) {
                return new Collection;
            }

            if ($item->frame_run_id === null) {
                return new Collection([$item]);
            }

            return Item::query()->where('frame_run_id', $item->frame_run_id)->latestSeen()->get();
        });
    }

    /**
     * The full frame when it's still on disk (boxes are drawn over it), otherwise the find's own thumbnail.
     *
     * @return array{path: string, annotated: bool, aspectRatio: float}
     */
    private function frameImage(Item $item): array
    {
        return $this->remember('frameImage', fn (): array => $this->resolveFrameImage($item));
    }

    /**
     * @return array{path: string, annotated: bool, aspectRatio: float}
     */
    private function resolveFrameImage(Item $item): array
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
            ['label' => 'Retail', 'value' => Money::format($item->retail_price_cents, $item->currency), 'tone' => 'plain'],
            ['label' => 'Active', 'value' => Money::format($item->active_price_cents, $item->currency), 'tone' => 'plain'],
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
        return $this->remember('marketEvidence', fn (): array => $this->collectMarketEvidence());
    }

    /**
     * @return list<array{type: string, title: string, url: string|null, price: string}>
     */
    private function collectMarketEvidence(): array
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

        preg_match_all(PriceText::MARKDOWN_LINK, $item->value_summary, $matches, PREG_SET_ORDER);

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
        return PriceText::plainSummary($item->value_summary);
    }

    private function confidence(Item $item): string
    {
        return (int) round($item->confidence * 100).'%';
    }
}
