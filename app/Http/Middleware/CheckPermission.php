<?php

namespace App\Http\Middleware;

use App\Services\AuditoriaService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(
        Request $request,
        Closure $next,
        string $permission
    ): Response {
        $user = $request->user();

        $permisos = config(
            'permissions.roles.' . ($user?->rol ?? ''),
            []
        );

        if (!in_array($permission, $permisos, true)) {
            app(AuditoriaService::class)->registrar(
                'DENEGADO',
                $this->modulo($request),
                'Intento de operación sin permiso.',
                [
                    'permiso' => $permission,
                    'metodo' => $request->method(),
                    'ruta' => $request->path(),
                    'rol' => $user?->rol,
                    'status' => 403,
                ],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => 'No tienes permisos para realizar esta operación.',
            ], 403);
        }

        $response = $next($request);

        app(AuditoriaService::class)->registrar(
            'PERMITIDO',
            $this->modulo($request),
            'Operación autorizada.',
            [
                'permiso' => $permission,
                'metodo' => $request->method(),
                'ruta' => $request->path(),
                'rol' => $user?->rol,
                'status' => $response->getStatusCode(),
            ],
            $request
        );

        return $response;
    }

    private function modulo(Request $request): string
    {
        $segmentos = $request->segments();

        return strtoupper($segmentos[1] ?? 'API');
    }
}
