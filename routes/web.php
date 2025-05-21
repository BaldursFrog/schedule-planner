<?php

use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::get('/schedule/{groupName}', [ScheduleController::class, 'showSchedule']);
Route::get('/free-time/{groupName}', [ScheduleController::class, 'getFreeTime']);
Route::get('/current-week', [ScheduleController::class, 'getCurrentWeekInfo']);
