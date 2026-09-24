<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class Scan extends NativeComponent
{
    public function render(): View
    {
        return view('native.scan');
    }
}
