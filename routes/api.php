<?php

use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\MovimientoController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProveedorController;
use Illuminate\Support\Facades\Route;

Route::get('/prueba', function () {
    return response()->json([
        'mensaje' => 'API de StockGo funcionando',
        'estado' => 'ok'
    ]);
});

Route::get(
    '/productos/codigo/{codigo}',
    [ProductoController::class, 'buscarPorCodigo']
);

Route::apiResource(
    'productos',
    ProductoController::class
);

Route::apiResource(
    'clientes',
    ClienteController::class
);

Route::apiResource(
    'proveedores',
    ProveedorController::class
);

Route::get(
    '/movimientos',
    [MovimientoController::class, 'index']
);

Route::get(
    '/movimientos/{movimiento}',
    [MovimientoController::class, 'show']
);

Route::post(
    '/movimientos/entrada',
    [MovimientoController::class, 'entrada']
);

Route::post(
    '/movimientos/salida',
    [MovimientoController::class, 'salida']
);

Route::post(
    '/movimientos/ajuste',
    [MovimientoController::class, 'ajuste']
);

Route::apiResource(
    'pedidos',
    PedidoController::class
);

Route::post(
    '/pedidos/{pedido}/surtir',
    [PedidoController::class, 'surtir']
);

Route::post(
    '/pedidos/{pedido}/entregar',
    [PedidoController::class, 'entregar']
);

Route::post(
    '/pedidos/{pedido}/cancelar',
    [PedidoController::class, 'cancelar']
);