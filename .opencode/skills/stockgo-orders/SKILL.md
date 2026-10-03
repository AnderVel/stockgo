---
name: stockgo-orders
description: Máquina de estados y consistencia de pedidos StockGo. Usar al tocar PedidoController, surtir, entregar, recibir, cancelar o edición.
when: Al modificar cualquier endpoint de pedidos o su lógica de reserva/movimientos.
---

# stockgo-orders

## Máquina de estados (real)

```
PENDIENTE --surtir--> SURTIDO --entregar--> ENVIADO --recibir--> ENTREGADO
PENDIENTE --cancelar--> CANCELADO
```

## Reglas

1. Crear pedido: valida producto `ACTIVO` y stock disponible; reserva stock (`stock_reservado += cantidad`), calcula `total` con precios vigentes, estado `PENDIENTE`.
2. Editar (`PUT/PATCH`): solo si `PENDIENTE`; ajustar reservas por diferencia de cantidades y recalcular total. Transacción + locks.
3. `surtir`: exige `PENDIENTE`; descuenta `stock_fisico` y `stock_reservado`; crea movimientos tipo SALIDA por línea.
4. `entregar`: exige `SURTIDO` → `ENVIADO`. `recibir`: exige `ENVIADO` → `ENTREGADO`.
5. `cancelar`: solo `PENDIENTE`; libera `stock_reservado`; estado `CANCELADO`.
6. `destroy`: solo si `CANCELADO`.
7. Todo paso dentro de `DB::transaction` con `lockForUpdate()` sobre pedido, detalles y productos.
8. Los errores deben distinguir: estado inválido (409), stock insuficiente (422), reserva inconsistente (409).
9. No cambiar nombres de campos (`id_cliente`, `detalles[].id_producto`, `detalles[].cantidad`, `fecha_pedido`, `observaciones`) sin revisar consumidores.
