<?php

use App\Http\Controllers\Admin\ComputerController;
use App\Http\Controllers\Admin\LiveSessionsController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SchoolClassController;
use App\Http\Controllers\Admin\SchoolController;
use App\Http\Controllers\Admin\StudentController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function () {
        Route::resource('schools', SchoolController::class)->only(['index', 'store', 'destroy']);
        Route::resource('classes', SchoolClassController::class)->only(['index', 'store', 'destroy']);
        Route::resource('students', StudentController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::resource('computers', ComputerController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('computers/{computer}/issue-token', [ComputerController::class, 'issueToken'])->name('computers.issue-token');
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('live', [LiveSessionsController::class, 'index'])->name('live.index');
    });
});

require __DIR__.'/settings.php';
