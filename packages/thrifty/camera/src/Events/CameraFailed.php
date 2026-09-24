<?php

namespace Thrifty\Camera\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * Broadcast globally so the plugin's VideoRunJournal records video-run
 * events even while another screen is active.
 */
class CameraFailed implements BroadcastsGlobally
{
    use Dispatchable;

    /**
     * @param  string|null  $runId  Set when the failure belongs to a video extraction run.
     */
    public function __construct(public string $message, public ?string $runId = null) {}
}
