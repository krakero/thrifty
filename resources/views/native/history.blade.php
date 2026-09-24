{{--
    The heading and search stay pinned (an opaque backdrop under the status bar); everything else scrolls and refreshes.

    @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Item> $items
    @var \App\Models\AppStat $stats
    @var string|null $scanError
    @var bool $scanErrorNeedsApiKey
--}}
@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Scanning\CompactNumber')

<column class="w-full h-full bg-theme-background">
    <column class="w-full gap-3 px-4 pt-2 pb-3 bg-theme-background">
        <row class="w-full items-center gap-2">
            <column class="flex-1 gap-1">
                <text font="semibold" class="text-xs uppercase tracking-widest text-theme-primary" :max-lines="1">All-time finds</text>
                <text font="display" class="text-3xl text-theme-on-background" :max-lines="1">History</text>
            </column>
            <thrifty-pressable ref="refresh-history" @press="refresh" :press-scale="0.9" a11y-label="Refresh history" class="w-[44] h-[44] rounded-full bg-theme-surface border border-theme-outline items-center justify-center">
                <icon :ios="Ios::ClockArrowCirclepath" :android="Android::History" :size="20" class="text-theme-on-surface" />
            </thrifty-pressable>
            <thrifty-pressable ref="open-settings" @press="openSettings" :press-scale="0.9" a11y-label="Open settings" class="w-[44] h-[44] rounded-full bg-theme-surface border border-theme-outline items-center justify-center">
                <icon :ios="Ios::Gearshape" :android="Android::Settings" :size="20" class="text-theme-on-surface" />
            </thrifty-pressable>
        </row>

        <row class="w-full items-center gap-2 rounded-full bg-theme-surface border border-theme-outline pl-4 pr-1 h-[48]">
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
                <thrifty-pressable ref="clear-search" @press="clearSearch" a11y-label="Clear history search" class="w-[44] h-[44] items-center justify-center">
                    <icon :ios="Ios::XmarkCircleFill" :android="Android::Cancel" :size="18" class="text-theme-on-surface-variant" />
                </thrifty-pressable>
            @endif
        </row>
    </column>

    <refreshable @refresh="refresh" class="w-full flex-1 bg-theme-background">
        <column class="w-full gap-4 px-4 pt-1 pb-8">
            <row
                ref="history-stats"
                class="w-full rounded-2xl bg-theme-surface border border-theme-outline py-3"
                a11y-label="Processing statistics: {{ number_format($stats->frames_processed) }} frames, {{ number_format($stats->items_identified) }} items, {{ number_format($stats->searches_performed) }} searches, {{ number_format($stats->model_calls) }} calls"
            >
                @foreach (['Frames' => $stats->frames_processed, 'Items' => $stats->items_identified, 'Searches' => $stats->searches_performed, 'Calls' => $stats->model_calls] as $label => $value)
                    <column class="flex-1 items-center gap-1 px-1">
                        <text font="mono" class="text-lg text-theme-on-surface" :max-lines="1" content-transition="numeric">{{ CompactNumber::format($value) }}</text>
                        <text class="text-[10] uppercase text-theme-on-surface-variant" :max-lines="1">{{ $label }}</text>
                    </column>
                @endforeach
            </row>

            @if ($scanError !== null)
                <row ref="scan-error" class="w-full items-center gap-3 rounded-xl bg-theme-destructive/15 border border-theme-destructive/40 pl-3 py-1">
                    <icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Warning" :size="18" class="text-theme-destructive" />
                    <text class="flex-1 text-sm text-theme-on-surface py-2">{{ $scanError }}</text>
                    @if ($scanErrorNeedsApiKey)
                        <button ref="scan-error-settings" variant="secondary" @press="openSettings">Settings</button>
                    @endif
                    <thrifty-pressable ref="dismiss-scan-error" @press="dismissScanError" a11y-label="Dismiss error" class="w-[44] h-[44] items-center justify-center">
                        <icon :ios="Ios::Xmark" :android="Android::Close" :size="14" class="text-theme-on-surface-variant" />
                    </thrifty-pressable>
                </row>
            @endif

            @if ($error !== null)
                <row class="w-full items-center gap-3 rounded-xl bg-theme-destructive/15 border border-theme-destructive/40 pl-3 py-1">
                    <icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Warning" :size="18" class="text-theme-destructive" />
                    <text class="flex-1 text-sm text-theme-on-surface py-2">{{ $error }}</text>
                    <button ref="retry-history" variant="secondary" @press="retry">Retry</button>
                    <thrifty-pressable ref="dismiss-error" @press="dismissError" a11y-label="Dismiss error" class="w-[44] h-[44] items-center justify-center">
                        <icon :ios="Ios::Xmark" :android="Android::Close" :size="14" class="text-theme-on-surface-variant" />
                    </thrifty-pressable>
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
                <button ref="load-more" variant="secondary" size="lg" @press="loadMore" class="w-full">Load more finds</button>
            @endif
        </column>
    </refreshable>
</column>
