<?php

namespace App\Services;

use App\Models\DetallePedido;
use App\Models\Movimiento;
use App\Models\Pedido;
use App\Models\Producto;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Recalcula el stock disponible de un producto.
     */
    private function recalcularDisponible(Producto $producto): void
    {
        $producto->stock_disponible = $producto->stock_fisico - $producto->stock_reservado;
    }

    /**
     * Valida que haya stock disponible suficiente.
     */
    private function validarStockDisponible(Producto $producto, int $cantidad): void
    {
        if ($cantidad > $producto->stock_disponible) {
            throw new \InvalidArgumentException(
                "Stock disponible insuficiente. Disponible: {$producto->stock_disponible}, solicitado: {$cantidad}"
            );
        }
    }

    /**
     * Valida que el stock físico sea suficiente.
     */
    private function validarStockFisico(Producto $producto, int $cantidad): void
    {
        if ($cantidad > $producto->stock_fisico) {
            throw new \InvalidArgumentException(
                "Stock físico insuficiente. Físico: {$producto->stock_fisico}, solicitado: {$cantidad}"
            );
        }
    }

    /**
     * Valida que la reserva no exceda el stock físico.
     */
    private function validarReserva(Producto $producto, int $cantidad): void
    {
        if (($producto->stock_reservado + $cantidad) > $producto->stock_fisico) {
            throw new \InvalidArgumentException(
                "La reserva excede el stock físico. Reservado actual: {$producto->stock_reservado}, físico: {$producto->stock_fisico}"
            );
        }
    }

    /**
     * Obtiene el usuario autenticado o lanza excepción.
     */
    private function getUsuarioId(): ?int
    {
        return Auth::id();
    }

    /**
     * Registra una entrada de mercancía.
     * Aumenta stock_fisico y recalcula stock_disponible.
     * Debe ejecutarse dentro de una transacción.
     */
    public function entrada(
        Producto $producto,
        int $cantidad,
        ?int $idProveedor = null,
        ?string $motivo = null
    ): Movimiento {
        $stockAnterior = $producto->stock_fisico;

        $producto->stock_fisico += $cantidad;
        $this->recalcularDisponible($producto);
        $producto->save();

        return Movimiento::create([
            'id_producto' => $producto->id_producto,
            'tipo' => 'ENTRADA',
            'cantidad' => $cantidad,
            'motivo' => $motivo ?? 'Entrada de mercancía',
            'id_proveedor' => $idProveedor,
            'stock_anterior' => $stockAnterior,
            'stock_nuevo' => $producto->stock_fisico,
            'usuario_id' => $this->getUsuarioId(),
        ]);
    }

    /**
     * Registra una salida de mercancía.
     * Disminuye stock_fisico y recalcula stock_disponible.
     * Debe ejecutarse dentro de una transacción.
     */
    public function salida(
        Producto $producto,
        int $cantidad,
        ?int $idPedido = null,
        ?string $motivo = null
    ): Movimiento {
        $this->validarStockDisponible($producto, $cantidad);

        $stockAnterior = $producto->stock_fisico;

        $producto->stock_fisico -= $cantidad;
        $this->recalcularDisponible($producto);
        $producto->save();

        return Movimiento::create([
            'id_producto' => $producto->id_producto,
            'tipo' => 'SALIDA',
            'cantidad' => $cantidad,
            'motivo' => $motivo ?? 'Salida de mercancía',
            'id_pedido' => $idPedido,
            'stock_anterior' => $stockAnterior,
            'stock_nuevo' => $producto->stock_fisico,
            'usuario_id' => $this->getUsuarioId(),
        ]);
    }

    /**
     * Registra un ajuste de inventario (positivo o negativo).
     * Debe ejecutarse dentro de una transacción.
     */
    public function ajuste(
        Producto $producto,
        int $cantidad,
        string $motivo
    ): Movimiento {
        $nuevoStockFisico = $producto->stock_fisico + $cantidad;

        if ($nuevoStockFisico < $producto->stock_reservado) {
            throw new \InvalidArgumentException(
                "El ajuste dejaría el stock físico ({$nuevoStockFisico}) por debajo del stock reservado ({$producto->stock_reservado})"
            );
        }

        if ($nuevoStockFisico < 0) {
            throw new \InvalidArgumentException(
                "El ajuste dejaría el stock físico en negativo ({$nuevoStockFisico})"
            );
        }

        $stockAnterior = $producto->stock_fisico;

        $producto->stock_fisico = $nuevoStockFisico;
        $this->recalcularDisponible($producto);
        $producto->save();

        return Movimiento::create([
            'id_producto' => $producto->id_producto,
            'tipo' => 'AJUSTE',
            'cantidad' => $cantidad,
            'motivo' => $motivo,
            'stock_anterior' => $stockAnterior,
            'stock_nuevo' => $producto->stock_fisico,
            'usuario_id' => $this->getUsuarioId(),
        ]);
    }

    /**
     * Reserva stock para un pedido.
     * Aumenta stock_reservado y recalcula stock_disponible.
     * Debe ejecutarse dentro de una transacción.
     */
    public function reservar(Producto $producto, int $cantidad): void
    {
        $this->validarStockDisponible($producto, $cantidad);
        $this->validarReserva($producto, $cantidad);

        $producto->stock_reservado += $cantidad;
        $this->recalcularDisponible($producto);
        $producto->save();
    }

    /**
     * Libera una reserva de stock.
     * Disminuye stock_reservado y recalcula stock_disponible.
     * Debe ejecutarse dentro de una transacción.
     */
    public function liberarReserva(Producto $producto, int $cantidad): void
    {
        if ($producto->stock_reservado < $cantidad) {
            throw new \InvalidArgumentException(
                "La reserva a liberar ({$cantidad}) excede el stock reservado actual ({$producto->stock_reservado})"
            );
        }

        $producto->stock_reservado -= $cantidad;
        $this->recalcularDisponible($producto);
        $producto->save();
    }

    /**
     * Surtir un pedido: descuenta stock_fisico, libera reserva y crea movimientos.
     * Debe ejecutarse dentro de una transacción.
     */
    public function surtir(Pedido $pedido): void
    {
        DB::transaction(function () use ($pedido) {
            if ($pedido->estado !== 'PENDIENTE') {
                throw new \InvalidArgumentException('El pedido no está pendiente de surtido.');
            }

            $detalles = $pedido->detalles()->lockForUpdate()->get();
            $idsProductos = $detalles->pluck('id_producto')->sort()->values();

            $productos = [];
            foreach ($idsProductos as $idProducto) {
                $productos[$idProducto] = Producto::where('id_producto', $idProducto)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            foreach ($detalles as $detalle) {
                $producto = $productos[$detalle->id_producto];

                $this->validarStockFisico($producto, $detalle->cantidad);

                if ($producto->stock_reservado < $detalle->cantidad) {
                    throw new \InvalidArgumentException(
                        "La reserva del producto '{$producto->nombre}' presenta una inconsistencia."
                    );
                }
            }

            foreach ($detalles as $detalle) {
                $producto = $productos[$detalle->id_producto];

                $stockAnterior = $producto->stock_fisico;

                $producto->stock_fisico -= $detalle->cantidad;
                $producto->stock_reservado -= $detalle->cantidad;
                $this->recalcularDisponible($producto);
                $producto->save();

                Movimiento::create([
                    'id_producto' => $producto->id_producto,
                    'tipo' => 'SALIDA',
                    'cantidad' => $detalle->cantidad,
                    'motivo' => "Salida por surtido del pedido #{$pedido->id_pedido}",
                    'id_pedido' => $pedido->id_pedido,
                    'stock_anterior' => $stockAnterior,
                    'stock_nuevo' => $producto->stock_fisico,
                    'usuario_id' => $this->getUsuarioId(),
                ]);
            }

            $pedido->update(['estado' => 'SURTIDO']);
        });
    }

    /**
     * Cancela un pedido: libera la reserva de stock.
     * Debe ejecutarse dentro de una transacción.
     */
    public function cancelar(Pedido $pedido): void
    {
        DB::transaction(function () use ($pedido) {
            if ($pedido->estado !== 'PENDIENTE') {
                throw new \InvalidArgumentException('Solo se pueden cancelar pedidos pendientes.');
            }

            $detalles = $pedido->detalles()->lockForUpdate()->get();
            $idsProductos = $detalles->pluck('id_producto')->sort()->values();

            $productos = [];
            foreach ($idsProductos as $idProducto) {
                $productos[$idProducto] = Producto::where('id_producto', $idProducto)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            foreach ($detalles as $detalle) {
                $producto = $productos[$detalle->id_producto];

                if ($producto->stock_reservado < $detalle->cantidad) {
                    throw new \InvalidArgumentException(
                        "La reserva del producto '{$producto->nombre}' presenta una inconsistencia."
                    );
                }

                $producto->stock_reservado -= $detalle->cantidad;
                $this->recalcularDisponible($producto);
                $producto->save();
            }

            $pedido->update(['estado' => 'CANCELADO']);
        });
    }

    /**
     * Reserva stock para un pedido usando ID.
     * Debe ejecutarse dentro de una transacción.
     */
    public function reservarPorId(int $idProducto, int $cantidad): void
    {
        $producto = Producto::where('id_producto', $idProducto)
            ->lockForUpdate()
            ->firstOrFail();

        $this->validarStockDisponible($producto, $cantidad);
        $this->validarReserva($producto, $cantidad);

        $producto->stock_reservado += $cantidad;
        $this->recalcularDisponible($producto);
        $producto->save();
    }

    /**
     * Libera una reserva de stock usando ID.
     * Debe ejecutarse dentro de una transacción.
     */
    public function liberarReservaPorId(int $idProducto, int $cantidad): void
    {
        $producto = Producto::where('id_producto', $idProducto)
            ->lockForUpdate()
            ->firstOrFail();

        if ($producto->stock_reservado < $cantidad) {
            throw new \InvalidArgumentException(
                "La reserva a liberar ({$cantidad}) excede el stock reservado actual ({$producto->stock_reservado})"
            );
        }

        $producto->stock_reservado -= $cantidad;
        $this->recalcularDisponible($producto);
        $producto->save();
    }

    /**
     * Ejecuta un movimiento desde Android (recepcion o picking).
     * Para picking, SOLO descuenta stock_reservado si el pedido_id corresponde
     * a un pedido que contiene este producto con cantidad reservada suficiente.
     * Si no hay pedido_id, no existe, o el pedido no contiene este producto con reserva suficiente,
     * solo descuenta stock_fisico y valida stock_disponible.
     */
    public function ejecutarMovimientoAndroid(
        string $codigoBarras,
        string $tipoMovimiento,
        int $cantidad,
        ?int $idPedido = null
    ): array {
        $producto = Producto::where('codigo_barras', $codigoBarras)
            ->lockForUpdate()
            ->first();

        if (! $producto) {
            return [
                'error' => true,
                'mensaje' => 'Código de producto no registrado.',
            ];
        }

        $stockDisponible = $producto->stock_fisico - $producto->stock_reservado;

        if ($tipoMovimiento === 'recepcion') {
            $stockAnterior = $producto->stock_fisico;

            $producto->stock_fisico += $cantidad;
            $this->recalcularDisponible($producto);
            $producto->save();

            Movimiento::create([
                'id_producto' => $producto->id_producto,
                'tipo' => 'ENTRADA',
                'cantidad' => $cantidad,
                'motivo' => 'Recepción de mercancía',
                'id_pedido' => $idPedido,
                'stock_anterior' => $stockAnterior,
                'stock_nuevo' => $producto->stock_fisico,
                'usuario_id' => $this->getUsuarioId(),
            ]);

            return ['error' => false];
        }

        if ($cantidad > $stockDisponible) {
            return [
                'error' => true,
                'mensaje' => 'La cantidad solicitada excede el stock disponible.',
            ];
        }

        $stockAnterior = $producto->stock_fisico;

        $producto->stock_fisico -= $cantidad;

        // Para picking (salida desde Android), solo reducir stock_reservado
        // si se proporciona un idPedido válido Y EXISTENTE Y el pedido contiene
        // este producto con cantidad reservada suficiente en sus detalles.
        // Si no hay pedido_id, no existe, o el pedido no tiene reserva para este producto,
        // solo validar stock_disponible y descontar stock_fisico.
        $debeReducirReserva = false;
        if ($idPedido !== null && $idPedido > 0) {
            $detallePedido = DetallePedido::where('id_pedido', $idPedido)
                ->where('id_producto', $producto->id_producto)
                ->first();

            if ($detallePedido && $producto->stock_reservado >= $cantidad) {
                // Verificar que la cantidad solicitada no exceda lo reservado en este pedido
                if ($detallePedido->cantidad >= $cantidad) {
                    $debeReducirReserva = true;
                }
            }
        }

        if ($debeReducirReserva) {
            $producto->stock_reservado -= $cantidad;
        }

        $this->recalcularDisponible($producto);
        $producto->save();

        // Solo registrar id_pedido en el movimiento si es un pedido válido
        // que contiene este producto con reserva suficiente
        $idPedidoValido = null;
        if ($idPedido !== null && $idPedido > 0) {
            $detallePedido = DetallePedido::where('id_pedido', $idPedido)
                ->where('id_producto', $producto->id_producto)
                ->first();

            if ($detallePedido && $detallePedido->cantidad >= $cantidad) {
                $idPedidoValido = $idPedido;
            }
        }

        Movimiento::create([
            'id_producto' => $producto->id_producto,
            'tipo' => 'SALIDA',
            'cantidad' => $cantidad,
            'motivo' => 'Picking desde Android',
            'id_pedido' => $idPedidoValido,
            'stock_anterior' => $stockAnterior,
            'stock_nuevo' => $producto->stock_fisico,
            'usuario_id' => $this->getUsuarioId(),
        ]);

        return ['error' => false];
    }

    /**
     * Ejecuta un movimiento desde Android por ID de producto.
     */
    public function ejecutarMovimientoProducto(
        Producto $producto,
        string $tipo,
        int $cantidad,
        ?int $idPedido = null
    ): array {
        $tipoMovimiento = $tipo === 'entrada' ? 'recepcion' : 'picking';

        return $this->ejecutarMovimientoAndroid($producto->codigo_barras, $tipoMovimiento, $cantidad, $idPedido);
    }
}
