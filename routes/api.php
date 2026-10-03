<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\MovimientoController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProveedorController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:30,1');

Route::middleware([
    'auth:sanctum',
    'abilities:2fa-setup',
])->group(function () {
    Route::post(
        '/auth/2fa/setup',
        [AuthController::class, 'setupTwoFactor']
    );

    Route::post(
        '/auth/2fa/confirm',
        [AuthController::class, 'confirmTwoFactor']
    );
});

Route::middleware([
    'auth:sanctum',
    'abilities:2fa-verify',
])->group(function () {
    Route::post(
        '/auth/2fa/verify',
        [AuthController::class, 'verifyTwoFactor']
    )->middleware('throttle:30,1');
});

Route::middleware([
    'auth:sanctum',
    'abilities:api-access',
    'throttle:120,1',
])->group(function () {

    Route::get(
        '/auth/me',
        [AuthController::class, 'me']
    );

    Route::post(
        '/auth/logout',
        [AuthController::class, 'logout']
    );

    Route::get(
        '/productos',
        [ProductoController::class, 'index']
    )->middleware('permission:products.view');

    Route::post(
        '/productos',
        [ProductoController::class, 'store']
    )->middleware('permission:products.create');

    Route::get(
        '/productos/codigo/{codigo}',
        [ProductoController::class, 'buscarPorCodigo']
    )->middleware('permission:products.view');

    Route::get(
        '/productos/{codigo_barras}',
        [ProductoController::class, 'buscarPorCodigo']
    )
        ->where('codigo_barras', '[0-9]{12,14}')
        ->middleware('permission:products.view');

    Route::get(
        '/productos/{producto}',
        [ProductoController::class, 'show']
    )->middleware('permission:products.view');

    Route::put(
        '/productos/{producto}',
        [ProductoController::class, 'update']
    )->middleware('permission:products.update');

    Route::patch(
        '/productos/{producto}',
        [ProductoController::class, 'update']
    )->middleware('permission:products.update');

    Route::delete(
        '/productos/{producto}',
        [ProductoController::class, 'destroy']
    )->middleware('permission:products.delete');

    Route::get(
        '/clientes',
        [ClienteController::class, 'index']
    )->middleware('permission:clients.view');

    Route::post(
        '/clientes',
        [ClienteController::class, 'store']
    )->middleware('permission:clients.create');

    Route::get(
        '/clientes/{cliente}',
        [ClienteController::class, 'show']
    )->middleware('permission:clients.view');

    Route::put(
        '/clientes/{cliente}',
        [ClienteController::class, 'update']
    )->middleware('permission:clients.update');

    Route::patch(
        '/clientes/{cliente}',
        [ClienteController::class, 'update']
    )->middleware('permission:clients.update');

    Route::delete(
        '/clientes/{cliente}',
        [ClienteController::class, 'destroy']
    )->middleware('permission:clients.delete');

    Route::get(
        '/movimientos',
        [MovimientoController::class, 'index']
    )->middleware('permission:inventory.view');

    Route::get(
        '/movimientos/{movimiento}',
        [MovimientoController::class, 'show']
    )->middleware('permission:inventory.view');

    Route::post(
        '/movimientos/entrada',
        [MovimientoController::class, 'entrada']
    )->middleware('permission:inventory.move');

    Route::post(
        '/movimientos/salida',
        [MovimientoController::class, 'salida']
    )->middleware('permission:inventory.move');

    Route::post(
        '/movimientos/ajuste',
        [MovimientoController::class, 'ajuste']
    )->middleware('permission:inventory.adjust');

    Route::post(
        '/inventario/movimiento',
        [MovimientoController::class, 'movimiento']
    )->middleware('permission:inventory.picking');

    Route::get(
        '/pedidos',
        [PedidoController::class, 'index']
    )->middleware('permission:orders.view');

    Route::post(
        '/pedidos',
        [PedidoController::class, 'store']
    )->middleware('permission:orders.create');

    Route::get(
        '/pedidos/{pedido}',
        [PedidoController::class, 'show']
    )->middleware('permission:orders.view');

    Route::put(
        '/pedidos/{pedido}',
        [PedidoController::class, 'update']
    )->middleware('permission:orders.update');

    Route::patch(
        '/pedidos/{pedido}',
        [PedidoController::class, 'update']
    )->middleware('permission:orders.update');

    Route::delete(
        '/pedidos/{pedido}',
        [PedidoController::class, 'destroy']
    )->middleware('permission:orders.delete');

    Route::post(
        '/pedidos/{pedido}/surtir',
        [PedidoController::class, 'surtir']
    )->middleware('permission:orders.surtir');

    Route::post(
        '/pedidos/{pedido}/entregar',
        [PedidoController::class, 'entregar']
    )->middleware('permission:orders.entregar');

    Route::post(
        '/pedidos/{pedido}/recibir',
        [PedidoController::class, 'recibir']
    )->middleware('permission:orders.receive');

    Route::post(
        '/pedidos/{pedido}/cancelar',
        [PedidoController::class, 'cancelar']
    )->middleware('permission:orders.cancel');

    Route::get(
        '/proveedores',
        [ProveedorController::class, 'index']
    )->middleware('permission:suppliers.view');

    Route::post(
        '/proveedores',
        [ProveedorController::class, 'store']
    )->middleware('permission:suppliers.create');

    Route::get(
        '/proveedores/{proveedor}',
        [ProveedorController::class, 'show']
    )->middleware('permission:suppliers.view');

    Route::put(
        '/proveedores/{proveedor}',
        [ProveedorController::class, 'update']
    )->middleware('permission:suppliers.update');

    Route::patch(
        '/proveedores/{proveedor}',
        [ProveedorController::class, 'update']
    )->middleware('permission:suppliers.update');

    Route::delete(
        '/proveedores/{proveedor}',
        [ProveedorController::class, 'destroy']
    )->middleware('permission:suppliers.delete');
});