<?php

use App\Http\Controllers\Api\V1\RegistrationController;
use Illuminate\Support\Facades\Route;

// Public JSON registration is stateless and must not require a browser CSRF token.
Route::post('/v1/register', [RegistrationController::class, 'store'])
    ->middleware('throttle:5,1');
