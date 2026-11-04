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

        if (! $user || $user->activo === false) {
            return response()->json([
                'status' => 'error',
                'message' => 'La cuenta está inactiva.',
            ], 403);
        }

        $permisos = config(
            'permissions.roles.'.($user?->rol ?? ''),
            []
        );

        if (! in_array($permission, $permisos, true)) {
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

        // La auditoría de la operación real la registran los propios
        // controladores (CREAR/ENTRADA/SURTIR/...). Aquí solo se anotan
        // los fallos (4xx/5xx) distintos del "sin permiso" ya registrado,
        // para no duplicar filas "PERMITIDO / Operación autorizada".
        $status = $response->getStatusCode();

        if ($status >= 400 && $status !== 403) {
            app(AuditoriaService::class)->registrar(
                'FALLO',
                $this->modulo($request),
                'Operación rechazada ('.$status.').',
                [
                    'permiso' => $permission,
                    'metodo' => $request->method(),
                    'ruta' => $request->path(),
                    'rol' => $user?->rol,
                    'status' => $status,
                ],
                $request
            );
        }

        return $response;
    }

    private function modulo(Request $request): string
    {
        $segmentos = $request->segments();

        return strtoupper($segmentos[1] ?? 'API');
    }
}
