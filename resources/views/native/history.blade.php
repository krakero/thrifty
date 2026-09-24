{{--
    @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Item> $items
    @var \App\Models\AppStat $stats
--}}
@use('App\Icons\Ios')
@use('App\Icons\Android')

<refreshable @refresh="refresh" class="w-full h-full bg-theme-background">
    <column class="w-full gap-4 px-4 pt-2 pb-8">
        <row class="w-full items-center gap-2">
            <column class="flex-1 gap-1">
                <text font="semibold" class="text-xs uppercase tracking-widest text-theme-primary">All-time finds</text>
                <text font="display" class="text-3xl text-theme-on-background">History</text>
            </column>
            <pressable ref="refresh-history" @press="refresh" :press-scale="0.9" a11y-label="Refresh history" class="w-[44] h-[44] rounded-full bg-theme-surface border border-theme-outline items-center justify-center">
                <icon :ios="Ios::ClockArrowCirclepath" :android="Android::History" :size="20" class="text-theme-on-surface" />
            </pressable>
            <pressable ref="open-settings" @press="openSettings" :press-scale="0.9" a11y-label="Open settings" class="w-[44] h-[44] rounded-full bg-theme-surface border border-theme-outline items-center justify-center">
                <icon :ios="Ios::Gearshape" :android="Android::Settings" :size="20" class="text-theme-on-surface" />
            </pressable>
        </row>

        <row class="w-full rounded-2xl bg-theme-surface border border-theme-outline py-3" a11y-label="Processing statistics">
            @foreach (['Frames' => $stats->frames_processed, 'Items' => $stats->items_identified, 'Searches' => $stats->searches_performed, 'Calls' => $stats->model_calls] as $label => $value)
                <column class="flex-1 items-center gap-1">
                    <text font="mono" class="text-lg text-theme-on-surface" content-transition="numeric">{{ number_format($value) }}</text>
                    <text class="text-[11] uppercase tracking-wide text-theme-on-surface-variant">{{ $label }}</text>
                </column>
            @endforeach
        </row>

        <row class="w-full items-center gap-2 rounded-full bg-theme-surface border border-theme-outline pl-4 pr-2 h-[48]">
            <icon :ios="Ios::Magnifyingglass" :android="Android::Search" :size="18" class="text-theme-on-surface-variant" />
            <bare-text-input
                ref="history-search"
                native:model.debounce.250ms="search"
                placeholder="Search all finds, brands, descriptions…"
                :max-length="500"
                a11y-label="Search all history"
                class="flex-1 text-theme-on-surface"
            />
            @if ($search !== '')
                <pressable ref="clear-search" @press="clearSearch" a11y-label="Clear history search" class="w-[36] h-[36] items-center justify-center">
                    <icon :ios="Ios::XmarkCircleFill" :android="Android::Cancel" :size="18" class="text-theme-on-surface-variant" />
                </pressable>
            @endif
        </row>

        @if ($error !== null)
            <row class="w-full items-center gap-3 rounded-xl bg-theme-destructive/15 border border-theme-destructive/40 p-3">
                <icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Warning" :size="18" class="text-theme-destructive" />
                <text class="flex-1 text-sm text-theme-on-surface">{{ $error }}</text>
                <button ref="retry-history" variant="secondary" size="sm" @press="retry">Retry</button>
                <pressable ref="dismiss-error" @press="dismissError" a11y-label="Dismiss error" class="w-[32] h-[32] items-center justify-center">
                    <icon :ios="Ios::Xmark" :android="Android::Close" :size="14" class="text-theme-on-surface-variant" />
                </pressable>
            </row>
        @endif

        <column class="w-full gap-3">
            @foreach ($items as $item)
                @include('native.partials.item-card', ['item' => $item, 'from' => 'history', 'showCapturedAt' => true])
            @endforeach
        </column>

        @if ($items->isEmpty() && $error === null)
            <column class="w-full items-center gap-3 py-16">
                <icon :ios="Ios::DollarsignCircle" :android="Android::MonetizationOn" :size="40" class="text-theme-on-surface-variant" />
                <text class="text-base text-theme-on-surface-variant text-center">{{ trim($search) !== '' ? 'No finds match your search.' : 'No saved finds yet.' }}</text>
            </column>
        @endif

        @if ($nextCursor !== null)
            <button ref="load-more" variant="secondary" @press="loadMore" class="w-full">Load more finds</button>
        @endif
    </column>
</refreshable>
