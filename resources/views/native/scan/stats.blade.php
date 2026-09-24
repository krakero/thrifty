@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Scanning\CompactNumber')

@php
    $counters = [
        'Frames' => (int) $stats->frames_processed,
        'Items' => (int) $stats->items_identified,
        'Searches' => (int) $stats->searches_performed,
        'Calls' => (int) $stats->model_calls,
    ];
    $summary = $state->inFlight().' of '.$maxConcurrentFrames.' analyses active, '
        .collect($counters)->map(fn (int $value, string $label) => number_format($value).' '.strtolower($label))->implode(', ');
@endphp

{{--
    One line at every text size, like the web's nowrap ribbon: compact numbers and single-line labels, and it
    scrolls sideways rather than wrapping once large type no longer fits. iOS reads core text elements one by
    one (they can't be grouped), so the leading icon carries the whole summary for VoiceOver.
--}}
<scroll-view ref="stats-ribbon" horizontal class="w-full">
    <row class="items-center gap-3 rounded-full bg-theme-background/60 border border-theme-outline px-3 py-2">
        @if ($state->inFlight() > 0)
            <activity-indicator :size="12" a11y-label="{{ $summary }}" class="text-theme-accent" />
        @else
            <icon :ios="Ios::Gauge" :android="Android::Speed" :size="12" a11y-label="{{ $summary }}" class="text-theme-on-surface-variant" />
        @endif
        <row class="items-end gap-1">
            <text font="mono" :max-lines="1" class="text-[13] text-theme-on-surface">{{ $state->inFlight() }}/{{ $maxConcurrentFrames }}</text>
            <text :max-lines="1" class="pb-[1] text-[9] uppercase text-theme-on-surface-variant">Active</text>
        </row>
        @foreach ($counters as $label => $value)
            <row class="items-end gap-1">
                <text font="mono" :max-lines="1" class="text-[13] text-theme-on-surface">{{ CompactNumber::format($value) }}</text>
                <text :max-lines="1" class="pb-[1] text-[9] uppercase text-theme-on-surface-variant">{{ $label }}</text>
            </row>
        @endforeach
    </row>
</scroll-view>
