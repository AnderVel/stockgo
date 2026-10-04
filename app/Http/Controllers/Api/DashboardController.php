<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Models\Pedido;
use App\Models\Producto;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $productoStats = Producto::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('COALESCE(SUM(stock_disponible), 0) as stock_disponible')
            ->selectRaw("SUM(CASE WHEN estado = 'ACTIVO' AND (stock_fisico - stock_reservado) <= 5 THEN 1 ELSE 0 END) as stock_bajo")
            ->first();

        $pedidosPorEstado = Pedido::query()
            ->select('estado', DB::raw('COUNT(*) as total'))
            ->groupBy('estado')
            ->pluck('total', 'estado');

        $movimientosPorTipo = Movimiento::query()
            ->select('tipo', DB::raw('COALESCE(SUM(cantidad), 0) as total'))
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $movimientos = Movimiento::query()
            ->select([
                'id_movimiento',
                'id_producto',
                'tipo',
                'cantidad',
                'motivo',
                'stock_anterior',
                'stock_nuevo',
                'created_at',
            ])
            ->with('producto:id_producto,nombre,codigo_barras')
            ->latest('id_movimiento')
            ->limit(10)
            ->get();

        return response()->json([
            'kpis' => [
                'productos' => (int) $productoStats->total,
                'stock_disponible' => (int) $productoStats->stock_disponible,
                'stock_bajo' => (int) $productoStats->stock_bajo,
                'pedidos' => (int) $pedidosPorEstado->sum(),
                'clientes' => DB::table('clientes')->count(),
                'entradas' => (int) ($movimientosPorTipo['ENTRADA'] ?? 0),
                'salidas' => (int) ($movimientosPorTipo['SALIDA'] ?? 0),
            ],
            'pedidos_por_estado' => $pedidosPorEstado,
            'movimientos_recientes' => $movimientos,
        ]);
    }
}
