@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('Native\Mobile\Edge\Layouts\Builders\NavAction')

<row class="w-full items-center gap-2 px-4 pt-3 pb-2">
    <pressable
        ref="camera-select"
        a11y-label="Select camera"
        :menu="[
            NavAction::make('camera-back')->label('Back camera')->icon(ios: Ios::Camera->value, android: Android::PhotoCamera->value)->press('useBackCamera'),
            NavAction::make('camera-front')->label('Front camera')->icon(ios: Ios::CameraRotate->value, android: Android::Cameraswitch->value)->press('useFrontCamera'),
            NavAction::divider(),
            NavAction::make('camera-off')->label('Camera off')->icon(ios: Ios::Xmark->value, android: Android::NoPhotography->value)->press('turnCameraOff'),
        ]"
        class="rounded-full bg-theme-background/60 border border-theme-outline px-3 h-[34]"
    >
        <row class="h-[34] items-center gap-1">
            <icon :ios="Ios::Camera" :android="Android::PhotoCamera" :size="14" class="text-theme-on-surface" />
            <text font="semibold" class="text-sm text-theme-on-surface">{{ $cameraLabel }}</text>
            <icon :ios="Ios::ChevronDown" :android="Android::ExpandMore" :size="10" class="text-theme-on-surface-variant" />
        </row>
    </pressable>

    <spacer />

    @if ($state->scanning)
        <row ref="live-state" a11y-label="Live" class="h-[34] items-center gap-2 rounded-full bg-theme-accent px-3">
            <column class="w-[7] h-[7] rounded-full bg-theme-on-accent" />
            <text font="semibold" class="text-xs text-theme-on-accent">Live</text>
        </row>
    @else
        <row ref="live-state" a11y-label="Paused" class="h-[34] items-center gap-2 rounded-full bg-theme-background/60 px-3">
            <column class="w-[7] h-[7] rounded-full bg-theme-on-surface-variant" />
            <text font="semibold" class="text-xs text-theme-on-surface-variant">Paused</text>
        </row>
    @endif

    <pressable
        ref="open-settings"
        @press="openSettings"
        a11y-label="Open settings"
        class="w-[34] h-[34] items-center justify-center rounded-xl bg-theme-background/60 border border-theme-outline"
    >
        <icon :ios="Ios::Gearshape" :android="Android::Settings" :size="16" class="text-theme-on-surface" />
    </pressable>
</row>
