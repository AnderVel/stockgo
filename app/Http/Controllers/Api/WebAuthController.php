<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebRefreshToken;
use App\Services\AuditoriaService;
use App\Services\JwtService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class WebAuthController extends Controller
{
    public function login(Request $request)
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'digits:6'],
        ]);

        $usuario = Str::lower(trim($datos['usuario']));
        $key = 'web-login:' . $usuario . '|' . $request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Demasiados intentos.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $user = User::where('username', $usuario)->first();

        if (!$user || !Hash::check($datos['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'status' => 'error',
                'message' => 'Usuario o contraseña incorrectos.',
            ], 401);
        }

        if (!$user->two_factor_enabled || !$user->two_factor_secret) {
            return response()->json([
                'status' => 'error',
                'message' => 'Debes configurar 2FA desde la aplicación Android antes de entrar al Web Admin.',
            ], 403);
        }

        if (empty($datos['code'])) {
            return response()->json([
                'status' => 'error',
                'two_factor_required' => true,
                'message' => 'Se requiere el código de autenticación de dos factores.',
            ], 422);
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
        $user->forceFill(['two_factor_last_timestamp' => $timestamp])->save();

        app(AuditoriaService::class)->registrar('LOGIN_WEB', 'AUTH', 'Inicio de sesión web.', [], $request);

        return $this->emitirSesion($user);
    }

    public function refresh(Request $request)
    {
        $datos = $request->validate(['refresh_token' => ['required', 'string']]);

        $hash = hash('sha256', $datos['refresh_token']);
        $token = WebRefreshToken::where('token_hash', $hash)->first();

        if (!$token || !$token->esValido()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Refresh token inválido o expirado.',
            ], 401);
        }

        $token->update(['revoked_at' => now()]);

        return $this->emitirSesion($token->user);
    }

    private function emitirSesion(User $user)
    {
        $access = app(JwtService::class)->emitirAccessToken($user);
        $refresh = Str::random(64);

        WebRefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $refresh),
            'expires_at' => now()->addDays((int) config('jwt.refresh_ttl_days', 7)),
        ]);

        return response()->json([
            'status' => 'success',
            'token' => $access,
            'token_type' => 'Bearer',
            'expires_in' => ((int) config('jwt.ttl_minutes', 30)) * 60,
            'refresh_token' => $refresh,
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'rol' => $user->rol,
                'bodega_asignada' => $user->bodega_asignada,
                'two_factor_enabled' => (bool) $user->two_factor_enabled,
            ],
        ]);
    }
}
