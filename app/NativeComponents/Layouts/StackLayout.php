<?php

namespace App\NativeComponents\Layouts;

use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * Pushed screens: back chevron plus the screen's own title.
 */
class StackLayout extends NativeLayout
{
    protected ?string $font = 'semibold';

    public function navBar(NativeComponent $screen): ?NavBar
    {
        return NavBar::make()
            ->title($screen->navTitle())
            ->back()
            ->backgroundColor('#141210')
            ->textColor('#F5EFE6')
            ->displayMode('inline');
    }
}
