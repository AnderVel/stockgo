<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebRefreshToken;
use App\Services\AuditoriaService;
use App\Services\JwtService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use PDOException;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;

class WebAuthController extends Controller
{
    private const COOKIE = 'stockgo_refresh';

    public function login(Request $request)
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'code' => ['nullable', 'digits:6'],
        ]);

        $usuario = Str::lower(trim($datos['usuario']));
        $key = 'web-login:'.$usuario.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Demasiados intentos.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $user = User::where('username', $usuario)->first();

        if (! $user || ! Hash::check($datos['password'], $user->password)) {
            RateLimiter::hit($key, 60);

            app(AuditoriaService::class)->registrar(
                'LOGIN_WEB_FALLIDO',
                'AUTH',
                'Intento de inicio de sesión web fallido.',
                ['usuario' => $usuario],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Usuario o contraseña incorrectos.',
            ], 401);
        }

        if ($user->activo === false) {
            app(AuditoriaService::class)->registrar(
                'LOGIN_WEB_RECHAZADO',
                'AUTH',
                'Intento de acceso web con una cuenta inactiva.',
                ['usuario' => $usuario],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => 'La cuenta está inactiva.',
            ], 403);
        }

        // Allow both Administrador and Administrador Regular to access Web Admin
        if (! in_array($user->rol, ['Administrador', 'Administrador Regular'])) {
            app(AuditoriaService::class)->registrar(
                'LOGIN_WEB_RECHAZADO',
                'AUTH',
                'Intento de acceso web sin rol autorizado.',
                ['usuario' => $usuario, 'rol' => $user->rol],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => 'El rol no tiene acceso al Web Admin.',
            ], 403);
        }

        if (! $user->two_factor_enabled || ! $user->two_factor_secret) {
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

        $google2fa = new Google2FA;
        // Web usa verifyKey (no verifyKeyNewer) para no compartir two_factor_last_timestamp
        // con Android: ambos clientes pueden consumir el mismo TOTP con códigos distintos
        // dentro de la misma ventana de 30s sin bloquearse entre sí.
        // El replay dentro de la ventana se mitiga con rate limiting + rotación de refresh.
        if (! $google2fa->verifyKey($user->two_factor_secret, $datos['code'])) {
            RateLimiter::hit($key, 60);

            return response()->json([
                'status' => 'error',
                'message' => 'El código de autenticación no es válido.',
            ], 422);
        }

        RateLimiter::clear($key);

        try {
            app(AuditoriaService::class)->registrar(
                'LOGIN_WEB',
                'AUTH',
                'Inicio de sesión web.',
                [],
                $request,
                $user->id
            );

            return $this->emitirSesion($user);
        } catch (QueryException|PDOException|RuntimeException $exception) {
            // Un fallo transitorio de base de datos durante la emisión de la
            // sesión (auditoría o refresh token) no debe exponer un 500 no controlado.
            // Se responde con un error estructurado y reintentable.
            Log::error('Fallo de base de datos al emitir la sesión web.', [
                'sql_state' => (string) $exception->getCode(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'El servicio de sesión no está disponible temporalmente. Intenta de nuevo.',
            ], 503)->header('Retry-After', '2');
        }
    }

    public function setupTwoFactor(Request $request)
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
        ]);

        $usuario = Str::lower(trim($datos['usuario']));
        $user = User::where('username', $usuario)->first();

        if (! $user || ! Hash::check($datos['password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario o contraseña incorrectos.',
            ], 401);
        }

        if ($user->activo === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'La cuenta está inactiva.',
            ], 403);
        }

        // Allow both Administrador and Administrador Regular to setup 2FA for web
        if (! in_array($user->rol, ['Administrador', 'Administrador Regular'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'El rol no tiene acceso al Web Admin.',
            ], 403);
        }

        if ($user->two_factor_enabled) {
            return response()->json([
                'status' => 'error',
                'message' => 'La autenticación de dos factores ya está activada.',
            ], 409);
        }

        $google2fa = new Google2FA;

        if (! $user->two_factor_secret) {
            $user->forceFill([
                'two_factor_secret' => $google2fa->generateSecretKey(),
                'two_factor_enabled' => false,
                'two_factor_last_timestamp' => null,
            ])->save();
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Registra esta cuenta en tu aplicación TOTP y confirma con un código.',
            'secret' => $user->two_factor_secret,
            'otpauth_url' => $google2fa->getQRCodeUrl(
                config('app.name', 'StockGo'),
                $user->email ?: $user->username,
                $user->two_factor_secret
            ),
        ]);
    }

    public function confirmTwoFactor(Request $request)
    {
        $datos = $request->validate([
            'usuario' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
        ]);

        $usuario = Str::lower(trim($datos['usuario']));
        $user = User::where('username', $usuario)->first();

        if (! $user || ! Hash::check($datos['password'], $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario o contraseña incorrectos.',
            ], 401);
        }

        if ($user->activo === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'La cuenta está inactiva.',
            ], 403);
        }

        if (! in_array($user->rol, ['Administrador', 'Administrador Regular'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'El rol no tiene acceso al Web Admin.',
            ], 403);
        }

        if ($user->two_factor_enabled || ! $user->two_factor_secret) {
            return response()->json([
                'status' => 'error',
                'message' => $user->two_factor_enabled
                    ? 'La autenticación de dos factores ya está activada.'
                    : 'Primero solicita la configuración del segundo factor.',
            ], $user->two_factor_enabled ? 409 : 422);
        }

        if (! (new Google2FA)->verifyKey($user->two_factor_secret, $datos['code'])) {
            return response()->json([
                'status' => 'error',
                'message' => 'El código de autenticación no es válido.',
            ], 422);
        }

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_last_timestamp' => null,
        ])->save();

        app(AuditoriaService::class)->registrar(
            '2FA_WEB_ACTIVADO',
            'AUTH',
            '2FA Web activado correctamente.',
            [],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Autenticación de dos factores activada correctamente.',
        ]);
    }

    public function refresh(Request $request)
    {
        $refresh = $request->cookie(self::COOKIE);

        if (! $refresh) {
            return response()->json([
                'status' => 'error',
                'message' => 'No hay refresh token.',
            ], 401);
        }

        try {
            $token = DB::transaction(function () use ($refresh) {
                $t = WebRefreshToken::where('token_hash', hash('sha256', $refresh))
                    ->lockForUpdate()
                    ->first();

                if (! $t || ! $t->esValido()) {
                    return null;
                }

                $t->update(['revoked_at' => now()]);

                return $t;
            });
        } catch (QueryException|PDOException $exception) {
            // Evita reutilizar una conexión que Neon cerró o dejó en transacción abortada.
            DB::purge();

            Log::error('Fallo de base de datos al renovar la sesión web.', [
                'sql_state' => (string) $exception->getCode(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'El servicio de sesión no está disponible temporalmente. Intenta de nuevo.',
            ], 503)->header('Retry-After', '2');
        }

        if (! $token) {
            app(AuditoriaService::class)->registrar(
                'REFRESH_TOKEN_INVALIDO',
                'AUTH',
                'Intento de uso de refresh token inválido o expirado.',
                [],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Refresh token inválido o expirado.',
            ], 401);
        }

        // Verificar que el usuario asociado exista, esté activo y tenga rol autorizado para Web Admin
        $user = $token->user;
        if (! $user || $user->activo === false || ! in_array($user->rol, ['Administrador', 'Administrador Regular'])) {
            app(AuditoriaService::class)->registrar(
                'REFRESH_TOKEN_RECHAZADO',
                'AUTH',
                'Refresh token rechazado: usuario inactivo, inexistente o sin rol autorizado para Web Admin.',
                ['usuario_id' => $user?->id, 'activo' => $user?->activo, 'rol' => $user?->rol],
                $request,
                $user?->id
            );

            return response()->json([
                'status' => 'error',
                'message' => 'La cuenta está inactiva o no tiene acceso al Web Admin.',
            ], 403);
        }

        app(AuditoriaService::class)->registrar(
            'REFRESH_TOKEN_EXITOSO',
            'AUTH',
            'Refresh token rotado y nueva sesión emitida.',
            ['usuario_id' => $token->user_id],
            $request,
            $token->user_id
        );

        return $this->emitirSesion($user);
    }

    public function logout(Request $request)
    {
        $refresh = $request->cookie(self::COOKIE);

        if ($refresh) {
            WebRefreshToken::where('token_hash', hash('sha256', $refresh))
                ->update(['revoked_at' => now()]);
        }

        app(AuditoriaService::class)->registrar('LOGOUT_WEB', 'AUTH', 'Sesión web cerrada.', [], $request);

        return response()->json([
            'status' => 'success',
            'message' => 'Sesión cerrada correctamente.',
        ])->withCookie($this->cookie(''))->withoutCookie(self::COOKIE);
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
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'rol' => $user->rol,
                'bodega_asignada' => $user->bodega_asignada,
                'activo' => (bool) $user->activo,
                'two_factor_enabled' => (bool) $user->two_factor_enabled,
            ],
        ])->withCookie($this->cookie($refresh));
    }

    private function cookie(string $value): Cookie
    {
        return cookie(
            self::COOKIE,
            $value,
            (int) config('jwt.refresh_ttl_days', 7) * 24 * 60,
            '/',
            null,
            (bool) app()->isProduction(),
            true,   // HttpOnly
            false,
            'lax'   // SameSite
        );
    }
}
