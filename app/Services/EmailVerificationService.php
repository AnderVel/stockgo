<?php

namespace App\Services;

use App\Models\EmailVerificationToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Tokens de verificación de correo (64 chars) usados por AuthController
 * en /auth/email/send-verification y /auth/email/verify-code.
 */
class EmailVerificationService
{
    public const TIPO_VERIFICACION = 'verificacion';

    private const MAX_REENVIOS_VENTANA = 3;

    private const VENTANA_REENVIO_MINUTOS = 10;

    public function puedeReenviar(User $user): bool
    {
        $recientes = EmailVerificationToken::query()
            ->where('user_id', $user->id)
            ->where('tipo', self::TIPO_VERIFICACION)
            ->where('created_at', '>', now()->subMinutes(self::VENTANA_REENVIO_MINUTOS))
            ->count();

        return $recientes < self::MAX_REENVIOS_VENTANA;
    }

    /**
     * Genera un token aleatorio de 64 caracteres y guarda su hash.
     * Invalida los tokens de verificación previos sin usar.
     */
    public function generarToken(User $user): string
    {
        $expiration = (int) config('email_verification.expiration_minutes', 15);

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        DB::transaction(function () use ($user, $hash, $expiration): void {
            EmailVerificationToken::query()
                ->where('user_id', $user->id)
                ->where('tipo', self::TIPO_VERIFICACION)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            EmailVerificationToken::create([
                'user_id' => $user->id,
                'token_hash' => $hash,
                'tipo' => self::TIPO_VERIFICACION,
                'validation_attempts' => 0,
                'expires_at' => now()->addMinutes($expiration),
            ]);
        });

        return $token;
    }

    /**
     * Valida el token presentado. Si es correcto marca el registro
     * como usado y devuelve el usuario; null en caso contrario.
     */
    public function validarToken(string $token, string $tipo, User $user): ?User
    {
        $hash = hash('sha256', $token);

        return DB::transaction(function () use ($user, $tipo, $hash): ?User {
            $record = EmailVerificationToken::query()
                ->where('user_id', $user->id)
                ->where('tipo', $tipo)
                ->where('token_hash', $hash)
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->first();

            if (! $record) {
                return null;
            }

            $record->update(['used_at' => now()]);

            return $user;
        });
    }

    public function invalidarToken(User $user, string $token): void
    {
        EmailVerificationToken::query()
            ->where('user_id', $user->id)
            ->where('tipo', self::TIPO_VERIFICACION)
            ->where('token_hash', hash('sha256', $token))
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('Application key is not configured.');
        }

        return $key;
    }
}
