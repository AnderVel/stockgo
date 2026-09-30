<?php

namespace App\Services;

use App\Models\Auditoria;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditoriaService
{
    public function registrar(
        string $accion,
        string $modulo,
        string $descripcion,
        array $detalles = [],
        ?Request $request = null
    ): Auditoria {
        $request = $request ?? request();

        return Auditoria::create([
            'usuario_id' => Auth::id(),
            'accion' => $accion,
            'modulo' => $modulo,
            'descripcion' => $descripcion,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'detalles' => $detalles ?: null,
        ]);
    }
}
