
<?php

use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

// Маршрут для получения расписания по ID группы
Route::get('/schedule/{group_id}', [ScheduleController::class, 'getGroupSchedule']);

// Маршрут для получения свободного времени по ID пользователя (пока используем group_id)
Route::get('/free-time/{group_id}', [ScheduleController::class, 'getFreeTime']);

// Вы можете добавить здесь другие API-маршруты по мере необходимости

Route::get('/test', function () {
    return response()->json(['message' => 'API Test Works!']);
});
