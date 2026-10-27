<?php

use App\Http\Controllers\Api\V1\RegistrationController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\StationController;
use App\Http\Controllers\Api\V1\UserApprovalController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json([
    'service' => 'cloudtunes-control-plane',
    'status' => 'ok',
    'phase' => 'station-onboarding',
]));

Route::post('/api/v1/register', [RegistrationController::class, 'store'])->middleware('throttle:5,1');
Route::post('/api/v1/login', [SessionController::class, 'store'])->middleware('throttle:10,1');

Route::middleware('auth')->group(function (): void {
    Route::post('/api/v1/logout', [SessionController::class, 'destroy']);
    Route::get('/api/v1/stations', [StationController::class, 'index']);
    Route::post('/api/v1/stations', [StationController::class, 'store']);
    Route::patch('/api/v1/admin/users/{user}/approval', [UserApprovalController::class, 'update']);
});
