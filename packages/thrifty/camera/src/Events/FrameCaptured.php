<?php

namespace Thrifty\Camera\Events;

use Illuminate\Foundation\Events\Dispatchable;

class FrameCaptured
{
    use Dispatchable;

    /**
     * @param  string  $path  Absolute JPEG path inside the requested frames directory.
     * @param  string  $source  One of "live", "snapshot", "video" or "image".
     * @param  string  $capturedAt  ISO-8601 timestamp.
     * @param  float|null  $videoSeconds  Position within the video, for video frames.
     */
    public function __construct(
        public string $path,
        public string $source,
        public int $width,
        public int $height,
        public string $capturedAt,
        public ?float $videoSeconds = null,
    ) {}
}
