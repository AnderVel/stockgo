<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->activo === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'La cuenta está inactiva.',
            ], 403);
        }

        return $next($request);
    }
}
