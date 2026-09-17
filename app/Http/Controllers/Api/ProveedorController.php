<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Proveedor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProveedorController extends Controller
{
    public function index()
    {
        return response()->json(
            Proveedor::orderBy('id_proveedor')->get()
        );
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255',
            'contacto' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:30',
            'correo' => 'nullable|email|max:255',
            'direccion' => 'nullable|string|max:255',
            'estado' => 'required|string|max:50',
        ]);

        $proveedor = Proveedor::create($datos);

        return response()->json($proveedor, 201);
    }

    public function show(Proveedor $proveedor)
    {
        return response()->json($proveedor);
    }

    public function update(Request $request, Proveedor $proveedor)
    {
        $datos = $request->validate([
            'nombre' => 'required|string|max:255',
            'contacto' => 'nullable|string|max:255',
            'telefono' => 'nullable|string|max:30',
            'correo' => [
                'nullable',
                'email',
                'max:255',
            ],
            'direccion' => 'nullable|string|max:255',
            'estado' => 'required|string|max:50',
        ]);

        $proveedor->update($datos);

        return response()->json($proveedor);
    }

    public function destroy(Proveedor $proveedor)
    {
        $proveedor->delete();

        return response()->json([
            'mensaje' => 'Proveedor eliminado correctamente.'
        ]);
    }
}