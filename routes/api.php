<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WebAuthController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:30,1');

Route::post('/web/auth/login', [WebAuthController::class, 'login'])
    ->middleware('throttle:30,1');

Route::post('/web/auth/refresh', [WebAuthController::class, 'refresh'])
    ->middleware('throttle:30,1');

Route::middleware([
    'auth:sanctum',
    'abilities:2fa-setup',
])->group(function () {
    Route::post(
        '/auth/2fa/setup',
        [AuthController::class, 'setupTwoFactor']
    );

    Route::post(
        '/auth/2fa/confirm',
        [AuthController::class, 'confirmTwoFactor']
    );
});

Route::middleware([
    'auth:sanctum',
    'abilities:2fa-verify',
])->group(function () {
    Route::post(
        '/auth/2fa/verify',
        [AuthController::class, 'verifyTwoFactor']
    )->middleware('throttle:30,1');
});

Route::middleware([
    'auth:sanctum',
    'abilities:api-access',
    'throttle:120,1',
])->group(base_path('routes/api_protected.php'));

Route::prefix('web')->middleware([
    \App\Http\Middleware\JwtAuthenticate::class,
    'throttle:120,1',
])->group(base_path('routes/api_protected.php'));
