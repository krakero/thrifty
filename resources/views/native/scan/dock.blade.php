@use('App\Icons\Ios')
@use('App\Icons\Android')

{{-- Scan actions, docked above the tab bar (the web app's bottom-nav dock buttons). --}}
<row class="w-full items-center justify-center gap-3 px-4 pt-2 pb-3">
    <pressable
        ref="toggle-live"
        @press="toggleLiveScan"
        :press-scale="0.95"
        a11y-label="{{ $state->scanning ? 'Stop live scanning' : 'Start live scanning' }}"
        :class="$state->scanning ? 'flex-1 h-[56] items-center justify-center rounded-2xl bg-theme-accent' : 'flex-1 h-[56] items-center justify-center rounded-2xl bg-theme-background/70 border border-theme-outline'"
    >
        <column class="items-center gap-1">
            @if ($state->scanning)
                <icon :ios="Ios::StopFill" :android="Android::Stop" :size="18" class="text-theme-on-accent" />
                <text font="semibold" class="text-xs text-theme-on-accent">Stop</text>
            @else
                <icon :ios="Ios::Viewfinder" :android="Android::CenterFocusStrong" :size="20" class="text-theme-on-surface" />
                <text font="semibold" class="text-xs text-theme-on-surface">Live</text>
            @endif
        </column>
    </pressable>

    <pressable
        ref="snapshot"
        @press="takeSnapshot"
        :press-scale="0.93"
        a11y-label="Take snapshot"
        class="w-[72] h-[72] items-center justify-center rounded-full bg-theme-primary"
    >
        <column class="items-center gap-1">
            <icon :ios="Ios::CameraFill" :android="Android::PhotoCamera" :size="22" class="text-theme-on-primary" />
            <text font="semibold" class="text-xs text-theme-on-primary">Snap</text>
        </column>
    </pressable>

    <pressable
        ref="upload"
        @press="upload"
        :press-scale="0.95"
        a11y-label="Upload a photo or video"
        class="flex-1 h-[56] items-center justify-center rounded-2xl bg-theme-background/70 border border-theme-outline"
    >
        <column class="items-center gap-1">
            <icon :ios="Ios::PhotoOnRectangle" :android="Android::AddPhotoAlternate" :size="20" class="text-theme-on-surface" />
            <text font="semibold" class="text-xs text-theme-on-surface">Upload</text>
        </column>
    </pressable>
</row>
