---
name: stockgo-inventory
description: Reglas de inventario de StockGo: invariante de stock, movimientos, concurrencia. Usar al tocar productos/movimientos/recepción/picking.
when: Al modificar ProductoController, MovimientoController, o cualquier lógica que toque stock físico/reservado/disponible.
---

# stockgo-inventory

## Invariante (NUNCA romper)

```
stock_disponible = stock_fisico - stock_reservado
stock_disponible >= 0
```

## Reglas

1. Todo cambio de stock ocurre dentro de `DB::transaction` y con `lockForUpdate()` sobre el producto.
2. Cada cambio crea un registro en `movimientos` con: `tipo` (ENTRADA/SALIDA/AJUSTE), `cantidad`, `motivo`, `stock_anterior`, `stock_nuevo`, y opcionalmente `id_pedido`/`id_proveedor`.
3. Entrada: `stock_fisico += cantidad`. Salida: validar `cantidad <= stock_disponible`. Ajuste: permitir negativo pero validar que no quede por debajo de `stock_reservado`.
4. Picking (`POST /inventario/movimiento`): por `codigo_barras`, tipo `recepcion` (suma) o `picking` (resta y reduce `stock_reservado`).
5. Nunca modificar stock desde frontends; siempre vía API.
6. No exponer `usuario_id` del movimiento desde el body: usar el usuario autenticado.
7. Stock reservado solo se modifica por ciclo de pedidos (crear/editar/cancelar/surtir), no a mano.
