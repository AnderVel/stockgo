<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
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
                'proveedor',
                'detalles.producto',
            ])
                ->orderByDesc('id_pedido')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $datos = $request->validate([
            'id_proveedor' =>
                'required|exists:proveedores,id_proveedor',

            'fecha_pedido' =>
                'required|date',

            'observaciones' =>
                'nullable|string',

            'detalles' =>
                'required|array|min:1',

            'detalles.*.id_producto' =>
                'required|exists:productos,id_producto',

            'detalles.*.cantidad' =>
                'required|integer|min:1',

            'detalles.*.precio_unitario' =>
                'required|numeric|min:0',
        ]);

        $pedido = DB::transaction(function () use ($datos) {
            $pedido = Pedido::create([
                'id_proveedor' => $datos['id_proveedor'],
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
                )->firstOrFail();

                $subtotal =
                    $detalle['cantidad'] *
                    $detalle['precio_unitario'];

                $pedido->detalles()->create([
                    'id_producto' =>
                        $producto->id_producto,

                    'cantidad' =>
                        $detalle['cantidad'],

                    'precio_unitario' =>
                        $detalle['precio_unitario'],

                    'subtotal' =>
                        $subtotal,
                ]);

                $total += $subtotal;
            }

            $pedido->update([
                'total' => $total,
            ]);

            return $pedido;
        });

        return response()->json(
            $pedido->load([
                'proveedor',
                'detalles.producto',
            ]),
            201
        );
    }

    public function show(Pedido $pedido)
    {
        return response()->json(
            $pedido->load([
                'proveedor',
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
                ['ENVIADO', 'RECIBIDO', 'CANCELADO']
            )
        ) {
            return response()->json([
                'mensaje' =>
                    'Este pedido ya no puede modificarse.'
            ], 409);
        }

        $datos = $request->validate([
            'id_proveedor' =>
                'required|exists:proveedores,id_proveedor',

            'fecha_pedido' =>
                'required|date',

            'observaciones' =>
                'nullable|string',
        ]);

        $pedido->update($datos);

        return response()->json(
            $pedido->load([
                'proveedor',
                'detalles.producto',
            ])
        );
    }

    public function surtir(Pedido $pedido)
    {
        if ($pedido->estado !== 'PENDIENTE') {
            return response()->json([
                'mensaje' =>
                    'El pedido no se puede poner en proceso en su estado actual.'
            ], 409);
        }

        $pedido->update([
            'estado' => 'EN_PROCESO',
        ]);

        return response()->json(
            $pedido->load([
                'proveedor',
                'detalles.producto',
            ])
        );
    }

    public function entregar(Pedido $pedido)
    {
        if ($pedido->estado !== 'EN_PROCESO') {
            return response()->json([
                'mensaje' =>
                    'El pedido debe estar en proceso antes de marcarlo como enviado.'
            ], 409);
        }

        $pedido->update([
            'estado' => 'ENVIADO',
        ]);

        return response()->json(
            $pedido->load([
                'proveedor',
                'detalles.producto',
            ])
        );
    }

    public function recibir(Pedido $pedido)
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
                    ['ENVIADO']
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

                $stockAnterior = $producto->stock_fisico;

                $producto->stock_fisico +=
                    $detalle->cantidad;

                $producto->stock_disponible =
                    $producto->stock_fisico -
                    $producto->stock_reservado;

                $producto->save();

                Movimiento::create([
                    'id_producto' =>
                        $producto->id_producto,

                    'tipo' =>
                        'ENTRADA',

                    'cantidad' =>
                        $detalle->cantidad,

                    'motivo' =>
                        'Recepción del pedido #' .
                        $pedido->id_pedido,

                    'id_pedido' =>
                        $pedido->id_pedido,

                    'id_proveedor' =>
                        $pedido->id_proveedor,

                    'stock_anterior' =>
                        $stockAnterior,

                    'stock_nuevo' =>
                        $producto->stock_fisico,
                ]);
            }

            $pedido->update([
                'estado' => 'RECIBIDO',
            ]);

            return $pedido->load([
                'proveedor',
                'detalles.producto',
                'movimientos',
            ]);
        });

        if (!$resultado) {
            return response()->json([
                'mensaje' =>
                    'El pedido no puede recibirse en su estado actual.'
            ], 409);
        }

        return response()->json([
            'mensaje' =>
                'Pedido recibido correctamente. El inventario fue actualizado.',
            'pedido' =>
                $resultado,
        ]);
    }

    public function cancelar(Pedido $pedido)
    {
        if (
            in_array(
                $pedido->estado,
                ['RECIBIDO', 'CANCELADO']
            )
        ) {
            return response()->json([
                'mensaje' =>
                    'El pedido no puede cancelarse en su estado actual.'
            ], 409);
        }

        $pedido->update([
            'estado' => 'CANCELADO',
        ]);

        return response()->json([
            'mensaje' =>
                'Pedido cancelado correctamente.',
            'pedido' =>
                $pedido->load([
                    'proveedor',
                    'detalles.producto',
                ]),
        ]);
    }

    public function destroy(Pedido $pedido)
    {
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