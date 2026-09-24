<?php

namespace Thrifty\Camera\Events;

use Illuminate\Foundation\Events\Dispatchable;

class VideoFramesExtracted
{
    use Dispatchable;

    /**
     * Fired once per extraction on every exit path: the end of the video,
     * a failure (after CameraFailed) or a cancel.
     */
    public function __construct(public int $count, public ?string $runId = null) {}
}
