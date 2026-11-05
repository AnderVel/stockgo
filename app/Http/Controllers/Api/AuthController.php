<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\VerificationEmail;
use App\Models\User;
use App\Models\WebRefreshToken;
use App\Services\AuditoriaService;
use App\Services\EmailVerificationService;
use App\Services\PasswordPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
        $key = 'login:'.$usuario.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Demasiados intentos de inicio de sesión. Intenta nuevamente más tarde.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        // Busqueda sin distinguir mayusculas: el usuario se guarda igual que
        // como se escribe, pero el login siempre compara en minusculas.
        $user = User::whereRaw('LOWER(username) = ?', [$usuario])->first();

        if (
            ! $user ||
            ! Hash::check($datos['password'], $user->password)
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

        if ($user->activo === false) {
            app(AuditoriaService::class)->registrar(
                'LOGIN_ANDROID_RECHAZADO',
                'AUTH',
                'Intento de acceso con una cuenta inactiva.',
                ['usuario' => $usuario],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => 'La cuenta está inactiva.',
            ], 403);
        }

        RateLimiter::clear($key);

        $deviceName = $datos['device_name'] ?? 'android-stockgo';

        if (! $user->two_factor_enabled) {
            $token = $user->createToken(
                'stockgo-2fa-setup',
                ['2fa-setup'],
                now()->addMinutes(10)
            )->plainTextToken;

            app(AuditoriaService::class)->registrar(
                'LOGIN_ANDROID',
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
            'LOGIN_ANDROID',
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

        $google2fa = new Google2FA;

        if (! $user->two_factor_secret) {
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

        if (! $user->two_factor_secret) {
            return response()->json([
                'status' => 'error',
                'message' => 'Primero debes iniciar la configuración del segundo factor.',
            ], 422);
        }

        $google2fa = new Google2FA;

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
            '2FA_ANDROID_ACTIVADO',
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

        if (! $user->two_factor_enabled || ! $user->two_factor_secret) {
            return response()->json([
                'status' => 'error',
                'message' => 'La autenticación de dos factores no está configurada.',
            ], 422);
        }

        $key = '2fa:'.$user->id.'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Demasiados intentos de verificación. Intenta nuevamente más tarde.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        $google2fa = new Google2FA;

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
            '2FA_ANDROID_VERIFICADO',
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

        app(AuditoriaService::class)->registrar(
            'LOGIN_ANDROID_EXITOSO',
            'AUTH',
            'Inicio de sesión Android completado.',
            ['usuario' => $user->username],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Autenticación completada correctamente.',
            'token' => $token,
            'expires_in' => 28800,
            'user' => $this->userData($user),
        ]);
    }

    public function forgotPassword(Request $request)
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
        ]);

        if ($this->passwordResetMailerWritesMessagesToLogs()) {
            Log::warning('Password reset email was not sent because the configured mailer logs message bodies.');
        } else {
            try {
                Password::sendResetLink($datos);
            } catch (\Throwable $exception) {
                $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower(trim($datos['email']))])->first();

                if ($user) {
                    Password::getRepository()->delete($user);
                }

                Log::warning('Password reset email delivery failed.', [
                    'user_id' => $user?->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        app(AuditoriaService::class)->registrar(
            'SOLICITUD_RESTABLECIMIENTO',
            'AUTH',
            'Solicitud de restablecimiento de contraseña.',
            [],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Si existe una cuenta con ese correo, se enviará un enlace de restablecimiento.',
        ]);
    }

    public function resetPassword(Request $request)
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $resetUser = null;
        $erroresPassword = [];

        try {
            $status = Password::reset(
                $datos,
                function ($user, $password) use (&$resetUser, &$erroresPassword): void {
                    $erroresPassword = app(PasswordPolicyService::class)->validar(
                        $password,
                        $user->username,
                        $user->email
                    );

                    if ($erroresPassword !== []) {
                        throw ValidationException::withMessages([
                            'password' => $erroresPassword,
                        ]);
                    }

                    $user->forceFill([
                        'password' => Hash::make($password),
                    ])->save();

                    $resetUser = $user;
                }
            );
        } catch (ValidationException) {
            return response()->json([
                'status' => 'error',
                'message' => 'La contraseña no cumple la política de seguridad.',
                'errors' => $erroresPassword,
            ], 422);
        }

        if ($status === Password::PASSWORD_RESET) {
            // Revocar tokens de sesión web y Android después de restablecer contraseña
            if ($resetUser) {
                $resetUser->tokens()->delete();
                WebRefreshToken::where('user_id', $resetUser->id)->update(['revoked_at' => now()]);
            }

            app(AuditoriaService::class)->registrar(
                'RESTABLECER_CONTRASENA',
                'AUTH',
                'Contraseña restablecida correctamente.',
                [],
                $request,
                $resetUser->id
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Contraseña restablecida correctamente. Tus sesiones anteriores han sido cerradas.',
            ]);
        }

        return response()->json([
            'status' => 'error',
            'message' => 'No se pudo restablecer la contraseña.',
        ], 422);
    }

    public function me(Request $request)
    {
        if ($request->user()->activo === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'La cuenta está inactiva.',
            ], 403);
        }

        return response()->json([
            'status' => 'success',
            'user' => $this->userData($request->user()),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()?->delete();

        if ($request->filled('refresh_token')) {
            WebRefreshToken::where(
                'token_hash',
                hash('sha256', $request->input('refresh_token'))
            )->update(['revoked_at' => now()]);
        }

        app(AuditoriaService::class)->registrar(
            'LOGOUT_ANDROID',
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

    public function changePassword(Request $request)
    {
        $datos = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);

        $user = $request->user();

        if (! Hash::check($datos['current_password'], $user->password)) {
            app(AuditoriaService::class)->registrar(
                'CAMBIO_CONTRASENA_FALLIDO',
                'AUTH',
                'Intento de cambio de contraseña con contraseña actual incorrecta.',
                [],
                $request,
                $user->id
            );

            return response()->json([
                'status' => 'error',
                'message' => 'La contraseña actual es incorrecta.',
            ], 422);
        }

        // Validar política de contraseñas para la nueva contraseña
        $erroresPassword = app(PasswordPolicyService::class)->validar(
            $datos['password'],
            $user->username,
            $user->email
        );

        if (! empty($erroresPassword)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La nueva contraseña no cumple la política de seguridad.',
                'errors' => $erroresPassword,
            ], 422);
        }

        $user->forceFill([
            'password' => Hash::make($datos['password']),
        ])->save();

        // Revocar todos los tokens del usuario excepto el actual
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()?->id)->delete();
        WebRefreshToken::where('user_id', $user->id)->update(['revoked_at' => now()]);

        app(AuditoriaService::class)->registrar(
            'CAMBIO_CONTRASENA_EXITOSO',
            'AUTH',
            'Contraseña cambiada correctamente.',
            [],
            $request,
            $user->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Contraseña cambiada correctamente. Tus otras sesiones han sido cerradas.',
        ]);
    }

    public function sendEmailVerification(Request $request)
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $normalizedEmail = mb_strtolower(trim($datos['email']));

        if ($this->passwordResetMailerWritesMessagesToLogs()) {
            Log::warning('Email verification code was not sent because the configured mailer logs message bodies.');

            app(AuditoriaService::class)->registrar(
                'SOLICITUD_VERIFICACION_EMAIL',
                'AUTH',
                'Solicitud de verificación por correo.',
                [],
                $request
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Si existe una cuenta con ese correo, se enviará un código de verificación.',
            ]);
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        // Siempre respuesta genérica para no revelar si el usuario existe o está inactivo
        if (! $user || $user->activo === false) {
            app(AuditoriaService::class)->registrar(
                'SOLICITUD_VERIFICACION_EMAIL',
                'AUTH',
                'Solicitud de verificación por correo.',
                ['email' => $normalizedEmail],
                $request
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Si existe una cuenta con ese correo, se enviará un código de verificación.',
            ]);
        }

        $verificationService = app(EmailVerificationService::class);

        // Verificar rate limiting de reenvíos sin revelar si el correo existe
        if (! $verificationService->puedeReenviar($user)) {
            app(AuditoriaService::class)->registrar(
                'SOLICITUD_VERIFICACION_EMAIL',
                'AUTH',
                'Solicitud de verificación por correo.',
                ['usuario_id' => $user->id],
                $request,
                $user->id
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Si existe una cuenta con ese correo, se enviará un código de verificación.',
            ]);
        }

        $token = $verificationService->generarToken($user);

        // Enviar correo real con el token
        try {
            Mail::to($user->email)->send(new VerificationEmail($token, (int) config('email_verification.expiration_minutes', 15)));
        } catch (\Throwable $e) {
            $verificationService->invalidarToken($user, $token);

            // Log error but don't reveal to user
            Log::warning('Error enviando correo de verificación', [
                'exception' => $e::class,
                'user_id' => $user->id,
            ]);
        }

        app(AuditoriaService::class)->registrar(
            'SOLICITUD_VERIFICACION_EMAIL',
            'AUTH',
            'Código de verificación por correo enviado.',
            ['usuario_id' => $user->id],
            $request,
            $user->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Si existe una cuenta con ese correo, se enviará un código de verificación.',
        ]);
    }

    public function verifyEmailCode(Request $request)
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string', 'size:64'],
        ]);

        $normalizedEmail = mb_strtolower(trim($datos['email']));
        $user = User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();

        if (! $user || $user->activo === false) {
            app(AuditoriaService::class)->registrar(
                'VERIFICACION_EMAIL_FALLIDA',
                'AUTH',
                'Intento de verificación con correo inexistente.',
                ['email' => $normalizedEmail],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Código de verificación inválido.',
            ], 422);
        }

        $userVerificado = app(EmailVerificationService::class)->validarToken(
            $datos['code'],
            EmailVerificationService::TIPO_VERIFICACION,
            $user
        );

        if (! $userVerificado || $userVerificado->id !== $user->id) {
            app(AuditoriaService::class)->registrar(
                'VERIFICACION_EMAIL_FALLIDA',
                'AUTH',
                'Código de verificación inválido o expirado.',
                ['usuario_id' => $user->id],
                $request,
                $user->id
            );

            return response()->json([
                'status' => 'error',
                'message' => 'Código de verificación inválido o expirado.',
            ], 422);
        }

        // Marcar email como verificado (si existe el campo)
        if (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        app(AuditoriaService::class)->registrar(
            'VERIFICACION_EMAIL_EXITOSA',
            'AUTH',
            'Correo verificado correctamente.',
            ['usuario_id' => $user->id],
            $request,
            $user->id
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Correo verificado correctamente.',
        ]);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'username' => $user->username,
            'rol' => $user->rol,
            'bodega_id' => $user->bodega_id,
            'bodega_asignada' => $user->bodega?->nombre ?? $user->bodega_asignada,
            'activo' => (bool) $user->activo,
            'two_factor_enabled' => (bool) $user->two_factor_enabled,
        ];
    }

    private function passwordResetMailerWritesMessagesToLogs(): bool
    {
        $mailers = config('mail.mailers', []);
        $pendingMailers = [(string) config('mail.default')];
        $checkedMailers = [];

        while ($pendingMailers !== []) {
            $mailerName = array_pop($pendingMailers);

            if (isset($checkedMailers[$mailerName])) {
                continue;
            }

            $checkedMailers[$mailerName] = true;
            $mailer = $mailers[$mailerName] ?? [];
            $transport = $mailer['transport'] ?? null;

            if ($transport === 'log') {
                return true;
            }

            if (in_array($transport, ['failover', 'roundrobin'], true)) {
                array_push($pendingMailers, ...($mailer['mailers'] ?? []));
            }
        }

        return false;
    }
}
