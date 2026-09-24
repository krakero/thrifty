@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Enums\ScanSource')
@use('App\Scanning\LiveScanState')

<stack class="w-full h-full bg-theme-background">
    {{-- Camera stage --}}
    <thrifty-camera
        ref="camera"
        :scanning="$state->scanning && $state->source === ScanSource::Camera->value"
        :interval="$scanIntervalSeconds"
        facing="{{ $state->facing }}"
        frames-directory="{{ $framesDirectory }}"
        a11y-label="Camera preview"
        class="w-full h-full"
    />

    @if ($state->stillPreviewPath && $state->source === ScanSource::Image->value)
        <image src="{{ $state->stillPreviewPath }}" :fit="1" alt="Uploaded photo" class="w-full h-full bg-theme-background" />
    @elseif ($state->facing === LiveScanState::FacingOff && $state->source !== ScanSource::Video->value)
        <column class="w-full h-full items-center justify-center bg-theme-background">
            <column class="w-[98] h-[98] items-center justify-center rounded-full border border-theme-outline bg-theme-surface/40">
                <icon :ios="Ios::Viewfinder" :android="Android::CenterFocusStrong" :size="44" class="text-theme-on-surface-variant" />
            </column>
        </column>
    @endif

    @include('native.scan.flash')

    {{-- Overlay --}}
    <column class="w-full h-full">
        @include('native.scan.top-bar')

        <column class="w-full flex-1 px-4 gap-3">
            @include('native.scan.stats')

            @if ($state->source)
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
                        @include('native.partials.item-card', ['item' => $item, 'from' => 'scan'])
                    @endforeach
                </column>
            </scroll-view>

            @if ($revealing)
                {{-- Re-render every half second while finds are still streaming in. --}}
                <spacer native:poll="500ms" class="h-[0]" />
            @endif

            @include('native.scan.error')
        </column>

        @include('native.scan.dock')
    </column>
</stack>
