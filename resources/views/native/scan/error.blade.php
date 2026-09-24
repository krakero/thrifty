@use('App\Icons\Ios')
@use('App\Icons\Android')

@if ($state->error)
    <row ref="error-banner" class="w-full items-center gap-2 rounded-xl bg-theme-destructive/90 pl-3 py-1">
        <icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Error" :size="16" class="text-theme-on-destructive" />
        <text class="flex-1 text-sm text-theme-on-destructive">{{ $state->error }}</text>
        @if ($state->errorNeedsCameraPermission)
            <button ref="error-open-ios-settings" variant="secondary" size="sm" label="iOS Settings" a11y-label="Open iOS Settings for Thrifty" a11y-hint="Turn on camera access there" @press="openAppSettings" class="min-h-[44]" />
        @endif
        @if ($state->errorNeedsApiKey)
            <button ref="error-open-settings" variant="secondary" size="sm" label="Settings" a11y-label="Open Settings" @press="openSettings" class="min-h-[44]" />
        @endif
        <icon
            ref="dismiss-error"
            @press="dismissError"
            :ios="Ios::Xmark"
            :android="Android::Close"
            :size="16"
            a11y-label="Dismiss error"
            class="text-theme-on-destructive"
        />
    </row>
@endif
