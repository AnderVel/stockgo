<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\WebRefreshToken;
use App\Services\AuditoriaService;
use App\Services\PasswordPolicyService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::query()->orderBy('id');

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($builder) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';
                $builder->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(username) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(rol) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(bodega_asignada) LIKE ?', [$term]);
            });
        }

        $query->select([
            'id',
            'name',
            'username',
            'email',
            'rol',
            'activo',
            'bodega_asignada',
            'two_factor_enabled',
            'created_at',
        ]);

        if ($request->boolean('paginate')) {
            $perPage = min(max($request->integer('per_page', 25), 1), 50);

            return response()->json($query->paginate($perPage));
        }

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:100|unique:users,username',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:12|max:255',
            'rol' => 'required|string|in:Administrador,Administrador Regular,Operador de Almacén',
            'bodega_asignada' => 'required|string|max:255',
        ]);

        // Validar política de contraseñas
        $erroresPassword = app(PasswordPolicyService::class)->validar(
            $datos['password'],
            $datos['username'],
            $datos['email']
        );

        if (! empty($erroresPassword)) {
            return response()->json([
                'status' => 'error',
                'message' => 'La contraseña no cumple la política de seguridad.',
                'errors' => $erroresPassword,
            ], 422);
        }

        $user = User::create([
            'name' => $datos['name'],
            'username' => $datos['username'],
            'email' => $datos['email'] ?? null,
            'password' => Hash::make($datos['password']),
            'rol' => $datos['rol'],
            'bodega_asignada' => $datos['bodega_asignada'],
            'activo' => true,
        ]);

        app(AuditoriaService::class)->registrar('CREAR', 'USUARIOS', 'Usuario creado.', ['id' => $user->id], $request);

        return response()->json($user->only(['id', 'name', 'username', 'email', 'rol', 'activo', 'bodega_asignada']), 201);
    }

    public function update(Request $request, User $user)
    {
        $datos = $request->validate([
            'name' => 'required|string|max:255',
            'rol' => 'required|string|in:Administrador,Administrador Regular,Operador de Almacén',
            'activo' => 'required|boolean',
            'bodega_asignada' => 'required|string|max:255',
        ]);

        if (
            $request->user()?->is($user)
            && ($datos['rol'] !== 'Administrador' || ! $datos['activo'])
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'No puedes desactivar o degradar tu propia cuenta de administrador.',
            ], 409);
        }

        if ($user->rol === 'Administrador' && ($datos['rol'] !== 'Administrador' || ! $datos['activo'])) {
            $admins = User::where('rol', 'Administrador')->where('activo', true)->count();

            if ($admins <= 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede degradar al único administrador.',
                ], 409);
            }
        }

        // Detectar si cambió el rol o se desactivó el usuario para revocar sesiones web
        $rolAnterior = $user->rol;
        $activoAnterior = $user->activo;
        $debeRevocarSesiones = $rolAnterior !== $datos['rol'] || ($activoAnterior && ! $datos['activo']);

        // Preservar username y email originales, no permitir cambios
        $user->update([
            'name' => $datos['name'],
            'rol' => $datos['rol'],
            'activo' => $datos['activo'],
            'bodega_asignada' => $datos['bodega_asignada'],
        ]);

        // Revocar refresh tokens web si cambió el rol o se desactivó el usuario
        if ($debeRevocarSesiones) {
            WebRefreshToken::where('user_id', $user->id)
                ->whereNull('revoked_at')
                ->update(['revoked_at' => now()]);
        }

        app(AuditoriaService::class)->registrar('ACTUALIZAR', 'USUARIOS', 'Usuario actualizado.', ['id' => $user->id], $request);

        return response()->json($user->only(['id', 'name', 'username', 'email', 'rol', 'activo', 'bodega_asignada']));
    }

    public function resetTwoFactor(Request $request, User $user)
    {
        $user->tokens()->delete();
        WebRefreshToken::where('user_id', $user->id)->update(['revoked_at' => now()]);

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_last_timestamp' => null,
        ])->save();

        app(AuditoriaService::class)->registrar('RESET_2FA', 'USUARIOS', '2FA reiniciado.', ['id' => $user->id], $request);

        return response()->json(['status' => 'success', 'message' => '2FA reiniciado.']);
    }

    public function destroy(Request $request, User $user)
    {
        // Solo el Administrador puede eliminar usuarios
        $currentUser = $request->user();
        if ($currentUser->rol !== 'Administrador') {
            return response()->json([
                'status' => 'error',
                'message' => 'Solo el Administrador puede eliminar usuarios.',
            ], 403);
        }

        // No permitir auto-eliminación
        if ($currentUser->is($user)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No puedes eliminar tu propia cuenta.',
            ], 409);
        }

        // No permitir eliminar al último administrador
        if ($user->rol === 'Administrador') {
            $admins = User::where('rol', 'Administrador')->where('activo', true)->count();
            if ($admins <= 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede eliminar al único administrador.',
                ], 409);
            }
        }

        $userId = $user->id;
        $userName = $user->name;

        try {
            $user->delete();

            app(AuditoriaService::class)->registrar(
                'ELIMINAR',
                'USUARIOS',
                'Usuario eliminado.',
                ['id' => $userId, 'nombre' => $userName],
                $request
            );

            return response()->json([
                'status' => 'success',
                'message' => 'Usuario eliminado correctamente.',
            ]);
        } catch (QueryException $e) {
            // Detectar SQLSTATE 23503 = foreign_key_violation (PostgreSQL)
            // $e->getCode() devuelve el SQLSTATE como string en Postgres
            // $e->errorInfo[1] contiene el código nativo del driver (23503 en Postgres)
            $sqlState = $e->getCode();
            $nativeCode = $e->errorInfo[1] ?? null;

            if ($sqlState === '23503' || $nativeCode === 23503) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede eliminar el usuario porque tiene historial de auditoría. Desactívelo en su lugar.',
                ], 409);
            }

            throw $e;
        }
    }
}
