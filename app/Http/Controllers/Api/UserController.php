<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    public function index()
    {
        return response()->json(
            User::orderBy('id')
                ->get(['id', 'name', 'username', 'email', 'rol', 'bodega_asignada', 'two_factor_enabled', 'created_at'])
        );
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'name' => 'required|string|max:255',
            'username' => 'required|string|max:100|unique:users,username',
            'email' => 'nullable|email|max:255',
            'password' => 'required|string|min:8|max:255',
            'rol' => 'required|string|in:Administrador,Operador de Almacén',
            'bodega_asignada' => 'required|string|max:255',
        ]);

        $user = User::create([
            'name' => $datos['name'],
            'username' => $datos['username'],
            'email' => $datos['email'] ?? null,
            'password' => Hash::make($datos['password']),
            'rol' => $datos['rol'],
            'bodega_asignada' => $datos['bodega_asignada'],
        ]);

        app(AuditoriaService::class)->registrar('CREAR', 'USUARIOS', 'Usuario creado.', ['id' => $user->id], $request);

        return response()->json($user->only(['id', 'name', 'username', 'email', 'rol', 'bodega_asignada']), 201);
    }

    public function update(Request $request, User $user)
    {
        $datos = $request->validate([
            'name' => 'required|string|max:255',
            'rol' => 'required|string|in:Administrador,Operador de Almacén',
            'bodega_asignada' => 'required|string|max:255',
        ]);

        if ($user->rol === 'Administrador' && $datos['rol'] !== 'Administrador') {
            $admins = User::where('rol', 'Administrador')->count();

            if ($admins <= 1) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'No se puede degradar al único administrador.',
                ], 409);
            }
        }

        $user->update($datos);

        app(AuditoriaService::class)->registrar('ACTUALIZAR', 'USUARIOS', 'Usuario actualizado.', ['id' => $user->id], $request);

        return response()->json($user->only(['id', 'name', 'username', 'email', 'rol', 'bodega_asignada']));
    }

    public function resetTwoFactor(Request $request, User $user)
    {
        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_last_timestamp' => null,
        ])->save();

        app(AuditoriaService::class)->registrar('RESET_2FA', 'USUARIOS', '2FA reiniciado.', ['id' => $user->id], $request);

        return response()->json(['status' => 'success', 'message' => '2FA reiniciado.']);
    }
}
