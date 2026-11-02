<?php

use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClienteController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InventarioController;
use App\Http\Controllers\Api\MovimientoController;
use App\Http\Controllers\Api\PedidoController;
use App\Http\Controllers\Api\ProductoController;
use App\Http\Controllers\Api\ProveedorController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::get(
    '/auth/me',
    [AuthController::class, 'me']
);

Route::get(
    '/dashboard',
    [DashboardController::class, 'index']
)->middleware('permission:dashboard.view');

// PRODUCTOS

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

// CLIENTES

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

// MOVIMIENTOS E INVENTARIO

Route::get(
    '/movimientos',
    [MovimientoController::class, 'index']
)->middleware('permission:inventory.view');

Route::get(
    '/movimientos/recientes',
    [InventarioController::class, 'recientes']
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

Route::post(
    '/productos/{producto}/movimiento',
    [MovimientoController::class, 'movimientoProducto']
)->middleware('permission:inventory.picking');

// NUEVA RUTA: ENTRADA DE INVENTARIO POR PRODUCTO

Route::post(
    '/productos/{producto}/entrada',
    [MovimientoController::class, 'entradaProducto']
)->middleware('permission:inventory.receive');

// INVENTARIO

Route::get(
    '/inventario/resumen',
    [InventarioController::class, 'resumen']
)->middleware('permission:inventory.view');

Route::get(
    '/inventario/alertas',
    [InventarioController::class, 'alertas']
)->middleware('permission:inventory.view');

Route::get(
    '/inventario/por-ubicacion',
    [InventarioController::class, 'porUbicacion']
)->middleware('permission:inventory.view');

// PEDIDOS

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

// PROVEEDORES

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

// USUARIOS

Route::get(
    '/usuarios',
    [UserController::class, 'index']
)->middleware('permission:users.view');

Route::post(
    '/usuarios',
    [UserController::class, 'store']
)->middleware('permission:users.create');

Route::put(
    '/usuarios/{user}',
    [UserController::class, 'update']
)->middleware('permission:users.update');

Route::patch(
    '/usuarios/{user}',
    [UserController::class, 'update']
)->middleware('permission:users.update');

Route::post(
    '/usuarios/{user}/reset-2fa',
    [UserController::class, 'resetTwoFactor']
)->middleware('permission:users.reset_2fa');

// AUDITORÍA

Route::get(
    '/auditorias',
    [AuditoriaController::class, 'index']
)->middleware('permission:audit.view');
