<?php

/**
 * Native UI — Theme Tokens
 *
 * Published via `php artisan vendor:publish --tag=native-ui-config`.
 * Edit to customize your app's visual identity in one place.
 *
 * For dynamic per-tenant theming, use Nativephp\NativeUi\Theme::merge([...])
 * from a service provider. Runtime merges deep-merge on top of these values.
 *
 * Decision log: /docs/NATIVE-UI-REWRITE-PLAN.md (D — theme layer)
 */

return [

    /*
    |---------------------------------------------------------------------------
    | Theme
    |---------------------------------------------------------------------------
    |
    | 17 color tokens, 4 radii, 4 font sizes, font family.
    |
    | "on-X" means "color of content placed ON a surface of color X"
    |   — i.e., text/icons on that background.
    |
    | Color tokens accept:
    |   - CSS hex: '#B91C1C', '#F00', or with alpha '#8B5CF680' (#RRGGBBAA)
    |   - Tailwind palette names: 'red-300', 'orange-800'
    |   - Opacity modifiers on either: 'red-300/20', '#8B5CF6/50'
    |
    | Dark mode is auto-derived from `light` when `dark` is not set. To opt
    | into explicit dark tokens, fill out the `dark` block.
    |
    | The default pairs meet WCAG AA (4.5:1) — if you customize, keep each
    | `on-*` color at 4.5:1 contrast against its background token.
    |
    */

    'theme' => [

        'light' => [
            // Thrifty is dark-only: light and dark share one warm-charcoal palette.
            'primary' => '#FF6B4A',
            'on-primary' => '#1A0F0B',

            'secondary' => '#3A342E',
            'on-secondary' => '#F5EFE6',

            'surface' => '#1E1B18',
            'on-surface' => '#F5EFE6',
            'background' => '#141210',
            'on-background' => '#F5EFE6',

            'surface-variant' => '#2A2622',
            'on-surface-variant' => '#A89F94',

            'outline' => '#3A342E',

            'destructive' => '#FF5A5F',
            'on-destructive' => '#1A0B0B',

            // Mint "profit" accent for resale values and live state.
            'accent' => '#5EE6A8',
            'on-accent' => '#0B1F16',
        ],

        'dark' => [
            'primary' => '#FF6B4A',
            'on-primary' => '#1A0F0B',

            'secondary' => '#3A342E',
            'on-secondary' => '#F5EFE6',

            'surface' => '#1E1B18',
            'on-surface' => '#F5EFE6',
            'background' => '#141210',
            'on-background' => '#F5EFE6',

            'surface-variant' => '#2A2622',
            'on-surface-variant' => '#A89F94',

            'outline' => '#3A342E',

            'destructive' => '#FF5A5F',
            'on-destructive' => '#1A0B0B',

            // Mint "profit" accent for resale values and live state.
            'accent' => '#5EE6A8',
            'on-accent' => '#0B1F16',
        ],

        // Corner radii (points / dp).
        'radius-sm' => 4,
        'radius-md' => 12,
        'radius-lg' => 20,
        'radius-full' => 9999,

        // Font size scale (points / sp).
        'font-sm' => 14,
        'font-md' => 16,
        'font-lg' => 20,
        'font-xl' => 24,
    ],

    'fonts' => [
        'default' => 'BricolageGrotesque-Regular',
        'display' => 'BricolageGrotesque-ExtraBold',
        'semibold' => 'BricolageGrotesque-SemiBold',
        'mono' => 'DMMono-Medium',
    ],

];
