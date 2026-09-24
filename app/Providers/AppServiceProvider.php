<?php

namespace App\Providers;

use App\Services\Drive\DriveClient;
use App\Services\Drive\GoogleDriveClient;
use App\Services\Gemini\GeminiClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeminiClient::class, fn () => GeminiClient::fromConfig());
        $this->app->singleton(DriveClient::class, fn () => new GoogleDriveClient(config('navi.drive.credentials')));
    }

    public function boot(): void
    {
        //
    }
}
