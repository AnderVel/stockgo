<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Movimiento;
use App\Models\Producto;
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
            $producto->stock_disponible += $datos['cantidad'];

            $producto->save();

            return Movimiento::create([
                'id_producto' => $producto->id_producto,
                'tipo' => 'ENTRADA',
                'cantidad' => $datos['cantidad'],
                'motivo' => $datos['motivo'] ?? 'Entrada de mercancía',
                'id_proveedor' => $datos['id_proveedor'] ?? null,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $producto->stock_fisico,
            ]);
        });

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
            $producto->stock_disponible -= $datos['cantidad'];

            $producto->save();

            return Movimiento::create([
                'id_producto' => $producto->id_producto,
                'tipo' => 'SALIDA',
                'cantidad' => $datos['cantidad'],
                'motivo' => $datos['motivo'] ?? 'Salida de mercancía',
                'id_pedido' => $datos['id_pedido'] ?? null,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $producto->stock_fisico,
            ]);
        });

        if (!$movimiento) {
            return response()->json([
                'mensaje' => 'Stock disponible insuficiente.'
            ], 422);
        }

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
            ]);
        });

        if (!$movimiento) {
            return response()->json([
                'mensaje' => 'El ajuste dejaría el stock físico por debajo del stock reservado.'
            ], 422);
        }

        return response()->json(
            $movimiento->load('producto'),
            201
        );
    }
}