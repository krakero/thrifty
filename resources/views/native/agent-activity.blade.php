{{--
    @var \App\Models\Item|null $item
    @var \App\Models\FrameRun|null $run
    @var list<array{key: string, title: string, index: int|null, body: string, open: bool}> $blocks
--}}
@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\NativeComponents\AgentActivity')

@if ($item === null || $run === null)
    <column class="w-full h-full items-center justify-center gap-3 p-8 bg-theme-background">
        <icon :ios="Ios::Cpu" :android="Android::SmartToy" :size="36" class="text-theme-on-surface-variant" />
        <text font="display" class="text-xl text-theme-on-background text-center">{{ $item === null ? 'Find not found.' : 'No run recorded' }}</text>
        <text class="text-sm text-theme-on-surface-variant text-center">
            {{ $item === null ? 'It may have been deleted.' : 'Agent activity was not recorded for this older find.' }}
        </text>
        <button ref="go-back" variant="secondary" @navigate.back>Go back</button>
    </column>
@else
    @php
        $status = AgentActivity::statusBadge($run);
    @endphp
    <scroll-view class="w-full h-full bg-theme-background">
        <column class="w-full gap-4 px-4 pt-2 pb-10">
            <column class="w-full gap-3 rounded-2xl bg-theme-surface border border-theme-outline p-4">
                <row class="w-full items-center gap-2">
                    <text font="semibold" class="rounded-full px-2 py-1 text-xs {{ $status['tone'] }}">{{ $status['label'] }}</text>
                    <text font="mono" class="flex-1 text-sm text-theme-on-surface" :max-lines="1">{{ $run->model ?? 'Unknown model' }}</text>
                </row>
                <row class="w-full">
                    @foreach (['Latency' => number_format($run->latency_ms / 1000, 1).'s', 'Calls' => number_format($run->model_calls), 'Searches' => number_format($run->searches_performed), 'Items' => number_format($run->item_count)] as $label => $value)
                        <column class="flex-1 items-center gap-1">
                            <text font="mono" class="text-lg text-theme-on-surface">{{ $value }}</text>
                            <text class="text-[11] uppercase tracking-wide text-theme-on-surface-variant">{{ $label }}</text>
                        </column>
                    @endforeach
                </row>
                <divider class="border-theme-outline" />
                <row class="w-full items-center">
                    <text class="text-xs text-theme-on-surface-variant">Captured</text>
                    <spacer />
                    <text class="text-xs text-theme-on-surface">{{ $run->captured_at->format('M j, Y, g:i:s A') }}</text>
                </row>
                <row class="w-full items-center">
                    <text class="text-xs text-theme-on-surface-variant">Completed</text>
                    <spacer />
                    <text class="text-xs text-theme-on-surface">{{ $run->completed_at?->format('M j, Y, g:i:s A') ?? '—' }}</text>
                </row>
                @if ($run->error !== null)
                    <row class="w-full items-center gap-2 rounded-xl bg-theme-destructive/15 p-3">
                        <icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Warning" :size="16" class="text-theme-destructive" />
                        <text class="flex-1 text-sm text-theme-on-surface">{{ $run->error }}</text>
                    </row>
                @endif
            </column>

            @foreach ($blocks as $block)
                @if ($block['key'] === 'raw' && ! collect($blocks)->contains(fn ($candidate) => $candidate['index'] !== null))
                    <text class="text-sm text-theme-on-surface-variant">No agent events were recorded.</text>
                @endif
                <column class="w-full rounded-xl bg-theme-surface border border-theme-outline">
                    <pressable
                        ref="audit-{{ $block['key'] }}"
                        @press="toggle('{{ $block['key'] }}')"
                        a11y-label="{{ $block['title'] }}"
                        a11y-hint="{{ $block['open'] ? 'Collapses this section' : 'Expands this section' }}"
                        class="w-full px-3 py-3"
                    >
                        <row class="w-full items-center gap-3">
                            @if ($block['index'] !== null)
                                <text font="mono" class="rounded-full bg-theme-primary/20 px-2 py-1 text-xs text-theme-primary">{{ $block['index'] }}</text>
                            @endif
                            <text font="semibold" class="flex-1 text-sm text-theme-on-surface">{{ $block['title'] }}</text>
                            <icon
                                :ios="$block['open'] ? Ios::ChevronDown : Ios::ChevronRight"
                                :android="$block['open'] ? Android::ExpandMore : Android::ChevronRight"
                                :size="14"
                                class="text-theme-on-surface-variant"
                            />
                        </row>
                    </pressable>
                    @if ($block['open'])
                        <column class="w-full px-3 pb-3">
                            <text font="mono" :text="$block['body']" class="w-full rounded-lg bg-theme-background p-3 text-xs leading-snug text-theme-on-surface whitespace-pre-wrap select-text" />
                        </column>
                    @endif
                </column>
            @endforeach
        </column>
    </scroll-view>
@endif
