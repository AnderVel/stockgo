# AGENTS.md — StockGo

Sistema de gestión de inventario, productos y pedidos con arquitectura API central.

## Arquitectura

```
Android ────────┐
                ├──► Laravel API ──► PostgreSQL (Neon)
Web Admin ──────┘
```

- Android y Web **NO** se conectan directamente a Neon.
- La API es la capa central de: autenticación, autorización, permisos, 2FA, productos, inventario, movimientos, pedidos, clientes, proveedores, auditoría y reglas de negocio.

## Backend actual

- Laravel 13.x, PHP 8.3, PostgreSQL (Neon)
- Laravel Sanctum (tokens con abilities)
- PragmaRX Google2FA
- Eloquent, API REST/JSON

## Android

- Kotlin, Jetpack Compose, Retrofit, Gson, Google Authenticator/TOTP
- El Android **YA** se conectó correctamente a la API (login, 2FA, setup inicial, token, dashboard, productos, scanner, búsqueda por código de barras, recepción/picking, movimientos).
- **NO ROMPER LA COMPATIBILIDAD CON ANDROID.**
- Antes de modificar cualquier endpoint: localizar consumidores → revisar request → revisar response → revisar códigos HTTP → revisar nombres de campos → evaluar impacto en Android → proponer migración compatible si es necesaria.

## Autenticación y 2FA

- Sanctum con abilities: `2fa-setup`, `2fa-verify`, `api-access`.
- Flujo: `login` → `setup_token` o `challenge_token` → 2FA → `token` con `api-access`.
- No cambiar este flujo sin revisar todos sus consumidores.

## Permisos

- Roles: `Administrador`, `Operador de Almacén`.
- Definidos en `config/permissions.php`.
- Middleware: `app/Http/Middleware/CheckPermission.php` (alias `permission`).
- No eliminar ni saltarse middleware para facilitar pruebas.

## Inventario

Regla fundamental:

```
stock_disponible = stock_fisico - stock_reservado
```

- Nunca permitir `stock_disponible < 0`.
- Operaciones sensibles: transacciones y `lockForUpdate()` cuando corresponda.
- No modificar inventario directamente desde frontend.
- Todo movimiento debe quedar registrado (tabla `movimientos`).

## Pedidos

Flujo:

```
PENDIENTE → SURTIDO → ENVIADO → ENTREGADO
PENDIENTE → CANCELADO
```

- La reserva de stock y los movimientos deben conservar consistencia.
- SURTIDO: descuenta `stock_fisico` y `stock_reservado`; CANCELADO: libera reserva.

## Auditoría

- Modelos/servicios: `Auditoria.php`, `AuditoriaService.php`.
- Las operaciones sensibles deben mantener trazabilidad.

## Seguridad (prioridades)

IDOR, autorización por recurso, autorización por bodega, rate limiting, brute force, enumeración, tokens, 2FA, mass assignment, validación de inputs/outputs, CORS, auditoría, seguridad de movimientos, concurrencia de inventario, consistencia de pedidos.

## Reglas Git

- Antes: `git status`. Después: `git diff`.
- PROHIBIDO: `git reset --hard`, `git push --force`.
- NO borrar migraciones aplicadas.
- NO modificar `.env` automáticamente.
- NO exponer secretos.
- NO hacer commits automáticos salvo solicitud del usuario.

## Metodología (siempre en este orden)

1. inspeccionar → 2. comprender → 3. buscar dependencias → 4. detectar riesgos → 5. proponer solución → 6. implementar → 7. probar → 8. revisar `git diff` → 9. comprobar que no se rompió Android/API/Web → 10. explicar cambios.

Si una modificación afecta una API usada por Android: **no cambiar silenciosamente el contrato**.

Ante duda sobre una función, clase, ruta, permiso, relación o columna: **inspeccionar el código real antes de asumir que existe**.
