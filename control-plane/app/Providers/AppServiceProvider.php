<?php

namespace App\Providers;

use App\Runtime\FakeStationRuntimeProvider;
use App\Runtime\HttpStationRuntimeProvider;
use App\Runtime\StationRuntimeProvider;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(StationRuntimeProvider::class, function ($app): StationRuntimeProvider {
            return match (config('cloudtunes.runtime.driver')) {
                'fake' => new FakeStationRuntimeProvider(),
                'http' => new HttpStationRuntimeProvider(),
                default => throw new RuntimeException('Unsupported CloudTunes station runtime driver.'),
            };
        });
    }

    public function boot(): void {}
}
