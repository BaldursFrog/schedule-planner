<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramBotController;

Route::post('/bot/webhook', [TelegramBotController::class, 'handleWebhook']);

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});
