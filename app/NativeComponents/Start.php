<?php

namespace App\NativeComponents;

use App\NativeComponents\Layouts\TabsLayout;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * The launch URL (`/`): hands straight over to the Scan tab. The tab lives at `/scan` because a tab at `/` can't own
 * the `/scan/...` routes that push inside it.
 */
class Start extends NativeComponent
{
    public function mount(): void
    {
        $this->replace('/'.TabsLayout::ScanTab);
    }

    public function render(): View
    {
        return view('native.start');
    }
}
