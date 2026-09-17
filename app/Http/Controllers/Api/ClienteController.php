<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cliente;
use Illuminate\Http\Request;

class ClienteController extends Controller
{
    public function index()
    {
        return response()->json(
            Cliente::orderBy('id_cliente')->get()
        );
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255',
            'telefono' => 'nullable|string|max:30',
            'correo' => 'nullable|email|max:255',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'required|string|max:50',
        ]);

        $cliente = Cliente::create($datos);

        return response()->json($cliente, 201);
    }

    public function show(Cliente $cliente)
    {
        return response()->json(
            $cliente->load('pedidos')
        );
    }

    public function update(Request $request, Cliente $cliente)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255',
            'telefono' => 'nullable|string|max:30',
            'correo' => 'nullable|email|max:255',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'required|string|max:50',
        ]);

        $cliente->update($datos);

        return response()->json($cliente);
    }

    public function destroy(Cliente $cliente)
    {
        if ($cliente->pedidos()->exists()) {
            return response()->json([
                'mensaje' => 'No se puede eliminar un cliente que tiene pedidos.'
            ], 409);
        }

        $cliente->delete();

        return response()->json([
            'mensaje' => 'Cliente eliminado correctamente.'
        ]);
    }
}