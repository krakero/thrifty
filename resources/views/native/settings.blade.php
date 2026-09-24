@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Support\Money')
@use('Illuminate\Support\Str')

<scroll-view class="w-full h-full bg-theme-background">
    <column class="w-full gap-6 px-4 pt-4 pb-10">
        @if ($error)
            <row class="w-full items-center gap-3 rounded-xl bg-theme-destructive/15 border border-theme-destructive/40 px-3 py-2">
                <icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Error" :size="16" class="text-theme-destructive" />
                <text class="flex-1 text-sm text-theme-on-surface">{{ $error }}</text>
                <icon ref="dismiss-error" @press="dismissError" :ios="Ios::Xmark" :android="Android::Close" :size="16" a11y-label="Dismiss error" class="text-theme-on-surface" />
            </row>
        @endif

        {{-- Find criteria --}}
        <column class="w-full gap-3">
            <text font="display" class="text-lg text-theme-on-background">Find criteria</text>
            <outlined-text-input
                ref="find-criteria"
                native:model.debounce.400ms="findCriteria"
                placeholder="Vintage band tees worth more than $40"
                :multiline="true"
                :min-lines="3"
                :max-lines="6"
                :max-length="1000"
                a11y-label="Find criteria"
                class="w-full"
            />
            <text ref="criteria-count" font="mono" class="self-end text-xs {{ mb_strlen($findCriteria) >= 1000 ? 'text-theme-primary' : 'text-theme-on-surface-variant' }}">{{ number_format(mb_strlen($findCriteria)) }}/1,000</text>
            {{--
                Preset pills, two per row: iOS flex rows shrink children rather than wrapping them, so the rows are
                explicit, and each scrolls sideways instead of squeezing labels at very large text sizes. Selection
                is drawn from the saved criteria alone, so tapping the active preset keeps it selected.
            --}}
            @php($presetRows = collect($presets)->map(fn ($preset, $index) => $preset + ['index' => $index])->chunk(2)->values())
            <column class="w-full gap-2">
                @foreach ($presetRows as $rowIndex => $row)
                    <scroll-view horizontal native:key="preset-row-{{ $rowIndex }}" class="w-full">
                        <row class="gap-2">
                            @foreach ($row as $preset)
                                @php($active = $findCriteria === $preset['value'])
                                <thrifty-pressable
                                    ref="preset-{{ $preset['index'] }}"
                                    @press="applyPreset({{ $preset['index'] }})"
                                    :press-scale="0.96"
                                    a11y-label="{{ $preset['label'] }} preset{{ $active ? ', selected' : '' }}"
                                    a11y-hint="Fills in the find criteria"
                                    class="shrink-0 min-h-[44] justify-center rounded-full px-4 {{ $active ? 'bg-theme-primary' : 'bg-theme-surface-variant border border-theme-outline' }}"
                                >
                                    <text font="semibold" :max-lines="1" class="text-sm {{ $active ? 'text-theme-on-primary' : 'text-theme-on-surface' }}">{{ $preset['label'] }}</text>
                                </thrifty-pressable>
                            @endforeach
                            @if ($loop->last && $findCriteria !== '')
                                <thrifty-pressable
                                    ref="clear-criteria"
                                    @press="clearFindCriteria"
                                    :press-scale="0.96"
                                    a11y-label="Clear find criteria"
                                    class="shrink-0 min-h-[44] justify-center rounded-full px-4 border border-theme-outline"
                                >
                                    <text font="semibold" :max-lines="1" class="text-sm text-theme-on-surface-variant">Clear</text>
                                </thrifty-pressable>
                            @endif
                        </row>
                    </scroll-view>
                @endforeach
            </column>
        </column>

        {{-- Concurrent processing --}}
        <column class="w-full gap-2">
            <row class="w-full items-center">
                <text font="semibold" class="flex-1 text-base text-theme-on-background">Concurrent processing</text>
                <text font="mono" class="text-base text-theme-primary">{{ $maxConcurrentFrames }}</text>
            </row>
            <slider
                ref="concurrent-processing"
                native:model="maxConcurrentFrames"
                :min="1"
                :max="$maxConcurrentLimit"
                :step="1"
                a11y-label="Concurrent processing: {{ $maxConcurrentFrames }} at a time"
                class="w-full"
            />
            <row class="w-full">
                <text font="mono" class="flex-1 text-xs text-theme-on-surface-variant">1</text>
                <text font="mono" class="text-xs text-theme-on-surface-variant">{{ $maxConcurrentLimit }}</text>
            </row>
            <text class="text-xs text-theme-on-surface-variant">How many frames are analyzed at the same time. This phone runs at most {{ $maxConcurrentLimit }} analyses in parallel; frames captured while every slot is busy are skipped.</text>
        </column>

        {{-- Scan frequency --}}
        <column class="w-full gap-2">
            <row class="w-full items-center">
                <text font="semibold" class="flex-1 text-base text-theme-on-background">Live scan frequency</text>
                <text font="mono" class="text-base text-theme-primary">{{ $scanIntervalSeconds }}s</text>
            </row>
            <slider
                ref="scan-frequency"
                native:model="scanIntervalSeconds"
                :min="1"
                :max="30"
                :step="1"
                a11y-label="Live scan frequency: every {{ $scanIntervalSeconds }} {{ Str::plural('second', $scanIntervalSeconds) }}"
                class="w-full"
            />
            <row class="w-full">
                <text font="mono" class="flex-1 text-xs text-theme-on-surface-variant">1s</text>
                <text font="mono" class="text-xs text-theme-on-surface-variant">30s</text>
            </row>
        </column>

        {{-- API keys --}}
        <column class="w-full gap-3">
            <text font="display" class="text-lg text-theme-on-background">API keys</text>
            <outlined-text-input
                ref="openai-key"
                native:model.debounce.400ms="openAiApiKey"
                label="OpenAI API key"
                placeholder="sk-..."
                :secure="true"
                supporting="Stored encrypted on this device. Frames go straight from your phone to OpenAI."
                class="w-full"
            />
            <button
                ref="get-api-key"
                variant="ghost"
                size="sm"
                label="Get an API key"
                icon-trailing="{{ Ios::ArrowUpRight->value }}"
                a11y-label="Get an OpenAI API key"
                a11y-hint="Opens the OpenAI API keys page"
                @press="openApiKeyPage"
                class="self-start min-h-[44]"
            />

            <text class="text-sm text-theme-on-surface-variant mt-2">eBay (optional). Add a client ID and secret to compare against active eBay listings.</text>
            <outlined-text-input
                ref="ebay-client-id"
                native:model.debounce.400ms="ebayClientId"
                label="eBay client ID"
                class="w-full"
            />
            <outlined-text-input
                ref="ebay-client-secret"
                native:model.debounce.400ms="ebayClientSecret"
                label="eBay client secret"
                :secure="true"
                class="w-full"
            />
        </column>

        {{-- Saved finds --}}
        <column class="w-full gap-3">
            <text class="text-xs uppercase text-theme-on-surface-variant">Saved inventory</text>
            <text font="display" class="text-lg text-theme-on-background">Saved finds</text>

            @forelse ($savedFinds as $item)
                <row class="w-full items-center gap-3 rounded-xl bg-theme-surface border border-theme-outline p-2">
                    <image src="{{ $item->thumbnailFile() }}" :fit="2" class="w-[48] h-[48] rounded-lg bg-theme-surface-variant" />
                    <column class="flex-1 gap-1">
                        <text font="semibold" class="text-sm text-theme-on-surface" :max-lines="1">{{ $item->name }}</text>
                        <text font="mono" class="text-sm text-theme-accent">{{ Money::resaleRange($item) }}</text>
                    </column>
                    {{-- An icon with a press handler is a labelled 44pt button on iOS; a pressable isn't. --}}
                    <icon
                        ref="delete-{{ $item->id }}"
                        @press="deleteFind('{{ $item->id }}')"
                        :ios="Ios::Trash"
                        :android="Android::DeleteOutline"
                        :size="18"
                        a11y-label="Delete {{ $item->name }}"
                        class="text-theme-destructive"
                    />
                </row>
            @empty
                <text class="text-sm text-theme-on-surface-variant">No saved finds.</text>
            @endforelse

            @if ($hasMoreFinds)
                <button ref="load-more" variant="secondary" label="Load more finds" @press="loadMoreFinds" class="w-full min-h-[44]" />
            @endif

            <button
                ref="delete-all"
                variant="destructive"
                label="Delete all finds"
                :disabled="$savedFinds->isEmpty()"
                @press="confirmDeleteAll"
                class="w-full mt-2 min-h-[44]"
            />
            <text class="text-xs text-theme-on-surface-variant text-center">Processing stats are kept.</text>
        </column>
    </column>
</scroll-view>
