<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\MovimientoController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProveedorController;
use Illuminate\Support\Facades\Route;


Route::post('/auth/login', [AuthController::class, 'login']);

Route::middleware(['auth:sanctum', 'abilities:2fa-setup'])->group(function () {
    Route::post('/auth/2fa/setup', [AuthController::class, 'setupTwoFactor']);
    Route::post('/auth/2fa/confirm', [AuthController::class, 'confirmTwoFactor']);
});

Route::middleware(['auth:sanctum', 'abilities:2fa-verify'])->group(function () {
    Route::post('/auth/2fa/verify', [AuthController::class, 'verifyTwoFactor']);
});

Route::middleware(['auth:sanctum', 'abilities:api-access'])->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/productos/{codigo_barras}', [ProductoController::class, 'buscarPorCodigo'])
        ->where('codigo_barras', '[0-9]{12,14}');

    Route::get('/productos/codigo/{codigo}', [ProductoController::class, 'buscarPorCodigo']);

    Route::apiResource('productos', ProductoController::class);

    Route::apiResource('clientes', ClienteController::class);

    Route::apiResource('proveedores', ProveedorController::class);

    Route::get('/movimientos', [MovimientoController::class, 'index']);
    Route::get('/movimientos/{movimiento}', [MovimientoController::class, 'show']);
    Route::post('/movimientos/entrada', [MovimientoController::class, 'entrada']);
    Route::post('/movimientos/salida', [MovimientoController::class, 'salida']);
    Route::post('/movimientos/ajuste', [MovimientoController::class, 'ajuste']);
    Route::post('/inventario/movimiento', [MovimientoController::class, 'movimiento']);

    Route::apiResource('pedidos', PedidoController::class);
    Route::post('/pedidos/{pedido}/surtir', [PedidoController::class, 'surtir']);
    Route::post('/pedidos/{pedido}/entregar', [PedidoController::class, 'entregar']);
    Route::post('/pedidos/{pedido}/recibir', [PedidoController::class, 'recibir']);
    Route::post('/pedidos/{pedido}/cancelar', [PedidoController::class, 'cancelar']);
});