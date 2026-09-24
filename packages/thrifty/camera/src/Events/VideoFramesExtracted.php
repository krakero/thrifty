<?php

namespace Thrifty\Camera\Events;

use Illuminate\Foundation\Events\Dispatchable;

class VideoFramesExtracted
{
    use Dispatchable;

    public function __construct(public int $count) {}
}
