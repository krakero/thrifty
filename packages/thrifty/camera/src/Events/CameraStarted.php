<?php

namespace Thrifty\Camera\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * The capture session is actually running (permission granted, input
 * configured, session started). Emitted for every start request that
 * succeeds: choosing a camera, starting a scan, the preview appearing or
 * the app returning to the foreground. Every failed start request emits
 * CameraFailed instead.
 */
class CameraStarted implements BroadcastsGlobally
{
    use Dispatchable;

    /**
     * @param  string  $facing  The running camera: "back" or "front".
     */
    public function __construct(public string $facing) {}
}
