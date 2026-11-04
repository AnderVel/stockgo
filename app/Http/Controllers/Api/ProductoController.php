<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Producto;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductoController extends Controller
{
    private const ROL_OPERADOR = 'Operador de Almacén';

    /**
     * Si el usuario es operador con bodega asignada, limita la consulta
     * a los productos de esa bodega (y a los aún sin bodega, para no
     * esconder datos heredados).
     */
    private function aplicarFiltroBodega($query, Request $request): void
    {
        $usuario = $request->user();

        if ($usuario && $usuario->rol === self::ROL_OPERADOR && $usuario->bodega_id) {
            $query->where('productos.bodega_id', $usuario->bodega_id);
        }
    }

    public function index(Request $request)
    {
        $query = Producto::query()
            ->with('bodega:id,nombre,descripcion')
            ->orderBy('id_producto');

        $this->aplicarFiltroBodega($query, $request);

        if ($request->filled('search')) {
            $search = $request->query('search');
            $query->where(function ($builder) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';
                $builder->whereRaw('LOWER(nombre) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(codigo_barras) LIKE ?', [$term]);
            });
        }

        if ($request->boolean('paginate')) {
            $perPage = min(max($request->integer('per_page', 25), 1), 50);

            return response()->json($query->paginate($perPage));
        }

        return response()->json($query->get());
    }

    public function buscarPorCodigo(Request $request, $codigo)
    {
        $producto = Producto::query()
            ->where('codigo_barras', $codigo)
            ->first();

        if (! $producto) {
            return response()->json([
                'mensaje' => 'Producto no encontrado.',
            ], 404);
        }

        // Un operador solo puede operar productos de su bodega.
        $usuario = $request->user();

        if (
            $usuario
            && $usuario->rol === self::ROL_OPERADOR
            && $usuario->bodega_id
            && $producto->bodega_id !== null
            && (int) $producto->bodega_id !== (int) $usuario->bodega_id
        ) {
            return response()->json([
                'mensaje' => 'El producto no pertenece a tu bodega asignada.',
            ], 403);
        }

        $stockDisponible =
            $producto->stock_fisico -
            $producto->stock_reservado;

        // La app móvil necesita `id_producto` para registrar recepción/picking
        // y `stock_reservado` para mostrar el desglose completo del stock.
        return response()->json([
            'id_producto' => $producto->id_producto,
            'codigo_barras' => $producto->codigo_barras,
            'nombre' => $producto->nombre,
            'precio' => (float) $producto->precio,
            'unidad_medida' => $producto->unidad_medida,
            'stock_fisico' => (int) $producto->stock_fisico,
            'stock_reservado' => (int) $producto->stock_reservado,
            'stock_disponible' => (int) $stockDisponible,
            'ubicacion' => $producto->ubicacion,
            'bodega' => $producto->bodega ? [
                'id' => $producto->bodega->id,
                'nombre' => $producto->bodega->nombre,
                'descripcion' => $producto->bodega->descripcion,
            ] : null,
            'estado' => $producto->estado,
        ]);
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'codigo_barras' => 'required|string|unique:productos,codigo_barras',

            'nombre' => 'required|string|max:255',

            'unidad_medida' => 'required|string|max:100',

            'precio' => 'required|numeric|min:0',

            'stock_fisico' => 'sometimes|integer|min:0',

            'stock_reservado' => 'sometimes|integer|min:0',

            'estado' => 'required|string|max:50',

            'ubicacion' => 'nullable|string|max:255',

            'bodega_id' => 'nullable|integer|exists:bodegas,id',
        ]);

        $stockFisico = (int) ($datos['stock_fisico'] ?? 0);
        $stockReservado = (int) ($datos['stock_reservado'] ?? 0);
        $datos['stock_fisico'] = $stockFisico;
        $datos['stock_reservado'] = $stockReservado;
        $datos['stock_disponible'] = $stockFisico - $stockReservado;

        if ($datos['stock_disponible'] < 0) {
            return response()->json([
                'mensaje' => 'El stock reservado no puede ser mayor al stock físico.',
            ], 422);
        }

        $producto = Producto::create($datos);

        app(AuditoriaService::class)->registrar(
            'CREAR',
            'PRODUCTOS',
            'Producto creado.',
            ['id_producto' => $producto->id_producto],
            $request
        );

        return response()->json(
            $producto,
            201
        );
    }

    public function show(Producto $producto)
    {
        return response()->json(
            $producto->load(['movimientos', 'bodega:id,nombre,descripcion'])
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

            'nombre' => 'required|string|max:255',

            'unidad_medida' => 'required|string|max:100',

            'precio' => 'required|numeric|min:0',

            'estado' => 'required|string|max:50',

            'ubicacion' => 'nullable|string|max:255',

            'bodega_id' => 'nullable|integer|exists:bodegas,id',
        ]);

        $producto->update($datos);

        app(AuditoriaService::class)->registrar(
            'ACTUALIZAR',
            'PRODUCTOS',
            'Producto actualizado.',
            ['id_producto' => $producto->id_producto],
            $request
        );

        return response()->json($producto);
    }

    public function destroy(Producto $producto)
    {
        if ($producto->stock_reservado > 0) {
            return response()->json([
                'mensaje' => 'No se puede eliminar un producto con stock reservado.',
            ], 409);
        }

        if ($producto->movimientos()->exists() || $producto->detallesPedido()->exists()) {
            return response()->json([
                'mensaje' => 'No se puede eliminar un producto con historial de movimientos o pedidos.',
            ], 409);
        }

        $producto->delete();

        app(AuditoriaService::class)->registrar(
            'ELIMINAR',
            'PRODUCTOS',
            'Producto eliminado.',
            ['id_producto' => $producto->id_producto],
            request()
        );

        return response()->json([
            'mensaje' => 'Producto eliminado correctamente.',
        ]);
    }
}
