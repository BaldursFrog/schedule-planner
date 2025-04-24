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
        $this->app->singleton(ScheduleService::class, function ($app) {
            return new ScheduleService();
        });

        $this->app->singleton(ScheduleParserService::class, function ($app) {
            return new ScheduleParserService();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}