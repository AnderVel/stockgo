<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\JwtService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class JwtAuthenticate
{
    public function handle(Request $request, Closure $next): Response
    {
        $header = $request->header('Authorization', '');

        if (!str_starts_with($header, 'Bearer ')) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autenticado.',
            ], 401);
        }

        try {
            $payload = app(JwtService::class)->validar(substr($header, 7));
        } catch (\Throwable) {
            return response()->json([
                'status' => 'error',
                'message' => 'Token inválido o expirado.',
            ], 401);
        }

        $user = User::find($payload['sub'] ?? null);

        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'No autenticado.',
            ], 401);
        }

        auth()->setUser($user);
        $request->setUserResolver(fn () => $user);

        return $next($request);
    }
}
