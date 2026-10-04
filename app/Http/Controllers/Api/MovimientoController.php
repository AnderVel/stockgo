<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Models\Producto;
use App\Services\AuditoriaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class MovimientoController extends Controller
{
    public function index()
    {
        return response()->json(
            Movimiento::with([
                'producto',
                'pedido',
                'proveedor'
            ])
            ->orderByDesc('id_movimiento')
            ->get()
        );
    }

    public function show(Movimiento $movimiento)
    {
        return response()->json(
            $movimiento->load([
                'producto',
                'pedido',
                'proveedor'
            ])
        );
    }

    public function entrada(Request $request)
    {
        $datos = $request->validate([
            'id_producto' => 'required|exists:productos,id_producto',
            'cantidad' => 'required|integer|min:1',
            'id_proveedor' => 'nullable|exists:proveedores,id_proveedor',
            'motivo' => 'nullable|string|max:255',
        ]);

        $movimiento = DB::transaction(function () use ($datos) {
            $producto = Producto::where(
                'id_producto',
                $datos['id_producto']
            )
            ->lockForUpdate()
            ->firstOrFail();

            $stockAnterior = $producto->stock_fisico;

            $producto->stock_fisico += $datos['cantidad'];

            $producto->stock_disponible =
                $producto->stock_fisico -
                $producto->stock_reservado;

            $producto->save();

            return Movimiento::create([
                'id_producto' => $producto->id_producto,
                'tipo' => 'ENTRADA',
                'cantidad' => $datos['cantidad'],
                'motivo' => $datos['motivo'] ?? 'Entrada de mercancía',
                'id_proveedor' => $datos['id_proveedor'] ?? null,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $producto->stock_fisico,
                'usuario_id' => auth()->id(),
            ]);
        });

        app(AuditoriaService::class)->registrar(
            'CREAR',
            'MOVIMIENTOS',
            'Entrada de mercancía registrada.',
            [
                'id_producto' => $datos['id_producto'],
                'cantidad' => $datos['cantidad'],
                'id_proveedor' => $datos['id_proveedor'] ?? null,
            ],
            $request
        );

        return response()->json(
            $movimiento->load('producto', 'proveedor'),
            201
        );
    }

    public function salida(Request $request)
    {
        $datos = $request->validate([
            'id_producto' => 'required|exists:productos,id_producto',
            'cantidad' => 'required|integer|min:1',
            'id_pedido' => 'nullable|exists:pedidos,id_pedido',
            'motivo' => 'nullable|string|max:255',
        ]);

        $movimiento = DB::transaction(function () use ($datos) {
            $producto = Producto::where(
                'id_producto',
                $datos['id_producto']
            )
            ->lockForUpdate()
            ->firstOrFail();

            if (
                $datos['cantidad'] >
                $producto->stock_disponible
            ) {
                return null;
            }

            $stockAnterior = $producto->stock_fisico;

            $producto->stock_fisico -= $datos['cantidad'];

            $producto->stock_disponible =
                $producto->stock_fisico -
                $producto->stock_reservado;

            $producto->save();

            return Movimiento::create([
                'id_producto' => $producto->id_producto,
                'tipo' => 'SALIDA',
                'cantidad' => $datos['cantidad'],
                'motivo' => $datos['motivo'] ?? 'Salida de mercancía',
                'id_pedido' => $datos['id_pedido'] ?? null,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $producto->stock_fisico,
                'usuario_id' => auth()->id(),
            ]);
        });

        if (!$movimiento) {
            return response()->json([
                'status' => 'error',
                'message' => 'Stock disponible insuficiente.'
            ], 422);
        }

        app(AuditoriaService::class)->registrar(
            'CREAR',
            'MOVIMIENTOS',
            'Salida de mercancía registrada.',
            [
                'id_producto' => $datos['id_producto'],
                'cantidad' => $datos['cantidad'],
                'id_pedido' => $datos['id_pedido'] ?? null,
            ],
            $request
        );

        return response()->json(
            $movimiento->load('producto', 'pedido'),
            201
        );
    }

    public function ajuste(Request $request)
    {
        $datos = $request->validate([
            'id_producto' => 'required|exists:productos,id_producto',
            'cantidad' => 'required|integer|not_in:0',
            'motivo' => 'required|string|max:255',
        ]);

        $movimiento = DB::transaction(function () use ($datos) {
            $producto = Producto::where(
                'id_producto',
                $datos['id_producto']
            )
            ->lockForUpdate()
            ->firstOrFail();

            $nuevoStockFisico =
                $producto->stock_fisico +
                $datos['cantidad'];

            if (
                $nuevoStockFisico <
                $producto->stock_reservado
            ) {
                return null;
            }

            $stockAnterior = $producto->stock_fisico;

            $producto->stock_fisico = $nuevoStockFisico;

            $producto->stock_disponible =
                $producto->stock_fisico -
                $producto->stock_reservado;

            $producto->save();

            return Movimiento::create([
                'id_producto' => $producto->id_producto,
                'tipo' => 'AJUSTE',
                'cantidad' => $datos['cantidad'],
                'motivo' => $datos['motivo'],
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $producto->stock_fisico,
                'usuario_id' => auth()->id(),
            ]);
        });

        if (!$movimiento) {
            return response()->json([
                'status' => 'error',
                'message' =>
                    'El ajuste dejaría el stock físico por debajo del stock reservado.'
            ], 422);
        }

        app(AuditoriaService::class)->registrar(
            'CREAR',
            'MOVIMIENTOS',
            'Ajuste de inventario registrado.',
            [
                'id_producto' => $datos['id_producto'],
                'cantidad' => $datos['cantidad'],
                'motivo' => $datos['motivo'],
            ],
            $request
        );

        return response()->json(
            $movimiento->load('producto'),
            201
        );
    }

    public function movimiento(Request $request)
    {
        $datos = $request->validate([
            'codigo_barras' => 'required|string',
            'tipo_movimiento' => 'required|in:recepcion,picking',
            'cantidad' => 'required|integer|min:1',
            // Se acepta por compatibilidad con Android pero ya NO se confía en él:
            // la identidad del operador siempre es la del token autenticado.
            'usuario_id' => 'nullable|integer|min:1',
        ]);

        $resultado = $this->ejecutarMovimiento(
            $datos['codigo_barras'],
            $datos['tipo_movimiento'],
            $datos['cantidad'],
            null
        );

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['mensaje']
            ], 422);
        }

        app(AuditoriaService::class)->registrar(
            'CREAR',
            'INVENTARIO',
            'Movimiento registrado desde dispositivo.',
            [
                'codigo_barras' => $datos['codigo_barras'],
                'tipo_movimiento' => $datos['tipo_movimiento'],
                'cantidad' => $datos['cantidad'],
            ],
            $request
        );

        return response()->json([
            'status' => 'success',
            'message' => 'Movimiento registrado'
        ], 200);
    }

    /**
     * Endpoint compatible con el contrato solicitado por Android:
     * POST /api/productos/{id}/movimiento
     * { "tipo": "entrada"|"salida", "cantidad": int, "pedido_id": int|null }
     */
    public function movimientoProducto(Request $request, Producto $producto)
    {
        $datos = $request->validate([
            'tipo' => 'required|in:entrada,salida',
            'cantidad' => 'required|integer|min:1',
            'pedido_id' => 'nullable|integer|exists:pedidos,id_pedido',
        ]);

        $tipoMovimiento = $datos['tipo'] === 'entrada' ? 'recepcion' : 'picking';

        $resultado = $this->ejecutarMovimiento(
            $producto->codigo_barras,
            $tipoMovimiento,
            $datos['cantidad'],
            $datos['pedido_id'] ?? null
        );

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['mensaje']
            ], 422);
        }

        app(AuditoriaService::class)->registrar(
            'CREAR',
            'INVENTARIO',
            'Movimiento registrado desde Android.',
            [
                'id_producto' => $producto->id_producto,
                'tipo' => $datos['tipo'],
                'cantidad' => $datos['cantidad'],
                'pedido_id' => $datos['pedido_id'] ?? null,
            ],
            $request
        );

        $producto->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Movimiento registrado',
            'producto' => [
                'id_producto' => $producto->id_producto,
                'stock_fisico' => $producto->stock_fisico,
                'stock_reservado' => $producto->stock_reservado,
                'stock_disponible' => $producto->stock_disponible,
            ],
        ], 200);
    }

    private function ejecutarMovimiento(string $codigoBarras, string $tipoMovimiento, int $cantidad, ?int $idPedido)
    {
        return DB::transaction(function () use ($codigoBarras, $tipoMovimiento, $cantidad, $idPedido) {
            $producto = Producto::where(
                'codigo_barras',
                $codigoBarras
            )
            ->lockForUpdate()
            ->first();

            if (!$producto) {
                return [
                    'error' => true,
                    'mensaje' => 'Código de producto no registrado.'
                ];
            }

            $stockDisponible =
                $producto->stock_fisico -
                $producto->stock_reservado;

            if ($tipoMovimiento === 'recepcion') {
                $stockAnterior = $producto->stock_fisico;

                $producto->stock_fisico += $cantidad;

                $producto->stock_disponible =
                    $producto->stock_fisico -
                    $producto->stock_reservado;

                $producto->save();

                Movimiento::create([
                    'id_producto' => $producto->id_producto,
                    'tipo' => 'ENTRADA',
                    'cantidad' => $cantidad,
                    'motivo' => 'Recepción de mercancía',
                    'id_pedido' => $idPedido,
                    'stock_anterior' => $stockAnterior,
                    'stock_nuevo' => $producto->stock_fisico,
                    'usuario_id' => auth()->id(),
                ]);

                return ['error' => false];
            }

            if ($cantidad > $stockDisponible) {
                return [
                    'error' => true,
                    'mensaje' =>
                        'La cantidad solicitada excede el stock disponible.'
                ];
            }

            $stockAnterior = $producto->stock_fisico;

            $producto->stock_fisico -= $cantidad;

            $producto->stock_reservado = max(0, $producto->stock_reservado - $cantidad);

            $producto->stock_disponible =
                $producto->stock_fisico -
                $producto->stock_reservado;

            $producto->save();

            Movimiento::create([
                'id_producto' => $producto->id_producto,
                'tipo' => 'SALIDA',
                'cantidad' => $cantidad,
                'motivo' => 'Picking desde Android',
                'id_pedido' => $idPedido,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $producto->stock_fisico,
                'usuario_id' => auth()->id(),
            ]);

            return ['error' => false];
        });
    }
}