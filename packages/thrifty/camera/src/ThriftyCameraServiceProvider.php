<?php

namespace Thrifty\Camera;

use Illuminate\Support\ServiceProvider;

class ThriftyCameraServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ThriftyCamera::class, fn (): ThriftyCamera => new ThriftyCamera);
    }
}
