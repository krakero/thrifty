@use('App\Icons\Ios')
@use('App\Icons\Android')
@use('App\Scanning\LiveScanState')
@use('Native\Mobile\Edge\Layouts\Builders\NavAction')

@php
    $cameraChoice = fn (string $id, string $label, string $facing, Ios $icon, Android $androidIcon, string $method) => NavAction::make($id)
        ->label($label)
        ->icon(
            ($state->facing === $facing ? Ios::Checkmark : $icon)->value,
            ios: ($state->facing === $facing ? Ios::Checkmark : $icon)->value,
            android: ($state->facing === $facing ? Android::Check : $androidIcon)->value,
        )
        ->press($method);
@endphp

<row native:key="scan-top-bar" class="w-full items-center gap-2 px-4 pt-3 pb-2">
    {{--
        thrifty-pressable puts the VoiceOver label and button trait on the menu itself (a theme button with :menu
        leaves the menu unlabelled) and keeps the label light on the dark pill. 44pt tall.
    --}}
    <thrifty-pressable
        ref="camera-select"
        native:key="camera-select"
        a11y-label="Camera: {{ $cameraLabel }}"
        a11y-hint="Chooses the back camera, the front camera, or turns the camera off"
        :menu="[
            $cameraChoice('camera-back', 'Back camera', LiveScanState::FacingBack, Ios::Camera, Android::PhotoCamera, 'useBackCamera'),
            $cameraChoice('camera-front', 'Front camera', LiveScanState::FacingFront, Ios::CameraRotate, Android::Cameraswitch, 'useFrontCamera'),
            NavAction::divider(),
            $cameraChoice('camera-off', 'Camera off', LiveScanState::FacingOff, Ios::Xmark, Android::NoPhotography, 'turnCameraOff'),
        ]"
        class="shrink-0 min-h-[44] justify-center rounded-full bg-theme-background/80 border border-theme-outline px-3"
    >
        <row class="items-center gap-1">
            <icon :ios="Ios::Camera" :android="Android::PhotoCamera" :size="14" class="text-theme-on-surface" />
            <text font="semibold" :max-lines="1" class="text-sm text-theme-on-surface">{{ $cameraLabel }}</text>
            <icon :ios="Ios::ChevronDown" :android="Android::ExpandMore" :size="10" class="text-theme-on-surface-variant" />
        </row>
    </thrifty-pressable>

    <spacer />

    @if ($state->scanning)
        <row ref="live-state" class="h-[34] items-center gap-2 rounded-full bg-theme-accent px-3">
            <column class="w-[7] h-[7] rounded-full bg-theme-on-accent" />
            <text font="semibold" :max-lines="1" class="text-xs text-theme-on-accent">Live</text>
        </row>
    @else
        <row ref="live-state" class="h-[34] items-center gap-2 rounded-full bg-theme-background/60 px-3">
            <column class="w-[7] h-[7] rounded-full bg-theme-on-surface-variant" />
            <text font="semibold" :max-lines="1" class="text-xs text-theme-on-surface-variant">{{ $cameraStarting ? 'Starting' : 'Paused' }}</text>
        </row>
    @endif

    {{-- An icon with a press handler is a labelled 44pt button on iOS. --}}
    <icon
        ref="open-settings"
        @press="openSettings"
        :ios="Ios::Gearshape"
        :android="Android::Settings"
        :size="20"
        a11y-label="Open settings"
        class="text-theme-on-surface"
    />
</row>
