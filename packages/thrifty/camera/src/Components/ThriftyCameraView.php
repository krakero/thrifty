<?php

namespace Thrifty\Camera\Components;

use Native\Mobile\Edge\Components\Native\NativeBladeComponent;

class ThriftyCameraView extends NativeBladeComponent
{
    protected bool $isSelfClosing = true;

    protected function elementType(): string
    {
        return 'thrifty_camera';
    }
}
