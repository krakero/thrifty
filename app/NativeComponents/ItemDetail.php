<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class ItemDetail extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Find';
    }

    public function render(): View
    {
        return view('native.item-detail');
    }
}
