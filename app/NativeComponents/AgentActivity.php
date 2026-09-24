<?php

namespace App\NativeComponents;

use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class AgentActivity extends NativeComponent
{
    public function navTitle(): string
    {
        return 'Agent activity';
    }

    public function render(): View
    {
        return view('native.agent-activity');
    }
}
