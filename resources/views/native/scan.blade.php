@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Enums\ScanSource')

<stack class="w-full h-full bg-theme-background">
    {{-- Camera stage --}}
    @if ($state->facing === 'off')
        <thrifty-camera ref="camera" native:key="scan-camera" :scanning="false" :interval="$scanIntervalSeconds" facing="off" frames-directory="{{ $framesDirectory }}" class="w-full h-full" />
    @else
        <thrifty-camera
            ref="camera"
            native:key="scan-camera"
            :scanning="$state->scanning && $state->cameraRunning && $state->source === ScanSource::Camera->value"
            :interval="$scanIntervalSeconds"
            facing="{{ $state->facing }}"
            frames-directory="{{ $framesDirectory }}"
            a11y-label="Camera preview"
            class="w-full h-full"
        />
    @endif

    @if ($stillPreview)
        {{-- Cover, like the web's object-fit: cover still preview. --}}
        <image src="{{ $stillPreview }}" :fit="2" alt="{{ $state->sourceLabel }}" class="w-full h-full bg-theme-background" />
    @elseif ($state->sessionId === null)
        <column class="w-full h-full items-center justify-center bg-theme-background">
            <column class="w-[98] h-[98] items-center justify-center rounded-full border border-theme-outline bg-theme-surface/40">
                <icon :ios="Ios::Viewfinder" :android="Android::CenterFocusStrong" :size="44" class="text-theme-on-surface-variant" />
            </column>
        </column>
    @endif

    {{-- The web's camera-shade: darken the top and bottom so the overlay stays legible over bright scenes. --}}
    <column class="w-full h-full">
        <column class="w-full h-[180] bg-gradient-to-b from-black/70 to-transparent" />
        <spacer />
        <column class="w-full h-[220] bg-gradient-to-t from-black/70 to-transparent" />
    </column>

    @include('native.scan.flash')

    {{--
        Overlay. Keyed so its nodes keep their ids while the stage above changes (a new still, the flash): an id
        change rebuilds the native view, which closes an open camera menu.
    --}}
    <column native:key="scan-overlay" class="w-full h-full">
        @include('native.scan.top-bar')

        <column class="w-full flex-1 px-4 gap-3">
            @include('native.scan.stats')

            @if ($state->sessionId)
                <row ref="source-caption" class="items-center gap-1 self-start rounded-full bg-theme-background/60 px-3 py-1">
                    @if ($state->source === ScanSource::Camera->value)
                        <icon :ios="Ios::Camera" :android="Android::PhotoCamera" :size="13" class="text-theme-on-surface" />
                    @elseif ($state->source === ScanSource::Image->value)
                        <icon :ios="Ios::Photo" :android="Android::Image" :size="13" class="text-theme-on-surface" />
                    @else
                        <icon :ios="Ios::Video" :android="Android::Videocam" :size="13" class="text-theme-on-surface" />
                    @endif
                    <text class="text-xs text-theme-on-surface">{{ $state->sourceLabel }}</text>
                </row>
            @endif

            <scroll-view class="w-full flex-1">
                <column class="w-full gap-2 pb-2">
                    @foreach ($liveItems as $item)
                        <column native:key="find-{{ $item->id }}" class="w-full">
                            @include('native.partials.item-card', ['item' => $item, 'from' => 'scan'])
                        </column>
                    @endforeach
                </column>
            </scroll-view>

            {{-- Re-render ticks: finds still streaming in, a snapshot waiting on the camera, analyses to check on. --}}
            @if ($revealing)
                <spacer native:poll="500ms" class="h-[0]" />
            @elseif ($state->inFlight() > 0)
                <spacer native:poll="5s" class="h-[0]" />
            @endif

            @include('native.scan.error')
        </column>

        @include('native.scan.dock')
    </column>
</stack>
