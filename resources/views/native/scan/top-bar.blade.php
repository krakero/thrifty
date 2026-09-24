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

<row class="w-full items-center gap-2 px-4 pt-3 pb-2">
    {{-- A real button (not a pressable) so VoiceOver gets a labelled button; the menu marks the current camera. --}}
    <button
        ref="camera-select"
        variant="secondary"
        size="sm"
        class="glass"
        icon="{{ Ios::Camera->value }}"
        icon-trailing="{{ Ios::ChevronDown->value }}"
        label="{{ $cameraLabel }}"
        a11y-label="Camera: {{ $cameraLabel }}"
        a11y-hint="Chooses the back camera, the front camera, or turns the camera off"
        :menu="[
            $cameraChoice('camera-back', 'Back camera', LiveScanState::FacingBack, Ios::Camera, Android::PhotoCamera, 'useBackCamera'),
            $cameraChoice('camera-front', 'Front camera', LiveScanState::FacingFront, Ios::CameraRotate, Android::Cameraswitch, 'useFrontCamera'),
            NavAction::divider(),
            $cameraChoice('camera-off', 'Camera off', LiveScanState::FacingOff, Ios::Xmark, Android::NoPhotography, 'turnCameraOff'),
        ]"
    />

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
