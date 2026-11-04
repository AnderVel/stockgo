<?php

namespace App\Services;

use App\Mail\PasswordResetCodeMail;
use App\Models\EmailVerificationToken;
use App\Models\User;
use App\Models\WebRefreshToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;

class PasswordResetCodeService
{
    private const TYPE = 'restablecimiento';

    private const EXPIRY_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    private const MAX_REQUESTS_PER_ACCOUNT = 3;

    private const ACCOUNT_WINDOW_SECONDS = 3600;

    /**
     * Issue a one-time code and send it to the address stored on the account.
     */
    public function requestCode(string $email): void
    {
        if ($this->mailerWritesMessageBodiesToLogs()) {
            Log::warning('Password recovery email was not sent because the configured mailer logs message bodies.');

            return;
        }

        $normalizedEmail = mb_strtolower(trim($email));
        $limiterKey = 'password-reset:account:'.hash_hmac('sha256', $normalizedEmail, $this->key());

        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_REQUESTS_PER_ACCOUNT)) {
            return;
        }

        $user = $this->findUser($normalizedEmail);

        if (! $user || $user->activo === false) {
            RateLimiter::hit($limiterKey, self::ACCOUNT_WINDOW_SECONDS);

            return;
        }

        [$recordId, $code] = DB::transaction(function () use ($user): array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $lockedUser || $lockedUser->activo === false) {
                return [null, null];
            }

            $recentRequests = EmailVerificationToken::query()
                ->where('user_id', $lockedUser->id)
                ->where('tipo', self::TYPE)
                ->where('created_at', '>', now()->subSeconds(self::ACCOUNT_WINDOW_SECONDS))
                ->where(function ($query): void {
                    $query->whereNull('used_at')
                        ->orWhereColumn('expires_at', '>', 'used_at');
                })
                ->count();

            if ($recentRequests >= self::MAX_REQUESTS_PER_ACCOUNT) {
                return [null, null];
            }

            EmailVerificationToken::query()
                ->where('user_id', $lockedUser->id)
                ->where('tipo', self::TYPE)
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            do {
                $newCode = str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
                $codeHash = $this->hashCode($lockedUser->id, $newCode);
            } while (EmailVerificationToken::query()->where('token_hash', $codeHash)->exists());

            $record = EmailVerificationToken::create([
                'user_id' => $lockedUser->id,
                'token_hash' => $codeHash,
                'tipo' => self::TYPE,
                'validation_attempts' => 0,
                'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
            ]);

            return [$record->id, $newCode];
        });

        if (! $recordId || ! $code) {
            return;
        }

        try {
            Mail::to($user->email)->send(new PasswordResetCodeMail($code, self::EXPIRY_MINUTES));
            RateLimiter::hit($limiterKey, self::ACCOUNT_WINDOW_SECONDS);
        } catch (\Throwable $exception) {
            $invalidatedAt = now();

            EmailVerificationToken::query()
                ->whereKey($recordId)
                ->whereNull('used_at')
                ->update([
                    'used_at' => $invalidatedAt,
                    'expires_at' => $invalidatedAt,
                ]);

            Log::warning('Password recovery email delivery failed.', [
                'user_id' => $user->id,
                'exception' => $exception::class,
            ]);
        }
    }

    public function verifyCode(string $email, string $code): bool
    {
        $user = $this->findUser(mb_strtolower(trim($email)));

        if (! $user || $user->activo === false) {
            return false;
        }

        return DB::transaction(function () use ($user, $code): bool {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $lockedUser || $lockedUser->activo === false) {
                return false;
            }

            $record = $this->activeRecord($lockedUser->id, true);

            return $record !== null && $this->codeMatches($record, $lockedUser->id, $code);
        });
    }

    /**
     * @return array{status: 'invalid'|'password_invalid'|'completed', errors?: string[], user_id?: int}
     */
    public function complete(
        string $email,
        string $code,
        string $password,
        PasswordPolicyService $passwordPolicy
    ): array {
        $user = $this->findUser(mb_strtolower(trim($email)));

        if (! $user || $user->activo === false) {
            return ['status' => 'invalid'];
        }

        return DB::transaction(function () use ($user, $code, $password, $passwordPolicy): array {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();

            if (! $lockedUser || $lockedUser->activo === false) {
                return ['status' => 'invalid'];
            }

            $record = $this->activeRecord($lockedUser->id, true);

            if (! $record || ! $this->codeMatches($record, $lockedUser->id, $code)) {
                return ['status' => 'invalid'];
            }

            $errors = $passwordPolicy->validar($password, $lockedUser->username, $lockedUser->email);

            if ($errors !== []) {
                return ['status' => 'password_invalid', 'errors' => $errors];
            }

            $lockedUser->forceFill(['password' => Hash::make($password)])->save();
            $record->update(['used_at' => now()]);
            $lockedUser->tokens()->delete();
            WebRefreshToken::query()
                ->where('user_id', $lockedUser->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);

            return ['status' => 'completed', 'user_id' => $lockedUser->id];
        });
    }

    private function activeRecord(int $userId, bool $forUpdate): ?EmailVerificationToken
    {
        $query = EmailVerificationToken::query()
            ->where('user_id', $userId)
            ->where('tipo', self::TYPE)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $record = $query->first();

        if ($record && $record->validation_attempts >= self::MAX_ATTEMPTS) {
            $record->update(['used_at' => now()]);

            return null;
        }

        return $record;
    }

    private function codeMatches(EmailVerificationToken $record, int $userId, string $code): bool
    {
        if (hash_equals($record->token_hash, $this->hashCode($userId, $code))) {
            return true;
        }

        $attempts = $record->validation_attempts + 1;
        $record->update([
            'validation_attempts' => $attempts,
            'used_at' => $attempts >= self::MAX_ATTEMPTS ? now() : null,
        ]);

        return false;
    }

    private function findUser(string $normalizedEmail): ?User
    {
        return User::query()->whereRaw('LOWER(email) = ?', [$normalizedEmail])->first();
    }

    private function hashCode(int $userId, string $code): string
    {
        return hash_hmac('sha256', $userId.'|'.self::TYPE.'|'.$code, $this->key());
    }

    private function key(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('Application key is not configured.');
        }

        return $key;
    }

    private function mailerWritesMessageBodiesToLogs(): bool
    {
        $mailers = config('mail.mailers', []);
        $pending = [(string) config('mail.default')];
        $checked = [];

        while ($pending !== []) {
            $name = array_pop($pending);

            if (isset($checked[$name])) {
                continue;
            }

            $checked[$name] = true;
            $mailer = $mailers[$name] ?? [];
            $transport = $mailer['transport'] ?? null;

            if ($transport === 'log') {
                return true;
            }

            if (in_array($transport, ['failover', 'roundrobin'], true)) {
                array_push($pending, ...($mailer['mailers'] ?? []));
            }
        }

        return false;
    }
}
