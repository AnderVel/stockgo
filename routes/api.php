<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\PasswordResetCodeController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\WebAuthController;
use App\Http\Middleware\JwtAuthenticate;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:30,1');

Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:30,1');

Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])
    ->middleware('throttle:30,1');

Route::post('/auth/password-reset/request-code', [PasswordResetCodeController::class, 'requestCode'])
    ->middleware('throttle:5,1');

Route::post('/auth/password-reset/verify-code', [PasswordResetCodeController::class, 'verifyCode'])
    ->middleware('throttle:10,1');

Route::post('/auth/password-reset/complete', [PasswordResetCodeController::class, 'complete'])
    ->middleware('throttle:10,1');

Route::post('/auth/email/send-verification', [AuthController::class, 'sendEmailVerification'])
    ->middleware('throttle:10,1');

Route::post('/auth/email/verify-code', [AuthController::class, 'verifyEmailCode'])
    ->middleware('throttle:10,1');

Route::post('/web/auth/login', [WebAuthController::class, 'login'])
    ->middleware('throttle:30,1');

Route::post('/web/auth/2fa/setup', [WebAuthController::class, 'setupTwoFactor'])
    ->middleware('throttle:10,1');

Route::post('/web/auth/2fa/confirm', [WebAuthController::class, 'confirmTwoFactor'])
    ->middleware('throttle:10,1');

Route::post('/web/auth/refresh', [WebAuthController::class, 'refresh'])
    ->middleware('throttle:30,1');

Route::post('/web/auth/logout', [WebAuthController::class, 'logout'])->middleware([
    JwtAuthenticate::class,
    'throttle:30,1',
]);

Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware([
    'auth:sanctum',
    'abilities:api-access',
    'active',
]);

Route::post('/auth/change-password', [AuthController::class, 'changePassword'])->middleware([
    'auth:sanctum',
    'abilities:api-access',
    'active',
    'throttle:10,1',
]);

Route::middleware([
    'auth:sanctum',
    'abilities:2fa-setup',
    'active',
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
    'active',
])->group(function () {
    Route::post(
        '/auth/2fa/verify',
        [AuthController::class, 'verifyTwoFactor']
    )->middleware('throttle:30,1');
});

Route::middleware([
    'auth:sanctum',
    'abilities:api-access',
    'active',
    'throttle:120,1',
])->group(base_path('routes/api_protected.php'));

Route::prefix('web')->middleware([
    JwtAuthenticate::class,
    'throttle:120,1',
])->group(base_path('routes/api_protected.php'));

// Roles y permisos (solo administrador web)
Route::prefix('web')->middleware([
    JwtAuthenticate::class,
    'permission:users.view',
    'throttle:120,1',
])->group(function () {
    Route::get('/roles', [RoleController::class, 'index']);
    Route::get('/roles/permissions', [RoleController::class, 'permissions']);
});
