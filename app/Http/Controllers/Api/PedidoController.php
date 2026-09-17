<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pedido;
use App\Models\Producto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PedidoController extends Controller
{
    public function index()
    {
        return response()->json(
            Pedido::with([
                'cliente',
                'detalles.producto',
            ])
            ->orderByDesc('id_pedido')
            ->get()
        );
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'id_cliente' => 'required|exists:clientes,id_cliente',
            'fecha_pedido' => 'required|date',
            'observaciones' => 'nullable|string',
            'detalles' => 'required|array|min:1',

            'detalles.*.id_producto' =>
                'required|exists:productos,id_producto',

            'detalles.*.cantidad' =>
                'required|integer|min:1',

            'detalles.*.precio_unitario' =>
                'required|numeric|min:0',
        ]);

        $pedido = DB::transaction(function () use ($datos) {
            $pedido = Pedido::create([
                'id_cliente' => $datos['id_cliente'],
                'fecha_pedido' => $datos['fecha_pedido'],
                'estado' => 'PENDIENTE',
                'total' => 0,
                'observaciones' => $datos['observaciones'] ?? null,
            ]);

            $total = 0;

            foreach ($datos['detalles'] as $detalle) {
                $producto = Producto::where(
                    'id_producto',
                    $detalle['id_producto']
                )
                ->lockForUpdate()
                ->firstOrFail();

                if (
                    $detalle['cantidad'] >
                    $producto->stock_disponible
                ) {
                    throw new \RuntimeException(
                        'Stock insuficiente para: ' .
                        $producto->nombre
                    );
                }

                $subtotal =
                    $detalle['cantidad'] *
                    $detalle['precio_unitario'];

                $pedido->detalles()->create([
                    'id_producto' => $producto->id_producto,
                    'cantidad' => $detalle['cantidad'],
                    'precio_unitario' => $detalle['precio_unitario'],
                    'subtotal' => $subtotal,
                ]);

                $producto->stock_reservado +=
                    $detalle['cantidad'];

                $producto->stock_disponible -=
                    $detalle['cantidad'];

                $producto->save();

                $total += $subtotal;
            }

            $pedido->update([
                'total' => $total,
            ]);

            return $pedido;
        });

        return response()->json(
            $pedido->load([
                'cliente',
                'detalles.producto',
            ]),
            201
        );
    }

    public function show(Pedido $pedido)
    {
        return response()->json(
            $pedido->load([
                'cliente',
                'detalles.producto',
                'movimientos',
            ])
        );
    }

    public function update(Request $request, Pedido $pedido)
    {
        if (
            in_array(
                $pedido->estado,
                ['ENTREGADO', 'CANCELADO']
            )
        ) {
            return response()->json([
                'mensaje' => 'Este pedido ya no puede modificarse.'
            ], 409);
        }

        $datos = $request->validate([
            'id_cliente' =>
                'required|exists:clientes,id_cliente',

            'fecha_pedido' =>
                'required|date',

            'observaciones' =>
                'nullable|string',
        ]);

        $pedido->update($datos);

        return response()->json(
            $pedido->load([
                'cliente',
                'detalles.producto',
            ])
        );
    }

    public function surtir(Pedido $pedido)
    {
        if (
            !in_array(
                $pedido->estado,
                ['PENDIENTE']
            )
        ) {
            return response()->json([
                'mensaje' => 'El pedido no se puede marcar como surtido en su estado actual.'
            ], 409);
        }

        $pedido->update([
            'estado' => 'SURTIDO'
        ]);

        return response()->json(
            $pedido->load([
                'cliente',
                'detalles.producto',
            ])
        );
    }

    public function entregar(Pedido $pedido)
    {
        $resultado = DB::transaction(function () use ($pedido) {
            $pedido = Pedido::where(
                'id_pedido',
                $pedido->id_pedido
            )
            ->lockForUpdate()
            ->with('detalles')
            ->firstOrFail();

            if (
                !in_array(
                    $pedido->estado,
                    ['PENDIENTE', 'SURTIDO']
                )
            ) {
                return null;
            }

            foreach ($pedido->detalles as $detalle) {
                $producto = Producto::where(
                    'id_producto',
                    $detalle->id_producto
                )
                ->lockForUpdate()
                ->firstOrFail();

                if (
                    $producto->stock_reservado <
                    $detalle->cantidad
                ) {
                    return null;
                }

                if (
                    $producto->stock_fisico <
                    $detalle->cantidad
                ) {
                    return null;
                }

                $stockAnterior = $producto->stock_fisico;

                $producto->stock_fisico -=
                    $detalle->cantidad;

                $producto->stock_reservado -=
                    $detalle->cantidad;

                $producto->stock_disponible =
                    $producto->stock_fisico -
                    $producto->stock_reservado;

                $producto->save();

                \App\Models\Movimiento::create([
                    'id_producto' =>
                        $producto->id_producto,

                    'tipo' => 'SALIDA',

                    'cantidad' =>
                        $detalle->cantidad,

                    'motivo' =>
                        'Entrega del pedido #' .
                        $pedido->id_pedido,

                    'id_pedido' =>
                        $pedido->id_pedido,

                    'stock_anterior' =>
                        $stockAnterior,

                    'stock_nuevo' =>
                        $producto->stock_fisico,
                ]);
            }

            $pedido->update([
                'estado' => 'ENTREGADO'
            ]);

            return $pedido->load([
                'cliente',
                'detalles.producto',
                'movimientos',
            ]);
        });

        if (!$resultado) {
            return response()->json([
                'mensaje' =>
                    'No se puede entregar el pedido por falta de stock o por su estado actual.'
            ], 409);
        }

        return response()->json([
            'mensaje' => 'Pedido entregado correctamente.',
            'pedido' => $resultado,
        ]);
    }

    public function cancelar(Pedido $pedido)
    {
        $resultado = DB::transaction(function () use ($pedido) {
            $pedido = Pedido::where(
                'id_pedido',
                $pedido->id_pedido
            )
            ->lockForUpdate()
            ->with('detalles')
            ->firstOrFail();

            if (
                in_array(
                    $pedido->estado,
                    ['ENTREGADO', 'CANCELADO']
                )
            ) {
                return null;
            }

            foreach ($pedido->detalles as $detalle) {
                $producto = Producto::where(
                    'id_producto',
                    $detalle->id_producto
                )
                ->lockForUpdate()
                ->firstOrFail();

                $producto->stock_reservado -=
                    $detalle->cantidad;

                $producto->stock_disponible =
                    $producto->stock_fisico -
                    $producto->stock_reservado;

                $producto->save();
            }

            $pedido->update([
                'estado' => 'CANCELADO'
            ]);

            return $pedido;
        });

        if (!$resultado) {
            return response()->json([
                'mensaje' =>
                    'El pedido no puede cancelarse.'
            ], 409);
        }

        return response()->json([
            'mensaje' =>
                'Pedido cancelado y stock liberado.',
            'pedido' =>
                $resultado->load([
                    'cliente',
                    'detalles.producto',
                ]),
        ]);
    }

    public function destroy(Pedido $pedido)
    {
        if (
            in_array(
                $pedido->estado,
                ['ENTREGADO']
            )
        ) {
            return response()->json([
                'mensaje' =>
                    'Un pedido entregado no puede eliminarse.'
            ], 409);
        }

        if (
            $pedido->estado !== 'CANCELADO'
        ) {
            return response()->json([
                'mensaje' =>
                    'Primero debes cancelar el pedido.'
            ], 409);
        }

        $pedido->delete();

        return response()->json([
            'mensaje' =>
                'Pedido eliminado correctamente.'
        ]);
    }
}