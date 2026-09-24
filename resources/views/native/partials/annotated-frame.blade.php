{{--
    A frame with a tappable outline around every find in it, the selected one highlighted.

    Each box is its own full-size layer: an empty band pushes it down to yMin, an empty column pushes it across to
    xMin, and the box takes the remaining percentage of the frame. Boxes are normalized 0–1000, so they line up with
    the image whatever width the screen gives it, since the frame keeps the image's aspect ratio.

    @var \Illuminate\Support\Collection<int, \App\Models\Item> $items
    @var string $activeItemId
    @var array{path: string, annotated: bool, aspectRatio: float} $frame
--}}
@php
    $percent = fn (int $value): string => rtrim(rtrim(number_format(max(0, min(1000, $value)) / 10, 2, '.', ''), '0'), '.').'%';
    $boxed = $frame['annotated']
        ? $items->filter(fn ($frameItem) => $frameItem->boundingBox() !== null)->sortBy(fn ($frameItem) => $frameItem->id === $activeItemId)
        : collect();
@endphp

<stack ref="annotated-frame" class="w-full aspect-[{{ $frame['aspectRatio'] }}] rounded-2xl bg-black">
    <image src="{{ $frame['path'] }}" :fit="1" class="w-full h-full rounded-2xl" />

    @foreach ($boxed as $frameItem)
        @php
            $box = $frameItem->boundingBox();
            $isActive = $frameItem->id === $activeItemId;
        @endphp
        <column class="w-full h-full">
            <column class="w-full" height="{{ $percent($box['yMin']) }}" />
            <row class="w-full" height="{{ $percent($box['yMax'] - $box['yMin']) }}">
                <column class="h-full" width="{{ $percent($box['xMin']) }}" />
                <pressable
                    ref="box-{{ $frameItem->id }}"
                    @press="selectItem('{{ $frameItem->id }}')"
                    width="{{ $percent($box['xMax'] - $box['xMin']) }}"
                    a11y-label="{{ $frameItem->name }}"
                    a11y-hint="{{ $isActive ? 'Selected find' : 'Shows this find' }}"
                    class="h-full rounded-[5] {{ $isActive ? 'border-4 border-theme-primary bg-theme-primary/15' : 'border-2 border-white/70' }}"
                />
            </row>
        </column>
    @endforeach
</stack>
