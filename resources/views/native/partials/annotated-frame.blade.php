{{--
    A frame with a tappable outline around every find in it, the selected one highlighted.

    Each box is its own full-size layer split by flex-grow ratios between empty (zero-size) children: vertically
    yMin : (yMax − yMin) : (1000 − yMax), and the same horizontally inside the middle band. Boxes are normalized 0–1000,
    so the ratios line up with the image at any size because the frame keeps the image's aspect ratio. (Percent sizes
    resolve against the screen on iOS, not the parent, so they can't be used here.)

    Zero-ratio spacers are left out rather than given a grow of 0: an empty column renders as `Color.clear`, which a
    grow-0 slot measures at SwiftUI's default 10pt and shifts an edge-touching box. The box itself always gets a grow of
    at least 1 (0.1% of the frame) for the same reason.

    Later layers are hit-tested first, so boxes are drawn largest first: a small box inside a big one stays tappable, and
    the selected box is never raised above smaller ones (its thicker outline and tint mark it instead).

    @var \Illuminate\Support\Collection<int, \App\Models\Item> $items
    @var string $activeItemId
    @var array{path: string, annotated: bool, aspectRatio: float} $frame
--}}
@php
    $boxed = $frame['annotated']
        ? $items->filter(fn ($frameItem) => $frameItem->boundingBox() !== null)
            ->sortByDesc(fn ($frameItem) => \App\NativeComponents\ItemDetail::boxArea($frameItem->boundingBox()))
            ->values()
        : collect();
@endphp

<stack ref="annotated-frame" class="w-full aspect-[{{ $frame['aspectRatio'] }}] rounded-2xl bg-black">
    <image src="{{ $frame['path'] }}" :fit="1" class="w-full h-full rounded-2xl" />

    @foreach ($boxed as $frameItem)
        @php
            $box = \App\NativeComponents\ItemDetail::boxRatios($frameItem->boundingBox());
            $isActive = $frameItem->id === $activeItemId;
        @endphp
        <column ref="box-layer-{{ $frameItem->id }}" class="w-full h-full">
            @if ($box['top'] > 0)
                <column class="w-full" :flexGrow="$box['top']" :flexShrink="1" />
            @endif
            <row class="w-full" :flexGrow="$box['height']" :flexShrink="1">
                @if ($box['left'] > 0)
                    <column class="h-full" :flexGrow="$box['left']" :flexShrink="1" />
                @endif
                <column class="h-full" :flexGrow="$box['width']" :flexShrink="1">
                    <thrifty-pressable
                        ref="box-{{ $frameItem->id }}"
                        @press="selectItem('{{ $frameItem->id }}')"
                        a11y-label="{{ $frameItem->name }}"
                        a11y-hint="{{ $isActive ? 'Selected find' : 'Shows this find' }}"
                        class="w-full h-full rounded-[5] {{ $isActive ? 'border-theme-primary border-[4] bg-theme-primary/15' : 'border-white/70 border-[2]' }}"
                    />
                </column>
                @if ($box['right'] > 0)
                    <column class="h-full" :flexGrow="$box['right']" :flexShrink="1" />
                @endif
            </row>
            @if ($box['bottom'] > 0)
                <column class="w-full" :flexGrow="$box['bottom']" :flexShrink="1" />
            @endif
        </column>
    @endforeach
</stack>
