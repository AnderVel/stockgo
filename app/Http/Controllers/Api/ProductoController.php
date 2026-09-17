<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductoController extends Controller
{
    public function index()
    {
        return response()->json(
            Producto::orderBy('id_producto')->get()
        );
    }

    public function buscarPorCodigo($codigo)
    {
        $producto = Producto::where(
            'codigo_barras',
            $codigo
        )->first();

        if (!$producto) {
            return response()->json([
                'mensaje' => 'Producto no encontrado.'
            ], 404);
        }

        return response()->json($producto);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'codigo_barras' =>
                'required|string|unique:productos,codigo_barras',

            'nombre' =>
                'required|string|max:255',

            'unidad_medida' =>
                'required|string|max:100',

            'precio' =>
                'required|numeric|min:0',

            'stock_fisico' =>
                'required|integer|min:0',

            'stock_reservado' =>
                'required|integer|min:0',

            'estado' =>
                'required|string|max:50',

            'ubicacion' =>
                'nullable|string|max:255',
        ]);

        $datos['stock_disponible'] =
            $datos['stock_fisico'] -
            $datos['stock_reservado'];

        if ($datos['stock_disponible'] < 0) {
            return response()->json([
                'mensaje' =>
                    'El stock reservado no puede ser mayor al stock físico.'
            ], 422);
        }

        $producto = Producto::create($datos);

        return response()->json(
            $producto,
            201
        );
    }

    public function show(Producto $producto)
    {
        return response()->json(
            $producto->load('movimientos')
        );
    }

    public function update(
        Request $request,
        Producto $producto
    ) {
        $datos = $request->validate([
            'codigo_barras' => [
                'required',
                'string',
                Rule::unique(
                    'productos',
                    'codigo_barras'
                )->ignore(
                    $producto->id_producto,
                    'id_producto'
                ),
            ],

            'nombre' =>
                'required|string|max:255',

            'unidad_medida' =>
                'required|string|max:100',

            'precio' =>
                'required|numeric|min:0',

            'estado' =>
                'required|string|max:50',

            'ubicacion' =>
                'nullable|string|max:255',
        ]);

        $producto->update($datos);

        return response()->json($producto);
    }

    public function destroy(Producto $producto)
    {
        if (
            $producto->stock_reservado > 0
        ) {
            return response()->json([
                'mensaje' =>
                    'No se puede eliminar un producto con stock reservado.'
            ], 409);
        }

        $producto->delete();

        return response()->json([
            'mensaje' =>
                'Producto eliminado correctamente.'
        ]);
    }
}