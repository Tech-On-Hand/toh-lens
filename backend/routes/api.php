<?php

use App\Http\Controllers\Api\ComputerController;
use App\Http\Controllers\Api\LoginSessionSyncController;
use App\Http\Controllers\Api\RosterController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['ok' => true]));

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/computer/me', [ComputerController::class, 'me']);
    Route::get('/roster', [RosterController::class, 'index']);
    Route::post('/sessions/sync', [LoginSessionSyncController::class, 'store']);
});
