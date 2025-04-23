<?php

use App\Http\Controllers\ScheduleController;
use Illuminate\Support\Facades\Route;

Route::get('/schedule/{groupName}', [ScheduleController::class, 'showSchedule']);