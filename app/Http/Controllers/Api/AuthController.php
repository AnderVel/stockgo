<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $usuario = Str::lower(trim($datos['usuario']));
        $key = 'login:' . $usuario . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Demasiados intentos de inicio de sesión. Intenta nuevamente más tarde.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $user = User::where('username', $usuario)->first();

        if (
            !$user ||
            !Hash::check($datos['password'], $user->password)
        ) {
            RateLimiter::hit($key, 60);

            app(AuditoriaService::class)->registrar(
                'LOGIN_FALLIDO',
                'AUTH',
                'Intento de inicio de sesión fallido.',
                ['usuario' => $usuario],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Usuario o contraseña incorrectos.',
            ], 401);
        }

        RateLimiter::clear($key);

        $deviceName = $datos['device_name'] ?? 'android-stockgo';

        if (!$user->two_factor_enabled) {
            $token = $user->createToken(
                'stockgo-2fa-setup',
                ['2fa-setup'],
                now()->addMinutes(10)
            )->plainTextToken;

            app(AuditoriaService::class)->registrar(
                'LOGIN',
                'AUTH',
                'Credenciales válidas; requiere configuración de 2FA.',
                ['usuario' => $user->username],
                $request
            );

            return response()->json([
                'status' => 'success',
                'two_factor_setup_required' => true,
                'setup_token' => $token,
                'expires_in' => 600,
                'user' => $this->userData($user),
            ]);
        }

        $token = $user->createToken(
            'stockgo-2fa-challenge',
            ['2fa-verify'],
            now()->addMinutes(5)
        )->plainTextToken;

        app(AuditoriaService::class)->registrar(
            'LOGIN',
            'AUTH',
            'Credenciales válidas; requiere verificación 2FA.',
            ['usuario' => $user->username],
            $request
        );

        return response()->json([
            'status' => 'success',
            'two_factor_required' => true,
            'challenge_token' => $token,
            'expires_in' => 300,
            'user' => $this->userData($user),
        ]);
    }

    public function setupTwoFactor(Request $request)
    {
        $user = $request->user();

        if ($user->two_factor_enabled) {
            return response()->json([
                'status' => 'error',
                'message' => 'La autenticación de dos factores ya está activada.',
            ], 409);
        }

        $google2fa = new Google2FA();

        if (!$user->two_factor_secret) {
            $user->forceFill([
                'two_factor_secret' => $google2fa->generateSecretKey(),
                'two_factor_enabled' => false,
                'two_factor_last_timestamp' => null,
            ])->save();
        }

        $label = $user->email ?: $user->username;

        $otpUrl = $google2fa->getQRCodeUrl(
            config('app.name', 'StockGo'),
            $label,
            $user->two_factor_secret
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Configura el segundo factor en una aplicación compatible con TOTP.',
            'secret' => $user->two_factor_secret,
            'otpauth_url' => $otpUrl,
        ]);
    }

    public function confirmTwoFactor(Request $request)
    {
        $datos = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if (!$user->two_factor_secret) {
            return response()->json([
                'status' => 'error',
                'message' => 'Primero debes iniciar la configuración del segundo factor.',
            ], 422);
        }

        $google2fa = new Google2FA();

        $timestamp = $google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            $datos['code'],
            $user->two_factor_last_timestamp ?? 0
        );

        if ($timestamp === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'El código de verificación no es válido.',
            ], 422);
        }

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_last_timestamp' => $timestamp,
        ])->save();

        $request->user()->currentAccessToken()?->delete();

        $token = $user->createToken(
            'android-stockgo',
            ['api-access'],
            now()->addHours(8)
        )->plainTextToken;

        app(AuditoriaService::class)->registrar(
            '2FA_ACTIVADO',
            'AUTH',
            '2FA activado correctamente.',
            [],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Autenticación de dos factores activada correctamente.',
            'token' => $token,
            'expires_in' => 28800,
            'user' => $this->userData($user),
        ]);
    }

    public function verifyTwoFactor(Request $request)
    {
        $datos = $request->validate([
            'code' => ['required', 'digits:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();

        if (!$user->two_factor_enabled || !$user->two_factor_secret) {
            return response()->json([
                'status' => 'error',
                'message' => 'La autenticación de dos factores no está configurada.',
            ], 422);
        }

        $key = '2fa:' . $user->id . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Demasiados intentos de verificación. Intenta nuevamente más tarde.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $google2fa = new Google2FA();

        $timestamp = $google2fa->verifyKeyNewer(
            $user->two_factor_secret,
            $datos['code'],
            $user->two_factor_last_timestamp ?? 0
        );

        if ($timestamp === false) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'status' => 'error',
                'message' => 'El código de autenticación no es válido.',
            ], 422);
        }

        RateLimiter::clear($key);

        $user->forceFill([
            'two_factor_last_timestamp' => $timestamp,
        ])->save();

        $request->user()->currentAccessToken()?->delete();

        $deviceName = $datos['device_name'] ?? 'android-stockgo';

        app(AuditoriaService::class)->registrar(
            '2FA_VERIFICADO',
            'AUTH',
            'Verificación 2FA exitosa.',
            [],
            $request
        );

        $token = $user->createToken(
            $deviceName,
            ['api-access'],
            now()->addHours(8)
        )->plainTextToken;

        return response()->json([
            'status' => 'success',
            'message' => 'Autenticación completada correctamente.',
            'token' => $token,
            'expires_in' => 28800,
            'user' => $this->userData($user),
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'user' => $this->userData($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        app(AuditoriaService::class)->registrar(
            'LOGOUT',
            'AUTH',
            'Sesión cerrada.',
            [],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'rol' => $user->rol,
            'bodega_asignada' => $user->bodega_asignada,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
        ];
    }
}