@use('App\Icons\Ios')
@use('App\Icons\Android')

{{--
    Scan actions, docked above the tab bar (the web app's bottom-nav dock). thrifty-pressable gives VoiceOver a
    labelled button while keeping our own look: the icon sits above a single-line label, so nothing wraps inside
    a narrow button, and the light-on-dark colours stay legible (theme glass buttons tint their label with the
    variant colour, which read as disabled).
--}}
<row native:key="scan-dock" class="w-full items-center justify-center gap-3 px-4 pt-2 pb-3">
    <thrifty-pressable
        ref="toggle-live"
        @press="toggleLiveScan"
        :press-scale="0.95"
        a11y-label="{{ $state->scanning || $cameraStarting ? 'Stop live scanning' : 'Start live scanning' }}"
        :class="$state->scanning || $cameraStarting
            ? 'flex-1 min-h-[56] items-center justify-center gap-1 rounded-2xl bg-theme-accent px-2 py-2'
            : 'flex-1 min-h-[56] items-center justify-center gap-1 rounded-2xl bg-theme-background/80 border border-theme-outline px-2 py-2'"
    >
        @if ($state->scanning || $cameraStarting)
            <icon :ios="Ios::StopFill" :android="Android::Stop" :size="18" class="text-theme-on-accent" />
            <text font="semibold" :max-lines="1" class="text-xs text-theme-on-accent">Stop</text>
        @else
            <icon :ios="Ios::Viewfinder" :android="Android::CenterFocusStrong" :size="20" class="text-theme-on-surface" />
            <text font="semibold" :max-lines="1" class="text-xs text-theme-on-surface">Live</text>
        @endif
    </thrifty-pressable>

    <thrifty-pressable
        ref="snapshot"
        @press="takeSnapshot"
        :press-scale="0.93"
        a11y-label="Take snapshot"
        class="w-[72] h-[72] items-center justify-center gap-1 rounded-full bg-theme-primary"
    >
        <icon :ios="Ios::CameraFill" :android="Android::PhotoCamera" :size="22" class="text-theme-on-primary" />
        <text font="semibold" :max-lines="1" class="text-xs text-theme-on-primary">Snap</text>
    </thrifty-pressable>

    <thrifty-pressable
        ref="upload"
        @press="upload"
        :press-scale="0.95"
        a11y-label="Upload a photo or video"
        class="flex-1 min-h-[56] items-center justify-center gap-1 rounded-2xl bg-theme-background/80 border border-theme-outline px-2 py-2"
    >
        <icon :ios="Ios::PhotoOnRectangle" :android="Android::AddPhotoAlternate" :size="20" class="text-theme-on-surface" />
        <text font="semibold" :max-lines="1" class="text-xs text-theme-on-surface">Upload</text>
    </thrifty-pressable>
</row>
