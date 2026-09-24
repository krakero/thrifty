{{--
    @var \App\Models\Item|null $item
    @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\Item> $frameItems
    @var array{path: string, annotated: bool, aspectRatio: float} $frame
    @var list<array{label: string, value: string, tone: string}> $priceRows
    @var list<array{type: string, title: string, url: string|null, price: string}> $evidence
    @var string $summary
    @var string $confidence
--}}
@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Support\Money')
@use('App\Support\PriceText')

@if ($item === null)
    <column class="w-full h-full items-center justify-center gap-3 p-8 bg-theme-background">
        <icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Warning" :size="36" class="text-theme-on-surface-variant" />
        <text font="display" class="text-xl text-theme-on-background text-center">Find not found.</text>
        <text class="text-sm text-theme-on-surface-variant text-center">It may have been deleted.</text>
        <button ref="go-back" variant="secondary" size="lg" @navigate.back>Go back</button>
    </column>
@else
    <scroll-view class="w-full h-full bg-theme-background">
        <column class="w-full gap-5 px-4 pt-2 pb-10">
            @include('native.partials.annotated-frame', ['items' => $frameItems, 'activeItemId' => $item->id, 'frame' => $frame])

            <row class="w-full gap-3">
                <thrifty-pressable ref="share-find" @press="share" a11y-label="Share find as image" class="flex-1 min-h-[44] justify-center rounded-full bg-theme-surface border border-theme-outline px-4">
                    <row class="w-full items-center justify-center gap-2">
                        <icon :ios="Ios::SquareAndArrowUp" :android="Android::Share" :size="16" class="text-theme-on-surface" />
                        <text font="semibold" class="text-sm text-theme-on-surface">Share</text>
                    </row>
                </thrifty-pressable>
                <thrifty-pressable ref="delete-find" @press="confirmDelete" a11y-label="Delete find" a11y-hint="Asks before deleting" class="flex-1 min-h-[44] justify-center rounded-full bg-theme-destructive/15 border border-theme-destructive/40 px-4">
                    <row class="w-full items-center justify-center gap-2">
                        <icon :ios="Ios::Trash" :android="Android::Delete" :size="16" class="text-theme-destructive" />
                        <text font="semibold" class="text-sm text-theme-destructive">Delete</text>
                    </row>
                </thrifty-pressable>
            </row>

            @if ($error !== null)
                <row class="w-full items-center gap-3 rounded-xl bg-theme-destructive/15 border border-theme-destructive/40 p-3">
                    <icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Warning" :size="18" class="text-theme-destructive" />
                    <text class="flex-1 text-sm text-theme-on-surface">{{ $error }}</text>
                    <thrifty-pressable ref="dismiss-error" @press="dismissError" a11y-label="Dismiss error" class="w-[44] h-[44] items-center justify-center">
                        <icon :ios="Ios::Xmark" :android="Android::Close" :size="14" class="text-theme-on-surface-variant" />
                    </thrifty-pressable>
                </row>
            @endif

            <column class="w-full gap-2">
                <text font="semibold" class="text-xs uppercase tracking-widest text-theme-on-surface-variant">
                    {{ $frameItems->count() }} {{ Str::plural('item', $frameItems->count()) }} found in this frame
                </text>
                <scroll-view axis="horizontal" :shows-indicators="false" class="w-full">
                    <row class="gap-2">
                        @foreach ($frameChips as $frameItem)
                            @php
                                $isActive = $frameItem->id === $item->id;
                            @endphp
                            <thrifty-pressable
                                ref="frame-item-{{ $frameItem->id }}"
                                @press="selectItem('{{ $frameItem->id }}')"
                                a11y-label="{{ $frameItem->name }}, {{ PriceText::spokenResale($frameItem) }}"
                                a11y-hint="{{ $isActive ? 'Selected find' : 'Shows this find' }}"
                                class="w-[168] gap-1 rounded-xl p-3 border {{ $isActive ? 'bg-theme-primary/15 border-theme-primary' : 'bg-theme-surface border-theme-outline' }}"
                            >
                                <text class="text-[11] uppercase text-theme-on-surface-variant" :max-lines="1">{{ $frameItem->category }}</text>
                                <text font="semibold" class="text-sm text-theme-on-surface" :max-lines="1">{{ $frameItem->name }}</text>
                                {{-- The chip is a fixed width, so at the largest text sizes the range may break after its dash (never mid-number). --}}
                                <text font="mono" class="text-sm text-theme-accent">{{ Money::resaleRange($frameItem) }}</text>
                            </thrifty-pressable>
                        @endforeach
                    </row>
                </scroll-view>
            </column>

            <thrifty-pressable ref="agent-activity" @press="openActivity" a11y-label="Agent activity" a11y-hint="Shows what the agent did to value this frame" class="w-full rounded-xl bg-theme-surface border border-theme-outline px-4 py-3">
                <row class="w-full items-center gap-3">
                    <icon :ios="Ios::Cpu" :android="Android::SmartToy" :size="18" class="text-theme-primary" />
                    <text font="semibold" class="flex-1 text-sm text-theme-on-surface">Agent activity</text>
                    <icon :ios="Ios::ChevronRight" :android="Android::ChevronRight" :size="14" class="text-theme-on-surface-variant" />
                </row>
            </thrifty-pressable>

            <column class="w-full gap-2">
                <text font="semibold" class="text-xs uppercase tracking-widest text-theme-on-surface-variant">{{ $item->category }} · {{ $confidence }} confidence</text>
                <text font="display" class="text-2xl text-theme-on-background">{{ $item->name }}</text>
                <row class="w-full items-center gap-2">
                    @if ($item->isRepeat())
                        <text font="semibold" class="rounded-full bg-theme-primary/20 px-2 py-1 text-xs text-theme-primary">{{ PriceText::keepTogether('Seen '.$item->seen_count.'×') }}</text>
                    @endif
                    <text class="rounded-full bg-theme-surface-variant px-2 py-1 text-xs text-theme-on-surface">{{ $item->condition }}</text>
                </row>
                <text class="text-base leading-relaxed text-theme-on-surface">{{ $item->description }}</text>
            </column>

            <column class="w-full gap-3 rounded-2xl bg-theme-surface border border-theme-outline p-4">
                @php
                    [$resale, $tag] = array_slice($priceRows, 0, 2);
                @endphp
                <column class="w-full gap-1 rounded-xl bg-theme-accent/15 p-3">
                    <text class="text-[11] uppercase text-theme-accent" :max-lines="1">{{ $resale['label'] }}</text>
                    <text font="mono" class="text-2xl text-theme-accent">{{ PriceText::keepTogether($resale['value']) }}</text>
                </column>
                @foreach (array_chunk([$tag, ...array_slice($priceRows, 2)], 2) as $pair)
                    <row class="w-full gap-3">
                        @foreach ($pair as $row)
                            <column class="flex-1 gap-1 rounded-xl p-3 {{ $row['tone'] === 'primary' ? 'bg-theme-primary/15' : 'bg-theme-surface-variant' }}">
                                <text class="text-[11] uppercase {{ $row['tone'] === 'primary' ? 'text-theme-primary' : 'text-theme-on-surface-variant' }}" :max-lines="1">{{ $row['label'] }}</text>
                                <text font="mono" class="text-base {{ $row['tone'] === 'primary' ? 'text-theme-primary' : 'text-theme-on-surface' }}">{{ PriceText::keepTogether($row['value']) }}</text>
                            </column>
                        @endforeach
                    </row>
                @endforeach
                @if ($summary !== '')
                    <text class="text-sm leading-relaxed text-theme-on-surface-variant">{{ $summary }}</text>
                @endif
            </column>

            @if ($evidence !== [])
                <column class="w-full gap-2">
                    <text font="display" class="text-lg text-theme-on-background">Sold comps & web results</text>
                    @foreach ($evidence as $index => $comparable)
                        @php
                            $typeClass = match ($comparable['type']) {
                                'sold' => ['bg-theme-accent/20', 'text-theme-accent'],
                                'active' => ['bg-theme-primary/20', 'text-theme-primary'],
                                default => ['bg-theme-surface-variant', 'text-theme-on-surface-variant'],
                            };
                        @endphp
                        @if ($comparable['url'] !== null)
                            <thrifty-pressable
                                ref="source-{{ $index }}"
                                @press="openSource({{ $index }})"
                                a11y-label="{{ $comparable['type'] }}: {{ $comparable['title'] }}, {{ $comparable['price'] }}"
                                a11y-hint="Opens the listing"
                                class="w-full rounded-xl bg-theme-surface border border-theme-outline px-3 py-3"
                            >
                                @include('native.partials.comparable-row', ['comparable' => $comparable, 'typeClass' => $typeClass])
                            </thrifty-pressable>
                        @else
                            <column ref="source-{{ $index }}" class="w-full rounded-xl bg-theme-surface border border-theme-outline px-3 py-3">
                                @include('native.partials.comparable-row', ['comparable' => $comparable, 'typeClass' => $typeClass])
                            </column>
                        @endif
                    @endforeach
                </column>
            @endif

            <column class="w-full rounded-2xl bg-theme-surface border border-theme-outline px-4 py-2">
                @foreach ([
                    'Brand' => $item->brand ?? 'Unknown',
                    'Model' => $item->model ?? 'Unknown',
                    'Condition' => $item->condition,
                    'Seen' => $item->seen_count.' '.Str::plural('time', $item->seen_count),
                    'First seen' => $firstSeen,
                    'Last seen' => $lastSeen,
                ] as $label => $value)
                    @if (! $loop->first)
                        <divider class="border-theme-outline" />
                    @endif
                    <row class="w-full items-start gap-3 py-2">
                        <text class="text-sm text-theme-on-surface-variant" :max-lines="1">{{ $label }}</text>
                        <text class="flex-1 text-sm text-theme-on-surface text-right">{{ $value }}</text>
                    </row>
                @endforeach
            </column>
        </column>
    </scroll-view>
@endif
