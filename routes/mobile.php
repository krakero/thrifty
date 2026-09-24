<?php

use App\NativeComponents\AgentActivity;
use App\NativeComponents\History;
use App\NativeComponents\ItemDetail;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Scan;
use App\NativeComponents\Settings;
use App\NativeComponents\Start;
use Illuminate\Support\Facades\Route;

/*
 * Every screen lives inside a tab so each tab keeps its own navigation stack: a find, its agent activity and
 * Settings push inside the tab that opened them (the prefix picks the tab and keeps it highlighted), and the tab's
 * root screen stays alive underneath with its scroll position.
 */
Route::native('/', Start::class);

Route::nativeGroup(TabsLayout::class, function () {
    Route::native('/scan', Scan::class);
    Route::native('/history', History::class);

    Route::native('/{tab}/finds/{id}', ItemDetail::class)->where('tab', 'scan|history');
    Route::native('/{tab}/finds/{id}/activity', AgentActivity::class)->where('tab', 'scan|history');
    Route::native('/{tab}/settings', Settings::class)->where('tab', 'scan|history');
});
