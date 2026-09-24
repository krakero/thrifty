<?php

namespace Thrifty\Camera;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Thrifty\Camera\Events\CameraFailed;
use Thrifty\Camera\Events\FrameCaptured;
use Thrifty\Camera\Events\VideoFramesExtracted;

class ThriftyCameraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ThriftyCamera::class, fn (): ThriftyCamera => new ThriftyCamera);

        $this->app->singleton(
            VideoRunJournal::class,
            fn (): VideoRunJournal => new VideoRunJournal(storage_path('framework/thrifty-camera/video-runs.json')),
        );
    }

    public function boot(): void
    {
        Event::listen(
            [FrameCaptured::class, VideoFramesExtracted::class, CameraFailed::class],
            fn (FrameCaptured|VideoFramesExtracted|CameraFailed $event) => $this->app->make(VideoRunJournal::class)->record($event),
        );
    }
}
