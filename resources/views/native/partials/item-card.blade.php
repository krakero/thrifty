{{--
    A find, styled as a price sticker. Tapping opens the detail screen.

    @var \App\Models\Item $item
    @var bool $showCapturedAt  History shows the absolute capture time; the live feed shows relative age.
    @var string $from  'scan' or 'history' — preserved so detail screens know their origin.
--}}
@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Support\Money')
@php($showCapturedAt ??= false)
@php($from ??= 'history')

<pressable
    ref="item-card-{{ $item->id }}"
    @navigate="/finds/{{ $item->id }}?from={{ $from }}"
    :press-scale="0.97"
    a11y-label="{{ $item->name }}, resale {{ Money::resaleRange($item) }}"
    a11y-hint="Opens the find details"
    class="w-full rounded-2xl bg-theme-surface border border-theme-outline p-3"
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
                <text class="text-xs uppercase text-theme-on-surface-variant">{{ $item->category }}</text>
                @if ($item->isRepeat())
                    <text font="semibold" class="rounded-full bg-theme-primary/20 px-2 text-[11] text-theme-primary">Seen {{ $item->seen_count }}×</text>
                @endif
                <spacer />
                <text class="text-xs text-theme-on-surface-variant">
                    {{ $showCapturedAt ? $item->last_seen_at->format('M j, g:i A') : $item->first_seen_at->diffForHumans() }}
                </text>
            </row>

            <text font="display" class="text-base text-theme-on-surface" :max-lines="2">{{ $item->name }}</text>
            <text class="text-sm text-theme-on-surface-variant" :max-lines="2">{{ $item->value_summary }}</text>

            <row class="w-full items-center gap-2 mt-1">
                <column class="rounded-lg bg-theme-accent/15 px-2 py-1">
                    <text class="text-[10] uppercase text-theme-accent">Resale</text>
                    <text font="mono" class="text-sm text-theme-accent">{{ Money::resaleRange($item) }}</text>
                </column>
                <column class="rounded-lg bg-theme-surface-variant px-2 py-1">
                    <text class="text-[10] uppercase text-theme-on-surface-variant">Retail</text>
                    <text font="mono" class="text-sm text-theme-on-surface">{{ Money::format($item->retail_price_cents, $item->currency) }}</text>
                </column>
                @if ($item->observed_price_cents !== null)
                    <row class="items-center gap-1 rounded-lg bg-theme-primary px-2 py-1">
                        <icon :ios="Ios::TagFill" :android="Android::Sell" :size="12" class="text-theme-on-primary" />
                        <text font="mono" class="text-sm text-theme-on-primary">{{ Money::format($item->observed_price_cents, $item->currency) }}</text>
                    </row>
                @endif
            </row>
        </column>

        <column class="h-[88] justify-center">
            <icon :ios="Ios::ChevronRight" :android="Android::ChevronRight" :size="16" class="text-theme-on-surface-variant" />
        </column>
    </row>
</pressable>
