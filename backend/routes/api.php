<?php

use App\Http\Controllers\Api\ComputerController;
use App\Http\Controllers\Api\LoginSessionSyncController;
use App\Http\Controllers\Api\RosterController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\Admin\ClassroomController as AdminClassroomController;
use App\Http\Controllers\Api\V1\Admin\DeviceController as AdminDeviceController;
use App\Http\Controllers\Api\V1\Admin\EnrollmentCodeController;
use App\Http\Controllers\Api\V1\Admin\InvitationController;
use App\Http\Controllers\Api\V1\AuthController as V1AuthController;
use App\Http\Controllers\Api\V1\DeviceController as V1DeviceController;
use App\Http\Controllers\Api\V1\DeviceEnrollmentController;
use App\Http\Controllers\Api\V1\DeviceRosterController;
use App\Http\Controllers\Api\V1\DeviceSessionController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\TeacherClassroomController;

Route::get('/ping', fn () => response()->json(['ok' => true]));

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/computer/me', [ComputerController::class, 'me']);
    Route::get('/roster', [RosterController::class, 'index']);
    Route::post('/sessions/sync', [LoginSessionSyncController::class, 'store']);
});

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [V1AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post('invitations/accept', [InvitationAcceptanceController::class, 'store'])->middleware('throttle:5,1');
    Route::post('devices/enroll', [DeviceEnrollmentController::class, 'store'])->middleware('throttle:10,1');

    Route::middleware(['auth:sanctum', 'device.active'])->prefix('device')->group(function () {
        Route::get('configuration', [V1DeviceController::class, 'configuration']);
        Route::post('heartbeat', [V1DeviceController::class, 'heartbeat']);
        Route::get('roster', [DeviceRosterController::class, 'index']);
        Route::post('student-sessions/sync', [DeviceSessionController::class, 'store']);
    });

    Route::middleware(['auth:sanctum', 'teacher.token'])->group(function () {
        Route::post('auth/logout', [V1AuthController::class, 'logout']);
        Route::get('teacher/classrooms', [TeacherClassroomController::class, 'index']);
        Route::get('teacher/classrooms/{classroom}/devices', [TeacherClassroomController::class, 'devices']);

        Route::prefix('admin')->group(function () {
            Route::get('classrooms', [AdminClassroomController::class, 'index']);
            Route::post('classrooms', [AdminClassroomController::class, 'store']);
            Route::put('classrooms/{classroom}/staff/{staff}', [AdminClassroomController::class, 'assignStaff']);
            Route::post('enrollment-codes', [EnrollmentCodeController::class, 'store']);
            Route::patch('devices/{device}', [AdminDeviceController::class, 'update']);
            Route::post('devices/{device}/revoke', [AdminDeviceController::class, 'revoke']);
            Route::post('invitations', [InvitationController::class, 'store']);
        });
    });
});
