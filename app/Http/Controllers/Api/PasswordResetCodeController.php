<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditoriaService;
use App\Services\PasswordPolicyService;
use App\Services\PasswordResetCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PasswordResetCodeController extends Controller
{
    /**
     * POST /api/auth/password-reset/request-code
     * Siempre responde igual: no revela si el correo existe.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        app(PasswordResetCodeService::class)->requestCode($datos['email']);

        app(AuditoriaService::class)->registrar(
            'SOLICITUD_RESET_CODIGO',
            'AUTH',
            'Solicitud de recuperación de contraseña por código.',
            ['correo_solicitado' => true],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Si la cuenta existe, enviamos un código de recuperación de 8 dígitos al correo registrado.',
        ]);
    }

    /**
     * POST /api/auth/password-reset/verify-code
     */
    public function verifyCode(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:8'],
        ]);

        $valido = app(PasswordResetCodeService::class)->verifyCode(
            $datos['email'],
            $datos['code']
        );

        app(AuditoriaService::class)->registrar(
            $valido ? 'VALIDACION_RESET_EXITOSA' : 'VALIDACION_RESET_FALLIDA',
            'AUTH',
            $valido
                ? 'Código de recuperación validado.'
                : 'Intento con código de recuperación inválido o expirado.',
            [],
            $request
        );

        if (! $valido) {
            return response()->json([
                'status' => 'error',
                'message' => 'El código no es válido o expiró.',
            ], 422);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Código válido. Ingresa tu nueva contraseña.',
        ]);
    }

    /**
     * POST /api/auth/password-reset/complete
     */
    public function complete(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'code' => ['required', 'string', 'size:8'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        $resultado = app(PasswordResetCodeService::class)->complete(
            $datos['email'],
            $datos['code'],
            $datos['password'],
            app(PasswordPolicyService::class)
        );

        if ($resultado['status'] === 'invalid') {
            app(AuditoriaService::class)->registrar(
                'RESET_COMPLETO_FALLIDO',
                'AUTH',
                'Intento de restablecimiento con código inválido o expirado.',
                [],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => 'El código no es válido o expiró. Solicita uno nuevo.',
            ], 422);
        }

        if ($resultado['status'] === 'password_invalid') {
            app(AuditoriaService::class)->registrar(
                'RESET_CONTRASENA_RECHAZADA',
                'AUTH',
                'La nueva contraseña no cumplió la política de seguridad.',
                ['motivos' => $resultado['errors']],
                $request
            );

            return response()->json([
                'status' => 'error',
                'message' => implode(' ', $resultado['errors']),
                'errors' => ['password' => $resultado['errors']],
            ], 422);
        }

        app(AuditoriaService::class)->registrar(
            'RESET_CONTRASENA',
            'AUTH',
            'Contraseña restablecida mediante código de un solo uso.',
            ['usuario_id' => $resultado['user_id'] ?? null],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Contraseña actualizada. Inicia sesión con tu nueva contraseña.',
        ]);
    }
}
