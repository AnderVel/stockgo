---
name: stockgo-api-security
description: Checklist de seguridad de la API StockGo (IDOR, autorización por bodega, mass assignment, tokens, 2FA, CORS, rate limiting). Usar al tocar endpoints, autenticación o permisos.
when: Al modificar rutas protegidas, autenticación, 2FA, permisos, o revisar seguridad de un endpoint.
---

# stockgo-api-security

## Cuándo usar esta skill
- Antes y después de cambiar cualquier endpoint.
- Al revisar un PR o una nueva ruta.

## Checklist (revisar siempre)

1. **IDOR / autorización por recurso**: ¿el usuario puede acceder a cualquier ID? Considerar restringir por `rol` y `bodega_asignada`.
2. **Mass assignment**: revisar `$fillable` de los modelos; nunca aceptar campos derivados del request como `stock_disponible`, `stock_reservado`, `estado`, `usuario_id` si no se validan.
3. **Identidad**: usar `$request->user()` en lugar de IDs enviados en el body (ej. `/inventario/movimiento` históricamente aceptaba `usuario_id`).
4. **Tokens**: Sanctum abilities (`2fa-setup`, `2fa-verify`, `api-access`); expiración de 8 h; no extender sin justificar.
5. **2FA**: flujo login → setup/challenge → verify → api-access. No cambiar sin verificar consumidores (Android probado).
6. **Rate limiting**: login y verify 2FA limitados a 5 intentos; considerar `throttle` en el grupo API.
7. **Permisos**: toda ruta protegida debe tener `auth:sanctum` + `abilities:api-access` + `permission:...`. Nunca saltarse `CheckPermission`.
8. **CORS**: ninguna config personalizada aún; definirla antes de exponer el Web Admin.
9. **Validación de outputs**: no exponer secretos (`two_factor_secret` ya está en `$hidden`), tokens ni hashes.
10. **Enumeración**: respuestas genéricas en login; 401/403/404 coherentes.
11. **Auditoría**: las operaciones sensibles deben quedar registradas (AuditoriaService), no solo los permisos.
