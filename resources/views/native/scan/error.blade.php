@use('App\Icons\Ios')
@use('App\Icons\Android')

@if ($state->error)
    <row ref="error-banner" class="w-full items-center gap-3 rounded-xl bg-theme-destructive/90 px-3 py-2">
        <icon :ios="Ios::ExclamationmarkTriangleFill" :android="Android::Error" :size="16" class="text-theme-on-destructive" />
        <text class="flex-1 text-sm text-theme-on-destructive">{{ $state->error }}</text>
        @if ($state->errorNeedsApiKey)
            <pressable ref="error-open-settings" @press="openSettings" a11y-label="Open Settings" class="rounded-full bg-theme-on-destructive px-3 py-1">
                <text font="semibold" class="text-xs text-theme-destructive">Settings</text>
            </pressable>
        @endif
        <pressable ref="dismiss-error" @press="dismissError" a11y-label="Dismiss error" class="p-1">
            <icon :ios="Ios::Xmark" :android="Android::Close" :size="16" class="text-theme-on-destructive" />
        </pressable>
    </row>
@endif
