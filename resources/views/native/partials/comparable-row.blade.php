{{--
    One valuation source: a fixed-width type badge (so titles line up), the title and the price.

    @var array{type: string, title: string, url: string|null, price: string} $comparable
    @var array{0: string, 1: string} $typeClass  Badge background and text colour classes.
--}}
@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Support\PriceText')

<row class="w-full items-center gap-3">
    <column class="min-w-[60] items-center rounded-md px-2 py-1 {{ $typeClass[0] }}">
        <text font="semibold" class="text-[10] uppercase {{ $typeClass[1] }}">{{ $comparable['type'] }}</text>
    </column>
    <text class="flex-1 text-sm text-theme-on-surface" :max-lines="2">{{ $comparable['title'] }}</text>
    <text font="mono" class="text-sm text-theme-on-surface">{{ PriceText::keepTogether($comparable['price']) }}</text>
    @if ($comparable['url'] !== null)
        <icon :ios="Ios::ArrowUpRight" :android="Android::OpenInNew" :size="14" class="text-theme-on-surface-variant" />
    @endif
</row>
