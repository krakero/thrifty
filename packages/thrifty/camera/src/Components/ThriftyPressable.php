<?php

namespace Thrifty\Camera\Components;

use Native\Mobile\Edge\Components\Native\NativeBladeComponent;

class ThriftyPressable extends NativeBladeComponent
{
    protected function elementType(): string
    {
        return 'thrifty_pressable';
    }
}
