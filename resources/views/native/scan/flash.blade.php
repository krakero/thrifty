{{-- The snapshot flash: a white wash that clears on the next poll tick. --}}
@if ($flashing)
    <column ref="snapshot-flash" native:poll="350ms" class="w-full h-full bg-white/80" />
@endif
