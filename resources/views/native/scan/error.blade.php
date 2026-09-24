@use('App\Icons\Ios')
@use('App\Icons\Android')

@if ($state->error)
    <row ref="error-banner" class="w-full items-center gap-2 rounded-xl bg-theme-destructive/90 pl-3 py-1">
        <icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Error" :size="16" class="text-theme-on-destructive" />
        <text class="flex-1 text-sm text-theme-on-destructive">{{ $state->error }}</text>
        @if ($state->errorNeedsCameraPermission)
            <thrifty-pressable ref="error-open-ios-settings" @press="openAppSettings" a11y-label="Open iOS Settings for Thrifty" a11y-hint="Turn on camera access there" class="shrink-0 min-h-[44] justify-center rounded-full bg-theme-on-destructive px-3">
                <text font="semibold" :max-lines="1" class="text-xs text-theme-destructive">iOS Settings</text>
            </thrifty-pressable>
        @endif
        @if ($state->errorNeedsApiKey)
            <thrifty-pressable ref="error-open-settings" @press="openSettings" a11y-label="Open Settings" class="shrink-0 min-h-[44] justify-center rounded-full bg-theme-on-destructive px-3">
                <text font="semibold" :max-lines="1" class="text-xs text-theme-destructive">Settings</text>
            </thrifty-pressable>
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
