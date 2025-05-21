<?php

namespace App\Providers;

use App\Services\ScheduleParserService;
use App\Services\ScheduleService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ScheduleParserService::class, function ($app) {
            return new ScheduleParserService();
        });
    }

    public function boot(): void
    {
        //
    }
}
