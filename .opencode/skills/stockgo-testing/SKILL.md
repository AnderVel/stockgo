---
name: stockgo-testing
description: Cómo probar cambios en StockGo sin romper Android/API/Web. Usar después de implementar.
when: Tras modificar endpoints, inventario, pedidos o auth.
---

# stockgo-testing

## Qué probar siempre

1. **Inventario**: entrada/salida/ajuste dejan `stock_disponible = stock_fisico - stock_reservado` y nunca < 0; movimientos registrados; concurrencia (dos salidas simultáneas no sobrevenden).
2. **Pedidos**: transiciones válidas (PENDIENTE→SURTIDO→ENVIADO→ENTREGADO, PENDIENTE→CANCELADO); edición solo en PENDIENTE; reserva liberada al cancelar; surtir descuenta físico y reservado.
3. **Auth/2FA**: login sin 2FA → setup → confirm → token; login con 2FA → challenge → verify → token; rate limit 5 intentos; token con ability correcta.
4. **Permisos**: Operador no puede crear productos; Administrador sí; middleware deniega sin permiso.
5. **Contratos**: mismos campos/códigos HTTP que antes para rutas consumidas por Android.

## Cómo probar

- Tests: `php artisan test` (no sobrescribir `database/database.sqlite` de producción si aplica).
- Manual con Postman/Insomnia: headers `Authorization: Bearer ...`, `Accept: application/json`.
- Para flujos críticos, probar también la respuesta de error (401, 403, 409, 422, 429).

## Después de probar

```bash
git diff
```

Explica qué cambió y confirma que Android/Web no se ven afectados.
