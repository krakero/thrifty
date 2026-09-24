<?php

use App\NativeComponents\AgentActivity;
use App\NativeComponents\History;
use App\NativeComponents\ItemDetail;
use App\NativeComponents\Layouts\StackLayout;
use App\NativeComponents\Layouts\TabsLayout;
use App\NativeComponents\Scan;
use App\NativeComponents\Settings;
use Illuminate\Support\Facades\Route;

Route::nativeGroup(TabsLayout::class, function () {
    Route::native('/', Scan::class);
    Route::native('/history', History::class);
});

Route::native('/finds/{id}', ItemDetail::class)->layout(StackLayout::class);
Route::native('/finds/{id}/activity', AgentActivity::class)->layout(StackLayout::class);
Route::native('/settings', Settings::class)->layout(StackLayout::class);
