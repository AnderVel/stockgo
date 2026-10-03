<?php

namespace App\Services;

use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use RuntimeException;

class JwtService
{
    public function emitirAccessToken(User $user): string
    {
        $ttl = (int) config('jwt.ttl_minutes', 30);

        return JWT::encode([
            'iss' => config('app.url'),
            'sub' => $user->id,
            'username' => $user->username,
            'rol' => $user->rol,
            'iat' => time(),
            'exp' => time() + ($ttl * 60),
        ], $this->secret(), 'HS256');
    }

    public function validar(string $token): array
    {
        $payload = JWT::decode($token, new Key($this->secret(), 'HS256'));

        return (array) $payload;
    }

    private function secret(): string
    {
        $secret = config('jwt.secret');

        if (!$secret) {
            throw new RuntimeException('JWT_SECRET no está configurado.');
        }

        return $secret;
    }
}
