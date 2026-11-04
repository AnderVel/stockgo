<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Auditoria;
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
            ->get()
            ->mapWithKeys(fn ($row) => [$row->estado => (int) $row->total]);

        $movimientosPorTipo = Movimiento::query()
            ->select('tipo', DB::raw('COALESCE(SUM(cantidad), 0) as total'))
            ->groupBy('tipo')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->tipo => (int) $row->total]);

        $movimientos = Movimiento::query()
            ->select([
                'id_movimiento',
                'id_producto',
                'tipo',
                'cantidad',
                'motivo',
                'stock_anterior',
                'stock_nuevo',
                'usuario_id',
                'created_at',
            ])
            ->with([
                'producto:id_producto,nombre,codigo_barras',
                'usuario:id,name,username',
            ])
            ->latest('id_movimiento')
            ->limit(10)
            ->get();

        $eventosPedidos = Auditoria::query()
            ->where('modulo', 'PEDIDOS')
            ->whereIn('accion', ['CREAR', 'SURTIR', 'ENTREGAR', 'RECIBIR', 'CANCELAR'])
            ->with('usuario:id,name,username')
            ->latest('id_auditoria')
            ->limit(10)
            ->get();

        $actividadInventario = $movimientos->map(fn (Movimiento $movimiento) => [
            'origen' => 'inventario',
            'id' => 'mov-'.$movimiento->id_movimiento,
            'tipo' => $movimiento->tipo,
            'titulo' => $movimiento->motivo ?: 'Movimiento de inventario',
            'cantidad' => (int) $movimiento->cantidad,
            'producto' => $movimiento->producto?->nombre,
            'id_producto' => $movimiento->id_producto,
            'usuario' => $movimiento->usuario?->username ?? 'Sistema',
            'created_at' => $movimiento->created_at?->toIso8601String(),
        ]);

        $actividadPedidos = $eventosPedidos->map(function (Auditoria $auditoria) {
            $idPedido = $auditoria->detalles['id_pedido'] ?? null;

            $tipo = match ($auditoria->accion) {
                'CREAR' => 'PEDIDO_CREADO',
                'SURTIR' => 'PEDIDO_SURTIDO',
                'ENTREGAR' => 'PEDIDO_ENVIADO',
                'RECIBIR' => 'PEDIDO_ENTREGADO',
                'CANCELAR' => 'PEDIDO_CANCELADO',
                default => 'PEDIDO_ACTUALIZADO',
            };

            $etiqueta = match ($tipo) {
                'PEDIDO_CREADO' => 'Pedido creado',
                'PEDIDO_SURTIDO' => 'Pedido surtido',
                'PEDIDO_ENVIADO' => 'Pedido enviado por correo',
                'PEDIDO_ENTREGADO' => 'Pedido entregado al cliente',
                'PEDIDO_CANCELADO' => 'Pedido cancelado',
                default => 'Pedido actualizado',
            };

            return [
                'origen' => 'pedido',
                'id' => 'aud-'.$auditoria->id_auditoria,
                'tipo' => $tipo,
                'titulo' => ($idPedido !== null ? "Pedido #{$idPedido} · " : '').$etiqueta,
                'cantidad' => null,
                'producto' => null,
                'id_producto' => null,
                'usuario' => $auditoria->usuario?->username ?? 'Sistema',
                'created_at' => $auditoria->created_at?->toIso8601String(),
            ];
        });

        $actividad = $actividadInventario
            ->concat($actividadPedidos)
            ->sortByDesc('created_at')
            ->take(12)
            ->values();

        return response()->json([
            'kpis' => [
                'productos' => (int) $productoStats->total,
                'stock_disponible' => (int) $productoStats->stock_disponible,
                'stock_bajo' => (int) $productoStats->stock_bajo,
                'pedidos' => (int) $pedidosPorEstado->sum(),
                'clientes' => (int) DB::table('clientes')->count(),
                'entradas' => (int) ($movimientosPorTipo['ENTRADA'] ?? 0),
                'salidas' => (int) ($movimientosPorTipo['SALIDA'] ?? 0),
            ],
            // Se castea a objeto: un mapa vacío debe serializarse como {}
            // y no como [], para que el cliente lo lea como diccionario.
            'pedidos_por_estado' => (object) $pedidosPorEstado->toArray(),
            'movimientos_recientes' => $actividad,
        ]);
    }
}
