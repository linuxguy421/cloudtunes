<?php

use App\Http\Controllers\Api\V1\SessionController as ApiSessionController;
use App\Http\Controllers\Api\V1\StationController as ApiStationController;
use App\Http\Controllers\Api\V1\UserApprovalController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\RegistrationController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\PlayerController;
use App\Http\Controllers\Web\StationController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

Route::get('/login', [LoginController::class, 'create'])->middleware('guest')->name('login');
Route::get('/register', [RegistrationController::class, 'create'])->middleware('guest')->name('register');
Route::post('/register', [RegistrationController::class, 'store'])
    ->middleware(['guest', 'throttle:5,1'])
    ->name('register.store');
Route::post('/login', [LoginController::class, 'store'])
    ->middleware(['guest', 'throttle:10,1'])
    ->name('login.store');

// Listening is public; station management remains behind browser authentication.
Route::get('/player', [PlayerController::class, 'show'])->name('player');
Route::get('/player/status', [PlayerController::class, 'status'])
    ->middleware('throttle:30,1')
    ->name('player.status');

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/stations', [StationController::class, 'index'])->name('stations.index');
    Route::post('/stations', [StationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('stations.store');
    Route::get('/stations/{station}/edit', [StationController::class, 'edit'])->name('stations.edit');
    Route::put('/stations/{station}', [StationController::class, 'update'])->name('stations.update');
    Route::delete('/stations/{station}', [StationController::class, 'destroy'])->name('stations.destroy');
});

// Session-authenticated API endpoints remain on web middleware because they use
// the browser session and therefore retain Laravel's CSRF protection.
Route::post('/api/v1/login', [ApiSessionController::class, 'store'])->middleware('throttle:10,1');

Route::middleware('auth')->group(function (): void {
    Route::post('/api/v1/logout', [ApiSessionController::class, 'destroy']);
    Route::get('/api/v1/stations', [ApiStationController::class, 'index']);
    Route::post('/api/v1/stations', [ApiStationController::class, 'store']);
    Route::patch('/api/v1/admin/users/{user}/approval', [UserApprovalController::class, 'update']);
});
