@use('App\Icons\Ios')

{{--
    Scan actions, docked above the tab bar (the web app's bottom-nav dock). Native buttons, so VoiceOver gets
    labelled buttons with the button trait; `pressable` rows are invisible to it on iOS.
--}}
<row native:key="scan-dock" class="w-full items-center justify-center gap-3 px-4 pt-2 pb-3">
    @if ($state->scanning || $cameraStarting)
        <button
            ref="toggle-live"
            variant="accent"
            size="lg"
            icon="{{ Ios::StopFill->value }}"
            label="Stop"
            a11y-label="Stop live scanning"
            @press="toggleLiveScan"
            class="flex-1 min-h-[52]"
        />
    @else
        <button
            ref="toggle-live"
            variant="secondary"
            size="lg"
            class="glass flex-1 min-h-[52]"
            icon="{{ Ios::Viewfinder->value }}"
            label="Live"
            a11y-label="Start live scanning"
            @press="toggleLiveScan"
        />
    @endif

    <button
        ref="snapshot"
        variant="primary"
        size="lg"
        icon="{{ Ios::CameraFill->value }}"
        label="Snap"
        a11y-label="Take snapshot"
        @press="takeSnapshot"
        class="min-h-[52]"
    />

    <button
        ref="upload"
        variant="secondary"
        size="lg"
        class="glass flex-1 min-h-[52]"
        icon="{{ Ios::PhotoOnRectangle->value }}"
        label="Upload"
        a11y-label="Upload a photo or video"
        @press="upload"
    />
</row>
