<?php

namespace Thrifty\Camera\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * Broadcast globally so the plugin's VideoRunJournal records video-run
 * events even while another screen is active.
 */
class VideoFramesExtracted implements BroadcastsGlobally
{
    use Dispatchable;

    /**
     * Fired once per extraction on every exit path: the end of the video,
     * a failure (after CameraFailed) or a cancel.
     */
    public function __construct(public int $count, public ?string $runId = null) {}
}
