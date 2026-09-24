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
    public const ScanTab = 'scan';

    public const HistoryTab = 'history';

    protected ?string $font = 'semibold';

    /**
     * Render with the vendor's native chrome (a SwiftUI TabView whose tabs each host a NavigationStack). The default
     * custom-drawn chrome publishes every screen as one column, so pushing a find replaced the whole screen and
     * History was rebuilt on Back, losing its scroll position.
     */
    public function usesNativeChrome(): bool
    {
        return true;
    }

    /**
     * Every tab gets a nav bar so the vendor renders each tab with its own NavigationStack
     * (NativeRootTabsRenderer: `hasNavBar` needs a title on every publish). Finds, their activity and Settings then
     * push inside the tab that opened them, and the tab's root screen stays alive underneath, keeping its scroll
     * position. Scan and History hide the bar with `$hidesNavBar`, which hides only the toolbar, not the stack.
     */
    public function navBar(NativeComponent $screen): ?NavBar
    {
        return NavBar::make()
            ->title($screen->navTitle() !== '' ? $screen->navTitle() : 'Thrifty')
            ->backgroundColor('#141210')
            ->textColor('#F5EFE6')
            ->displayMode('inline');
    }

    /**
     * The tab a pushed screen belongs to, from its route prefix.
     */
    public static function tabFor(?string $prefix): string
    {
        return $prefix === self::ScanTab ? self::ScanTab : self::HistoryTab;
    }

    public static function findUri(string $tab, string $itemId): string
    {
        return '/'.self::tabFor($tab).'/finds/'.$itemId;
    }

    public static function activityUri(string $tab, string $itemId): string
    {
        return self::findUri($tab, $itemId).'/activity';
    }

    public static function settingsUri(string $tab): string
    {
        return '/'.self::tabFor($tab).'/settings';
    }

    public function tabBar(NativeComponent $screen): ?TabBar
    {
        return TabBar::make()
            ->dark()
            ->activeColor('#FF6B4A')
            ->backgroundColor('#141210')
            ->labelVisibility('labeled')
            ->add(Tab::link('Scan', '/scan', ios: Ios::Viewfinder->value, android: Android::CenterFocusStrong->value))
            ->add(Tab::link('History', '/history', ios: Ios::Archivebox->value, android: Android::Inventory2->value));
    }
}
