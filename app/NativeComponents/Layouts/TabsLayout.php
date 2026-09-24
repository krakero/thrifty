<?php

namespace App\NativeComponents\Layouts;

use App\Icons\Android;
use App\Icons\Ios;
use Native\Mobile\Edge\Layouts\Builders\NavBar;
use Native\Mobile\Edge\Layouts\Builders\Tab;
use Native\Mobile\Edge\Layouts\Builders\TabBar;
use Native\Mobile\Edge\Layouts\NativeLayout;
use Native\Mobile\Edge\NativeComponent;

/**
 * Top-level chrome for the Scan and History tabs.
 */
class TabsLayout extends NativeLayout
{
    protected ?string $font = 'semibold';

    public function navBar(NativeComponent $screen): ?NavBar
    {
        return null;
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->dark()
            ->activeColor('#FF6B4A')
            ->backgroundColor('#141210')
            ->labelVisibility('labeled')
            ->add(Tab::link('Scan', '/', ios: Ios::Viewfinder->value, android: Android::CenterFocusStrong->value))
            ->add(Tab::link('History', '/history', ios: Ios::Archivebox->value, android: Android::Inventory2->value));
    }
}
