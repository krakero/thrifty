{{--
    A find, styled as a price sticker. Tapping opens the detail screen.

    @var \App\Models\Item $item
    @var bool $showCapturedAt  History shows the absolute capture time; the live feed shows relative age.
    @var string $from  'scan' or 'history' — preserved so detail screens know their origin.
--}}
@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Support\Money')
@use('App\Support\LocalTime')
@use('App\Support\PriceText')
@php($showCapturedAt ??= false)
@php($from ??= 'history')

<thrifty-pressable
    native:key="item-card-{{ $item->id }}"
    ref="item-card-{{ $item->id }}"
    @navigate="'/finds/'.$item->id.'?from='.$from, ['from' => $from]"
    a11y-label="{{ $item->name }}, resale {{ Money::resaleRange($item) }}"
    a11y-hint="Opens the find details"
    class="w-full gap-2 rounded-2xl bg-theme-surface border border-theme-outline p-3"
>
    <row class="w-full gap-3">
        <stack class="w-[88] h-[88] rounded-xl bg-theme-surface-variant">
            <image src="{{ $item->thumbnailFile() }}" :fit="2" class="w-[88] h-[88] rounded-xl" />
            <row class="w-full h-full items-end justify-start p-1">
                <text font="mono" class="rounded-md bg-theme-background/80 px-1 text-[11] text-theme-on-surface">{{ (int) round($item->confidence * 100) }}%</text>
            </row>
        </stack>

        <column class="flex-1 gap-1">
            <row class="w-full items-center gap-2">
                <text class="flex-1 text-xs uppercase text-theme-on-surface-variant" :max-lines="1">{{ $item->category }}</text>
                @if ($item->isRepeat())
                    <text font="semibold" class="rounded-full bg-theme-primary/20 px-2 text-[11] text-theme-primary">{{ PriceText::keepTogether('Seen '.$item->seen_count.'×') }}</text>
                @endif
                @unless ($showCapturedAt)
                    <text class="text-xs text-theme-on-surface-variant" :max-lines="1">{{ $item->first_seen_at->diffForHumans() }}</text>
                @endunless
            </row>

            @if ($showCapturedAt)
                <text class="text-xs text-theme-on-surface-variant">Snapped {{ LocalTime::format($item->last_seen_at) }}</text>
            @endif

            <text font="display" class="text-base text-theme-on-surface" :max-lines="2">{{ $item->name }}</text>
            <text class="text-sm text-theme-on-surface-variant" :max-lines="2">{{ PriceText::plainSummary($item->value_summary) }}</text>
        </column>

        <column class="h-[88] justify-center">
            <icon :ios="Ios::ChevronRight" :android="Android::ChevronRight" :size="16" class="text-theme-on-surface-variant" />
        </column>
    </row>

    {{--
        Prices get the full card width, the resale range on its own row, so they stay on one line at normal sizes. At the
        largest text sizes they wrap rather than truncate: a value is never hidden.
    --}}
    <column class="w-full rounded-lg bg-theme-accent/15 px-2 py-1">
        <text class="text-[10] uppercase text-theme-accent" :max-lines="1">Resale</text>
        <text font="mono" class="text-sm text-theme-accent">{{ PriceText::keepTogether(Money::resaleRange($item)) }}</text>
    </column>
    <row class="w-full items-center gap-2">
        <column class="flex-1 rounded-lg bg-theme-surface-variant px-2 py-1">
            <text class="text-[10] uppercase text-theme-on-surface-variant" :max-lines="1">Retail</text>
            <text font="mono" class="text-sm text-theme-on-surface">{{ Money::format($item->retail_price_cents, $item->currency) }}</text>
        </column>
        @if ($item->observed_price_cents !== null)
            <column class="flex-1 rounded-lg bg-theme-primary px-2 py-1">
                <text class="text-[10] uppercase text-theme-on-primary" :max-lines="1">Tag</text>
                <text font="mono" class="text-sm text-theme-on-primary">{{ Money::format($item->observed_price_cents, $item->currency) }}</text>
            </column>
        @endif
    </row>
</thrifty-pressable>
