<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home'; // Можешь изменить на свой домашний маршрут, если он есть

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // Конфигурируем ограничители частоты запросов (Rate Limiting)
        $this->configureRateLimiting();

        // Определяем, как загружаются файлы маршрутов
        $this->routes(function () {
            // Маршруты для API:
            // Применяется middleware 'api' (например, для throttling)
            // К URL автоматически добавляется префикс 'api' (т.е. /api/your-route)
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php')); // Указываем Laravel загружать маршруты из routes/api.php

            // Маршруты для веб-интерфейса (если он у тебя будет):
            // Применяется middleware 'web' (сессии, CSRF и т.д.)
            Route::middleware('web')
                ->group(base_path('routes/web.php')); // Указываем Laravel загружать маршруты из routes/web.php
        });
    }

    /**
     * Configure the rate limiters for the application.
     *
     * Protected, чтобы нельзя было вызвать снаружи.
     * Laravel вызывает этот метод автоматически из boot().
     */
    protected function configureRateLimiting(): void
    {
        // Пример ограничителя для API: не более 60 запросов в минуту с одного IP
        RateLimiter::for('api', function (Request $request) {
            // Можно использовать $request->user()->id для ограничения по пользователю,
            // или $request->ip() для ограничения по IP.
            return Limit::perMinute(60)->by($request->ip());
        });

        // Ты можешь добавить другие ограничители здесь, если нужно
        // RateLimiter::for('uploads', function (Request $request) {
        //     return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        // });
    }
}
