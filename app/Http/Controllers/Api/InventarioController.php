<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Bodega;
use App\Models\Movimiento;
use App\Models\Producto;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class InventarioController extends Controller
{
    private const ROL_OPERADOR = 'Operador de Almacén';

    /**
     * Un operador con bodega asignada solo ve productos de su bodega
     * (y los aún sin asignar). El administrador ve todo.
     */
    private function scopeBodega($query)
    {
        $usuario = Auth::user();

        if ($usuario && $usuario->rol === self::ROL_OPERADOR && $usuario->bodega_id) {
            $query->where('bodega_id', $usuario->bodega_id);
        }

        return $query;
    }

    /**
     * Resumen general del inventario para la pantalla general.
     * GET /api/inventario/resumen
     */
    public function resumen(): JsonResponse
    {
        // Productos activos: estado ACTIVO
        $productosActivos = $this->scopeBodega(
            Producto::query()->where('estado', 'ACTIVO')
        )->count();

        // Unidades totales: stock_fisico total (unidades almacenadas físicamente)
        $unidadesTotales = $this->scopeBodega(
            Producto::query()->where('estado', 'ACTIVO')
        )->sum('stock_fisico');

        // Valor aproximado: sumar stock_fisico * precio para productos activos
        // Nota: precio es precio de venta, no necesariamente costo.
        $valorAproximado = $this->scopeBodega(
            Producto::query()->where('estado', 'ACTIVO')
        )->selectRaw('COALESCE(SUM(stock_fisico * precio), 0) as total')
            ->value('total');

        // Alertas de stock: productos activos con stock_disponible <= 5 (umbral hardcodeado como en Dashboard)
        $alertasStock = $this->scopeBodega(
            Producto::query()->where('estado', 'ACTIVO')->whereRaw('stock_disponible <= 5')
        )->count();

        // Última actualización: fecha más reciente de movimientos o productos
        $ultimaMovimiento = Movimiento::max('created_at');
        $ultimaProducto = Producto::max('updated_at');

        $ultimaActualizacion = null;
        if ($ultimaMovimiento && $ultimaProducto) {
            $ultimaActualizacion = $ultimaMovimiento > $ultimaProducto ? $ultimaMovimiento : $ultimaProducto;
        } elseif ($ultimaMovimiento) {
            $ultimaActualizacion = $ultimaMovimiento;
        } elseif ($ultimaProducto) {
            $ultimaActualizacion = $ultimaProducto;
        } else {
            $ultimaActualizacion = now();
        }

        // Convertir a Carbon si es string (SQLite devuelve string)
        if (is_string($ultimaActualizacion)) {
            $ultimaActualizacion = Carbon::parse($ultimaActualizacion);
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'productos_activos' => (int) $productosActivos,
                'unidades_totales' => (int) $unidadesTotales,
                'valor_aproximado' => (float) $valorAproximado,
                'alertas_stock' => (int) $alertasStock,
                'ultima_actualizacion' => $ultimaActualizacion?->format('Y-m-d H:i:s'),
            ],
        ]);
    }

    /**
     * Alertas de stock bajo.
     * GET /api/inventario/alertas
     */
    public function alertas(Request $request): JsonResponse
    {
        $query = Producto::query()
            ->where('estado', 'ACTIVO')
            ->whereRaw('stock_disponible <= 5'); // Umbral hardcodeado como en Dashboard

        $productos = $this->scopeBodega($query)->get();

        $data = $productos->map(function ($producto) {
            return [
                'id_producto' => $producto->id_producto,
                'nombre' => $producto->nombre,
                'codigo_barras' => $producto->codigo_barras,
                'stock_disponible' => (int) $producto->stock_disponible,
                'stock_minimo' => 5, // Umbral hardcodeado (no hay campo stock_minimo en BD)
                'ubicacion' => $producto->ubicacion ?? 'Sin ubicación',
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Stock agrupado por ubicación.
     * GET /api/inventario/por-ubicacion
     */
    public function porUbicacion(): JsonResponse
    {
        $productos = $this->scopeBodega(
            Producto::query()->where('estado', 'ACTIVO')
        )->get();

        // Agrupar por ubicación (incluyendo null/empty como 'Sin ubicación')
        $grupos = $productos->groupBy(function ($producto) {
            return $producto->ubicacion ?? 'Sin ubicación';
        });

        $data = $grupos->map(function ($productosGrupo, $ubicacion) {
            return [
                'ubicacion' => $ubicacion,
                'total_productos' => $productosGrupo->count(),
                'total_unidades' => (int) $productosGrupo->sum('stock_fisico'),
                'productos' => $productosGrupo->map(function ($producto) {
                    return [
                        'id_producto' => $producto->id_producto,
                        'nombre' => $producto->nombre,
                        'codigo_barras' => $producto->codigo_barras,
                        'stock_fisico' => (int) $producto->stock_fisico,
                        'stock_reservado' => (int) $producto->stock_reservado,
                        'stock_disponible' => (int) $producto->stock_disponible,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Movimientos recientes.
     * GET /api/movimientos/recientes?limite=20&mio=1
     * mio=1 devuelve únicamente los movimientos del usuario autenticado.
     */
    public function recientes(Request $request): JsonResponse
    {
        $limite = $request->integer('limite', 20);

        // Validar límite: entre 1 y 100
        if ($limite < 1 || $limite > 100) {
            return response()->json([
                'status' => 'error',
                'message' => 'El límite debe estar entre 1 y 100.',
            ], 422);
        }

        $movimientos = Movimiento::query()
            ->when(
                $request->boolean('mio'),
                fn ($query) => $query->where('usuario_id', Auth::id())
            )
            ->with([
                'producto:id_producto,nombre',
                'pedido:id_pedido',
                'usuario:id,name,username',
            ])
            ->latest('created_at')
            ->limit($limite)
            ->get();

        $data = $movimientos->map(function ($movimiento) {
            return [
                'id' => $movimiento->id_movimiento,
                'tipo' => strtolower($movimiento->tipo),
                'cantidad' => (int) $movimiento->cantidad,
                'producto' => $movimiento->producto ? [
                    'id_producto' => $movimiento->producto->id_producto,
                    'nombre' => $movimiento->producto->nombre,
                ] : null,
                'pedido_id' => $movimiento->id_pedido,
                'usuario' => $movimiento->usuario?->username ?? $movimiento->usuario?->name ?? 'Sistema',
                'fecha' => $movimiento->created_at?->format('Y-m-d H:i:s'),
            ];
        })->values();

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }

    /**
     * Inventario agrupado por bodega (vista de administración).
     * GET /api/inventario/por-bodega
     * Incluye también los productos aún sin bodega asignada.
     */
    public function porBodega(): JsonResponse
    {
        $bodegas = Bodega::query()->orderBy('nombre')->get();

        $productos = Producto::query()
            ->where('estado', 'ACTIVO')
            ->get(['id_producto', 'nombre', 'codigo_barras', 'stock_fisico', 'stock_reservado', 'stock_disponible', 'ubicacion', 'bodega_id']);

        $porBodega = $productos->groupBy(
            fn ($p) => $p->bodega_id ?? 0
        );

        $data = $bodegas->map(function (Bodega $bodega) use ($porBodega): array {
            $grupo = $porBodega->get($bodega->id, collect());

            return [
                'id' => $bodega->id,
                'nombre' => $bodega->nombre,
                'descripcion' => $bodega->descripcion,
                'total_productos' => $grupo->count(),
                'total_unidades' => (int) $grupo->sum('stock_fisico'),
                'alertas' => $grupo->filter(
                    fn ($p) => (int) $p->stock_disponible <= 5
                )->count(),
            ];
        })->values();

        // Productos sin bodega asignada (avisar al admin para reubicarlos)
        $sinAsignar = $porBodega->get(0, collect());

        if ($sinAsignar->isNotEmpty()) {
            $data->push([
                'id' => null,
                'nombre' => 'Sin bodega asignada',
                'descripcion' => 'Productos pendientes de ubicación; asínjalos desde Productos.',
                'total_productos' => $sinAsignar->count(),
                'total_unidades' => (int) $sinAsignar->sum('stock_fisico'),
                'alertas' => $sinAsignar->filter(
                    fn ($p) => (int) $p->stock_disponible <= 5
                )->count(),
            ]);
        }

        return response()->json([
            'status' => 'success',
            'data' => $data,
        ]);
    }
}
