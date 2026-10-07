<?php

use App\Http\Controllers\Admin\ComputerController;
use App\Http\Controllers\Admin\LiveSessionsController;
use App\Http\Controllers\Admin\KlasFoundationController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SchoolClassController;
use App\Http\Controllers\Admin\SchoolController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Auth\AcceptInvitationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Public: a teacher redeems the token an administrator gave them.
Route::get('invitations/accept', [AcceptInvitationController::class, 'show'])->name('invitations.accept');
Route::post('invitations/accept', [AcceptInvitationController::class, 'store'])->middleware('throttle:5,1')->name('invitations.accept.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::prefix('admin')->name('admin.')->middleware('admin.access')->group(function () {
        Route::resource('schools', SchoolController::class)->only(['index', 'store', 'destroy']);
        Route::resource('classes', SchoolClassController::class)->only(['index', 'store', 'destroy']);
        Route::resource('students', StudentController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('students/import', [StudentController::class, 'import'])->name('students.import');
        Route::resource('computers', ComputerController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::post('computers/{computer}/issue-token', [ComputerController::class, 'issueToken'])->name('computers.issue-token');
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('live', [LiveSessionsController::class, 'index'])->name('live.index');
        Route::get('klas', [KlasFoundationController::class, 'index'])->name('klas.index');
        Route::post('klas/classrooms', [KlasFoundationController::class, 'classroom'])->name('klas.classrooms.store');
        Route::post('klas/enrollment-codes', [KlasFoundationController::class, 'enrollmentCode'])->name('klas.enrollment-codes.store');
        Route::post('klas/invitations', [KlasFoundationController::class, 'invitation'])->name('klas.invitations.store');
        Route::put('klas/classrooms/{classroom}/staff', [KlasFoundationController::class, 'assignStaff'])->name('klas.classrooms.staff');
    });
});

require __DIR__.'/settings.php';
