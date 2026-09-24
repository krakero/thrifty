<?php

namespace App\Scanning;

/**
 * The still image behind the Scan overlay: an uploaded photo or the latest video frame.
 */
class Stage
{
    public function __construct(private LiveScanState $state, private FrameFiles $files) {}

    public function show(string $framePath): void
    {
        $previous = $this->state->stillPreviewPath;
        $this->state->stillPreviewPath = $this->files->copyToPreview($framePath) ?? $previous;

        if ($previous !== $this->state->stillPreviewPath) {
            $this->files->delete($previous);
        }
    }

    public function clear(): void
    {
        $this->files->delete($this->state->stillPreviewPath);
        $this->state->stillPreviewPath = null;
    }
}
