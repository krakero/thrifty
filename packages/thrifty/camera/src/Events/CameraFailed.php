<?php

namespace Thrifty\Camera\Events;

use Illuminate\Foundation\Events\Dispatchable;

class CameraFailed
{
    use Dispatchable;

    public function __construct(public string $message) {}
}
