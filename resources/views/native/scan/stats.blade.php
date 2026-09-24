@use('App\Icons\Ios')
@use('App\Icons\Android')

<row ref="stats-ribbon" class="items-center gap-3 self-start rounded-full bg-theme-background/60 border border-theme-outline px-3 py-2">
    <row class="items-center gap-1" a11y-label="{{ $state->inFlight() }} of {{ $maxConcurrentFrames }} requests active">
        @if ($state->inFlight() > 0)
            <activity-indicator :size="12" class="text-theme-accent" />
        @else
            <icon :ios="Ios::Gauge" :android="Android::Speed" :size="12" class="text-theme-on-surface-variant" />
        @endif
        <text font="mono" class="text-sm text-theme-on-surface">{{ $state->inFlight() }}/{{ $maxConcurrentFrames }}</text>
        <text class="text-[10] uppercase text-theme-on-surface-variant">Active</text>
    </row>
    @foreach (['Frames' => $stats->frames_processed, 'Items' => $stats->items_identified, 'Searches' => $stats->searches_performed, 'Calls' => $stats->model_calls] as $label => $value)
        <row class="items-center gap-1">
            <text font="mono" class="text-sm text-theme-on-surface">{{ number_format((int) $value) }}</text>
            <text class="text-[10] uppercase text-theme-on-surface-variant">{{ $label }}</text>
        </row>
    @endforeach
</row>
