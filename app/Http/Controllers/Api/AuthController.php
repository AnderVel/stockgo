<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $datos = $request->validate([
            'usuario' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where(
            'username',
            $datos['usuario']
        )->first();

        if (
            !$user ||
            !Hash::check(
                $datos['password'],
                $user->password
            )
        ) {
            return response()->json([
                'status' => 'error',
                'message' => 'Usuario o contraseña incorrectos.'
            ], 401);
        }

        $token = $user
            ->createToken('android-stockgo')
            ->plainTextToken;

        return response()->json([
            'status' => 'success',
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'username' => $user->name,
                'rol' => $user->rol,
                'bodega_asignada' => $user->bodega_asignada,
            ]
        ]);
    }
}