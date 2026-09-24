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
use App\Http\Controllers\Api\V1\DeviceBroadcastController;
use App\Http\Controllers\Api\V1\DeviceBrowserController;
use App\Http\Controllers\Api\V1\DeviceCommandController;
use App\Http\Controllers\Api\V1\DeviceChatController;
use App\Http\Controllers\Api\V1\DeviceCommunicationController;
use App\Http\Controllers\Api\V1\DeviceController as V1DeviceController;
use App\Http\Controllers\Api\V1\DeviceEnrollmentController;
use App\Http\Controllers\Api\V1\DeviceRosterController;
use App\Http\Controllers\Api\V1\DeviceScreenSessionController;
use App\Http\Controllers\Api\V1\DeviceSessionController;
use App\Http\Controllers\Api\V1\InvitationAcceptanceController;
use App\Http\Controllers\Api\V1\TeacherBroadcastController;
use App\Http\Controllers\Api\V1\TeacherClassroomController;
use App\Http\Controllers\Api\V1\TeacherChatController;
use App\Http\Controllers\Api\V1\TeacherCommandController;
use App\Http\Controllers\Api\V1\TeacherCommunicationController;
use App\Http\Controllers\Api\V1\TeacherScreenSessionController;
use App\Http\Controllers\Api\V1\TeacherPolicyController;
use App\Http\Controllers\Api\V1\DevicePolicyController;
use App\Http\Controllers\Api\V1\Admin\AuditController;
use App\Http\Controllers\Api\V1\Admin\BlockRuleController;

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
        Route::post('browser/events', [DeviceBrowserController::class, 'store']);
        Route::get('commands', [DeviceCommandController::class, 'index']);
        Route::post('commands/{uuid}/result', [DeviceCommandController::class, 'result']);
        Route::get('policy', [DevicePolicyController::class, 'show']);
        Route::get('screen-sessions/current', [DeviceScreenSessionController::class, 'current']);
        Route::patch('screen-sessions/{uuid}', [DeviceScreenSessionController::class, 'answer']);
        Route::post('screen-sessions/{uuid}/candidates', [DeviceScreenSessionController::class, 'addCandidates']);
        Route::get('broadcasts/current', [DeviceBroadcastController::class, 'current']);
        Route::post('broadcasts/{uuid}/join', [DeviceBroadcastController::class, 'join']);
        Route::get('broadcasts/outgoing', [DeviceBroadcastController::class, 'outgoing']);
        Route::patch('broadcasts/targets/{uuid}', [DeviceBroadcastController::class, 'answer']);
        Route::post('broadcasts/targets/{uuid}/candidates', [DeviceBroadcastController::class, 'addCandidates']);
        Route::get('announcements', [DeviceCommunicationController::class, 'announcements']);
        Route::post('announcements/{uuid}/read', [DeviceCommunicationController::class, 'markAnnouncementRead']);
        Route::get('help-requests/current', [DeviceCommunicationController::class, 'currentHelpRequest']);
        Route::post('help-requests', [DeviceCommunicationController::class, 'requestHelp']);
        Route::post('help-requests/{uuid}/cancel', [DeviceCommunicationController::class, 'cancelHelpRequest']);
        Route::get('messages', [DeviceChatController::class, 'index']);
        Route::post('messages', [DeviceChatController::class, 'store']);
        Route::post('messages/read', [DeviceChatController::class, 'markRead']);
    });

    Route::middleware(['auth:sanctum', 'teacher.token'])->group(function () {
        Route::post('auth/logout', [V1AuthController::class, 'logout']);
        Route::get('teacher/classrooms', [TeacherClassroomController::class, 'index']);
        Route::get('teacher/classrooms/{classroom}/devices', [TeacherClassroomController::class, 'devices']);
        Route::get('teacher/classrooms/{classroom}/devices/{device}/browser-tabs', [TeacherClassroomController::class, 'browserTabs']);
        Route::post('teacher/classrooms/{classroom}/devices/{device}/commands', [TeacherCommandController::class, 'store'])->middleware('throttle:60,1');
        Route::get('teacher/classrooms/{classroom}/devices/{device}/commands/{uuid}', [TeacherCommandController::class, 'show']);
        Route::post('teacher/classrooms/{classroom}/devices/{device}/screen-sessions', [TeacherScreenSessionController::class, 'store'])->middleware('throttle:30,1');
        Route::get('teacher/classrooms/{classroom}/devices/{device}/screen-sessions/{uuid}', [TeacherScreenSessionController::class, 'show']);
        Route::post('teacher/classrooms/{classroom}/devices/{device}/screen-sessions/{uuid}/candidates', [TeacherScreenSessionController::class, 'addCandidates']);
        Route::post('teacher/classrooms/{classroom}/devices/{device}/screen-sessions/{uuid}/quality', [TeacherScreenSessionController::class, 'setQuality'])->middleware('throttle:30,1');
        Route::post('teacher/classrooms/{classroom}/devices/{device}/screen-sessions/{uuid}/end', [TeacherScreenSessionController::class, 'end']);
        Route::post('teacher/classrooms/{classroom}/devices/{device}/broadcast', [TeacherBroadcastController::class, 'store'])->middleware('throttle:30,1');
        Route::post('teacher/classrooms/{classroom}/devices/{device}/broadcast/{uuid}/end', [TeacherBroadcastController::class, 'end']);

        Route::get('teacher/classrooms/{classroom}/devices/{device}/messages', [TeacherChatController::class, 'index']);
        Route::post('teacher/classrooms/{classroom}/devices/{device}/messages', [TeacherChatController::class, 'store'])->middleware('throttle:120,1');

        Route::get('teacher/classrooms/{classroom}/announcements',[TeacherCommunicationController::class, 'announcements']);
        Route::post('teacher/classrooms/{classroom}/announcements', [TeacherCommunicationController::class, 'sendAnnouncement'])->middleware('throttle:30,1');
        Route::get('teacher/classrooms/{classroom}/help-requests', [TeacherCommunicationController::class, 'helpRequests']);
        Route::post('teacher/classrooms/{classroom}/help-requests/{uuid}/resolve', [TeacherCommunicationController::class, 'resolveHelpRequest']);

        Route::get('teacher/classrooms/{classroom}/policy', [TeacherPolicyController::class, 'show']);
        Route::post('teacher/classrooms/{classroom}/focus-sessions', [TeacherPolicyController::class, 'startFocus'])->middleware('throttle:30,1');
        Route::post('teacher/classrooms/{classroom}/focus-sessions/{uuid}/end', [TeacherPolicyController::class, 'endFocus']);
        Route::post('teacher/classrooms/{classroom}/block-rules', [TeacherPolicyController::class, 'addBlockRule'])->middleware('throttle:60,1');
        Route::delete('teacher/classrooms/{classroom}/block-rules/{rule}', [TeacherPolicyController::class, 'removeBlockRule']);

        Route::prefix('admin')->group(function () {
            Route::post('block-rules', [BlockRuleController::class, 'store']);
            Route::delete('block-rules/{rule}', [BlockRuleController::class, 'destroy']);
            Route::get('audit', [AuditController::class, 'index']);
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
