<?php

namespace Thrifty\Camera\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

/**
 * Broadcast globally so the plugin's VideoRunJournal records video-run
 * events even while another screen is active.
 */
class FrameCaptured implements BroadcastsGlobally
{
    use Dispatchable;

    /**
     * @param  string  $path  Absolute JPEG path inside the requested frames directory.
     * @param  string  $source  One of "live", "snapshot", "video" or "image".
     * @param  string  $capturedAt  ISO-8601 timestamp.
     * @param  float|null  $videoSeconds  Position within the video, for video frames.
     * @param  string|null  $runId  The extraction run id, for video frames.
     */
    public function __construct(
        public string $path,
        public string $source,
        public int $width,
        public int $height,
        public string $capturedAt,
        public ?float $videoSeconds = null,
        public ?string $runId = null,
    ) {}
}
