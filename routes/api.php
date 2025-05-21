<?php

use App\Http\Controllers\Api\PlanController;
use Illuminate\Support\Facades\Route; // Если используешь PlanController

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Твои основные API маршруты
Route::post('/generate-plan', [PlanController::class, 'requestPlanGeneration']);
Route::get('/get-plan-result/{job_id}', [PlanController::class, 'getPlanResult']);

// Тестовый маршрут
Route::get('/ping', function () {
    return response()->json(['message' => 'API Ping Success! Planner is online!']);
});
