<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Models\Pedido;
use App\Models\Producto;
use App\Services\AuditoriaService;
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
            'id_cliente' => [
                'required',
                'integer',
                'exists:clientes,id_cliente',
            ],

            'fecha_pedido' => [
                'required',
                'date',
            ],

            'observaciones' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'detalles' => [
                'required',
                'array',
                'min:1',
            ],

            'detalles.*.id_producto' => [
                'required',
                'integer',
                'distinct',
                'exists:productos,id_producto',
            ],

            'detalles.*.cantidad' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $resultado = DB::transaction(function () use ($datos) {

            $detallesEntrada = collect($datos['detalles'])
                ->sortBy('id_producto')
                ->values();

            $productos = [];

            foreach ($detallesEntrada as $detalle) {
                $producto = Producto::where(
                    'id_producto',
                    $detalle['id_producto']
                )
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($producto->estado !== 'ACTIVO') {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' =>
                            'El producto "' .
                            $producto->nombre .
                            '" no está disponible.',
                    ];
                }

                if (
                    $detalle['cantidad'] >
                    $producto->stock_disponible
                ) {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' =>
                            'Stock disponible insuficiente para "' .
                            $producto->nombre .
                            '". Disponible: ' .
                            $producto->stock_disponible .
                            '.',
                    ];
                }

                $productos[$producto->id_producto] = $producto;
            }

            $pedido = Pedido::create([
                'id_cliente' => $datos['id_cliente'],
                'fecha_pedido' => $datos['fecha_pedido'],
                'estado' => 'PENDIENTE',
                'total' => 0,
                'observaciones' =>
                    $datos['observaciones'] ?? null,
            ]);

            $total = 0;

            foreach ($detallesEntrada as $detalle) {
                $producto =
                    $productos[$detalle['id_producto']];

                $precioUnitario =
                    (float) $producto->precio;

                $subtotal =
                    round(
                        $detalle['cantidad'] *
                        $precioUnitario,
                        2
                    );

                $pedido->detalles()->create([
                    'id_producto' =>
                        $producto->id_producto,

                    'cantidad' =>
                        $detalle['cantidad'],

                    'precio_unitario' =>
                        $precioUnitario,

                    'subtotal' =>
                        $subtotal,
                ]);

                $producto->stock_reservado +=
                    $detalle['cantidad'];

                $producto->stock_disponible =
                    $producto->stock_fisico -
                    $producto->stock_reservado;

                $producto->save();

                $total += $subtotal;
            }

            $pedido->update([
                'total' => $total,
            ]);

            return [
                'error' => false,
                'pedido' => $pedido->load([
                    'cliente',
                    'detalles.producto',
                ]),
            ];
        });

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['message'],
            ], $resultado['status']);
        }

        app(AuditoriaService::class)->registrar(
            'CREAR',
            'PEDIDOS',
            'Pedido creado.',
            ['id_pedido' => $resultado['pedido']->id_pedido],
            $request
        );

        return response()->json(
            $resultado['pedido'],
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

    public function update(
        Request $request,
        Pedido $pedido
    ) {
        $datos = $request->validate([
            'id_cliente' => [
                'required',
                'integer',
                'exists:clientes,id_cliente',
            ],

            'fecha_pedido' => [
                'required',
                'date',
            ],

            'observaciones' => [
                'nullable',
                'string',
                'max:5000',
            ],

            'detalles' => [
                'required',
                'array',
                'min:1',
            ],

            'detalles.*.id_producto' => [
                'required',
                'integer',
                'distinct',
                'exists:productos,id_producto',
            ],

            'detalles.*.cantidad' => [
                'required',
                'integer',
                'min:1',
            ],
        ]);

        $resultado = DB::transaction(function () use (
            $datos,
            $pedido
        ) {
            $pedido = Pedido::where(
                'id_pedido',
                $pedido->id_pedido
            )
                ->lockForUpdate()
                ->firstOrFail();

            if ($pedido->estado !== 'PENDIENTE') {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' =>
                        'Solo se pueden modificar pedidos pendientes.',
                ];
            }

            $detallesActuales = $pedido->detalles()
                ->lockForUpdate()
                ->get();

            $actuales = $detallesActuales->keyBy(
                'id_producto'
            );

            $nuevos = collect($datos['detalles'])
                ->sortBy('id_producto')
                ->values()
                ->keyBy('id_producto');

            $idsProductos = collect(
                $actuales->keys()
            )
                ->merge($nuevos->keys())
                ->unique()
                ->sort()
                ->values();

            $productos = [];

            foreach ($idsProductos as $idProducto) {
                $productos[$idProducto] =
                    Producto::where(
                        'id_producto',
                        $idProducto
                    )
                        ->lockForUpdate()
                        ->firstOrFail();
            }

            foreach ($idsProductos as $idProducto) {
                $cantidadActual =
                    isset($actuales[$idProducto])
                        ? (int) $actuales[$idProducto]->cantidad
                        : 0;

                $cantidadNueva =
                    isset($nuevos[$idProducto])
                        ? (int) $nuevos[$idProducto]['cantidad']
                        : 0;

                $diferencia =
                    $cantidadNueva -
                    $cantidadActual;

                $producto =
                    $productos[$idProducto];

                if (
                    $cantidadNueva > 0 &&
                    $producto->estado !== 'ACTIVO'
                ) {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' =>
                            'El producto "' .
                            $producto->nombre .
                            '" no está disponible.',
                    ];
                }

                if (
                    $diferencia > 0 &&
                    $diferencia >
                    $producto->stock_disponible
                ) {
                    return [
                        'error' => true,
                        'status' => 422,
                        'message' =>
                            'No hay stock suficiente para aumentar "' .
                            $producto->nombre .
                            '". Disponible: ' .
                            $producto->stock_disponible .
                            '.',
                    ];
                }

                if (
                    $diferencia < 0 &&
                    abs($diferencia) >
                    $producto->stock_reservado
                ) {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' =>
                            'La reserva de "' .
                            $producto->nombre .
                            '" presenta una inconsistencia.',
                    ];
                }
            }

            foreach ($idsProductos as $idProducto) {
                $cantidadActual =
                    isset($actuales[$idProducto])
                        ? (int) $actuales[$idProducto]->cantidad
                        : 0;

                $cantidadNueva =
                    isset($nuevos[$idProducto])
                        ? (int) $nuevos[$idProducto]['cantidad']
                        : 0;

                $diferencia =
                    $cantidadNueva -
                    $cantidadActual;

                $producto =
                    $productos[$idProducto];

                $producto->stock_reservado +=
                    $diferencia;

                $producto->stock_disponible =
                    $producto->stock_fisico -
                    $producto->stock_reservado;

                $producto->save();
            }

            $pedido->detalles()->delete();

            $total = 0;

            foreach ($nuevos as $detalle) {
                $producto =
                    $productos[$detalle['id_producto']];

                $precioUnitario =
                    (float) $producto->precio;

                $subtotal =
                    round(
                        $detalle['cantidad'] *
                        $precioUnitario,
                        2
                    );

                $pedido->detalles()->create([
                    'id_producto' =>
                        $producto->id_producto,

                    'cantidad' =>
                        $detalle['cantidad'],

                    'precio_unitario' =>
                        $precioUnitario,

                    'subtotal' =>
                        $subtotal,
                ]);

                $total += $subtotal;
            }

            $pedido->update([
                'id_cliente' => $datos['id_cliente'],
                'fecha_pedido' => $datos['fecha_pedido'],
                'total' => $total,
                'observaciones' =>
                    $datos['observaciones'] ?? null,
            ]);

            return [
                'error' => false,
                'pedido' => $pedido->load([
                    'cliente',
                    'detalles.producto',
                ]),
            ];
        });

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['message'],
            ], $resultado['status']);
        }

        app(AuditoriaService::class)->registrar(
            'ACTUALIZAR',
            'PEDIDOS',
            'Pedido actualizado.',
            ['id_pedido' => $resultado['pedido']->id_pedido],
            $request
        );

        return response()->json(
            $resultado['pedido']
        );
    }

    public function surtir(Pedido $pedido)
    {
        $resultado = DB::transaction(function () use ($pedido) {

            $pedido = Pedido::where(
                'id_pedido',
                $pedido->id_pedido
            )
                ->lockForUpdate()
                ->firstOrFail();

            if ($pedido->estado !== 'PENDIENTE') {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' =>
                        'El pedido no está pendiente de surtido.',
                ];
            }

            $detalles = $pedido->detalles()
                ->lockForUpdate()
                ->get();

            $idsProductos = $detalles
                ->pluck('id_producto')
                ->sort()
                ->values();

            $productos = [];

            foreach ($idsProductos as $idProducto) {
                $productos[$idProducto] =
                    Producto::where(
                        'id_producto',
                        $idProducto
                    )
                        ->lockForUpdate()
                        ->firstOrFail();
            }

            foreach ($detalles as $detalle) {
                $producto =
                    $productos[$detalle->id_producto];

                if (
                    $producto->stock_fisico <
                    $detalle->cantidad
                ) {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' =>
                            'El stock físico ya no permite surtir el producto "' .
                            $producto->nombre .
                            '".',
                    ];
                }

                if (
                    $producto->stock_reservado <
                    $detalle->cantidad
                ) {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' =>
                            'La reserva del producto "' .
                            $producto->nombre .
                            '" presenta una inconsistencia.',
                    ];
                }
            }

            foreach ($detalles as $detalle) {
                $producto =
                    $productos[$detalle->id_producto];

                $stockAnterior =
                    $producto->stock_fisico;

                $producto->stock_fisico -=
                    $detalle->cantidad;

                $producto->stock_reservado -=
                    $detalle->cantidad;

                $producto->stock_disponible =
                    $producto->stock_fisico -
                    $producto->stock_reservado;

                $producto->save();

                Movimiento::create([
                    'id_producto' =>
                        $producto->id_producto,

                    'tipo' => 'SALIDA',

                    'cantidad' =>
                        $detalle->cantidad,

                    'motivo' =>
                        'Salida por surtido del pedido #' .
                        $pedido->id_pedido,

                    'id_pedido' =>
                        $pedido->id_pedido,

                    'stock_anterior' =>
                        $stockAnterior,

                    'stock_nuevo' =>
                        $producto->stock_fisico,
                    'usuario_id' => auth()->id(),
                ]);
            }

            $pedido->update([
                'estado' => 'SURTIDO',
            ]);

            return [
                'error' => false,
                'pedido' => $pedido->load([
                    'cliente',
                    'detalles.producto',
                    'movimientos',
                ]),
            ];
        });

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['message'],
            ], $resultado['status']);
        }

        app(AuditoriaService::class)->registrar(
            'SURTIR',
            'PEDIDOS',
            'Pedido surtido.',
            ['id_pedido' => $pedido->id_pedido],
            request()
        );

        return response()->json([
            'status' => 'success',
            'message' =>
                'Pedido surtido correctamente. El inventario fue actualizado.',
            'pedido' =>
                $resultado['pedido'],
        ]);
    }

    public function entregar(Pedido $pedido)
    {
        if ($pedido->estado !== 'SURTIDO') {
            return response()->json([
                'status' => 'error',
                'message' =>
                    'El pedido debe estar surtido antes de marcarlo como enviado.',
            ], 409);
        }

        $pedido->update([
            'estado' => 'ENVIADO',
        ]);

        app(AuditoriaService::class)->registrar(
            'ENTREGAR',
            'PEDIDOS',
            'Pedido marcado como enviado.',
            ['id_pedido' => $pedido->id_pedido],
            request()
        );

        return response()->json([
            'status' => 'success',
            'message' =>
                'Pedido marcado como enviado.',
            'pedido' =>
                $pedido->load([
                    'cliente',
                    'detalles.producto',
                ]),
        ]);
    }

    public function recibir(Pedido $pedido)
    {
        if ($pedido->estado !== 'ENVIADO') {
            return response()->json([
                'status' => 'error',
                'message' =>
                    'El pedido debe estar enviado antes de marcarlo como entregado.',
            ], 409);
        }

        $pedido->update([
            'estado' => 'ENTREGADO',
        ]);

        app(AuditoriaService::class)->registrar(
            'RECIBIR',
            'PEDIDOS',
            'Pedido entregado.',
            ['id_pedido' => $pedido->id_pedido],
            request()
        );

        return response()->json([
            'status' => 'success',
            'message' =>
                'Pedido entregado correctamente.',
            'pedido' =>
                $pedido->load([
                    'cliente',
                    'detalles.producto',
                ]),
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
                ->firstOrFail();

            if ($pedido->estado !== 'PENDIENTE') {
                return [
                    'error' => true,
                    'status' => 409,
                    'message' =>
                        'Solo se pueden cancelar pedidos pendientes.',
                ];
            }

            $detalles = $pedido->detalles()
                ->lockForUpdate()
                ->get();

            $idsProductos = $detalles
                ->pluck('id_producto')
                ->sort()
                ->values();

            $productos = [];

            foreach ($idsProductos as $idProducto) {
                $productos[$idProducto] =
                    Producto::where(
                        'id_producto',
                        $idProducto
                    )
                        ->lockForUpdate()
                        ->firstOrFail();
            }

            foreach ($detalles as $detalle) {
                $producto =
                    $productos[$detalle->id_producto];

                if (
                    $producto->stock_reservado <
                    $detalle->cantidad
                ) {
                    return [
                        'error' => true,
                        'status' => 409,
                        'message' =>
                            'La reserva del producto "' .
                            $producto->nombre .
                            '" presenta una inconsistencia.',
                    ];
                }

                $producto->stock_reservado -=
                    $detalle->cantidad;

                $producto->stock_disponible =
                    $producto->stock_fisico -
                    $producto->stock_reservado;

                $producto->save();
            }

            $pedido->update([
                'estado' => 'CANCELADO',
            ]);

            return [
                'error' => false,
                'pedido' => $pedido->load([
                    'cliente',
                    'detalles.producto',
                ]),
            ];
        });

        if ($resultado['error']) {
            return response()->json([
                'status' => 'error',
                'message' => $resultado['message'],
            ], $resultado['status']);
        }

        app(AuditoriaService::class)->registrar(
            'CANCELAR',
            'PEDIDOS',
            'Pedido cancelado.',
            ['id_pedido' => $pedido->id_pedido],
            request()
        );

        return response()->json([
            'status' => 'success',
            'message' =>
                'Pedido cancelado y reserva liberada correctamente.',
            'pedido' =>
                $resultado['pedido'],
        ]);
    }

    public function destroy(Pedido $pedido)
    {
        if ($pedido->estado !== 'CANCELADO') {
            return response()->json([
                'status' => 'error',
                'message' =>
                    'Primero debes cancelar el pedido.',
            ], 409);
        }

        $pedido->delete();

        app(AuditoriaService::class)->registrar(
            'ELIMINAR',
            'PEDIDOS',
            'Pedido eliminado.',
            ['id_pedido' => $pedido->id_pedido],
            request()
        );

        return response()->json([
            'status' => 'success',
            'message' =>
                'Pedido eliminado correctamente.',
        ]);
    }
}