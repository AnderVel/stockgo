# StockGo — Documentación

## Arquitectura

```
Android (Sanctum + TOTP) ──┐
                           ├─► Laravel API ──► PostgreSQL/Neon
Web Admin (JWT) ───────────┘
```

- **Android** (Kotlin/Compose/Retrofit): flujo Sanctum + 2FA existente, sin cambios.
- **Web Admin** (`web-admin/`, React + TS + Vite): JWT (access 30 min) + refresh token (7 días), mismo usuario, mismos roles, mismos permisos, misma lógica de negocio.
- La API es la única puerta a la base de datos. Ningún frontend toca Neon.

## Autenticación

### Android (sin cambios)
- `POST /api/auth/login` → `setup_token` o `challenge_token`
- `POST /api/auth/2fa/setup|confirm|verify` → token `api-access` (Sanctum)
- `GET /api/auth/me`, `POST /api/auth/logout`

### Web Admin (nuevo)
- `POST /api/web/auth/login` `{usuario, password, code}` → `{token, refresh_token, expires_in}`
- `POST /api/web/auth/refresh` `{refresh_token}` → rota y emite nuevo par
- `GET /api/web/auth/me` (JWT)
- `POST /api/web/auth/logout` (revoca el refresh token si se envía)
- Todas las rutas protegidas web viven bajo `/api/web/*` con middleware `JwtAuthenticate` + `permission`.

## Roles y permisos

- `Administrador`: todos los permisos (productos, inventario, pedidos, clientes, proveedores, usuarios, auditoría).
- `Operador de Almacén`: `products.view`, `inventory.view`, `inventory.picking`, `orders.view`, `orders.surtir`.
- Middleware `CheckPermission` se aplica igualmente en rutas Sanctum y JWT.

## Endpoints nuevos

| Método | Ruta | Permiso |
|---|---|---|
| POST | /api/web/auth/login | público (rate limit) |
| POST | /api/web/auth/refresh | público (rate limit) |
| GET | /api/web/auth/me | JWT |
| GET | /api/usuarios | users.view |
| POST | /api/usuarios | users.create |
| PUT/PATCH | /api/usuarios/{user} | users.update |
| POST | /api/usuarios/{user}/reset-2fa | users.reset_2fa |
| GET | /api/auditorias | audit.view |

Todos tienen su equivalente bajo `/api/web/*` para el JWT del Web Admin.

## Variables de entorno requeridas

```
# .env
JWT_SECRET=              # obligatoria, >= 32 bytes, nunca en el repo
JWT_TTL_MINUTES=30
JWT_REFRESH_TTL_DAYS=7
CORS_ALLOWED_ORIGINS=https://tu-web-admin.com
```

## Migraciones creadas (NO ejecutadas automáticamente)

- `2026_10_03_000000_agregar_usuario_id_a_movimientos.php`
- `2026_10_03_010000_create_web_refresh_tokens_table.php`

Ejecutar manualmente: `php artisan migrate`

## Seguridad implementada

- JWT HS256 firmado, secreto en `.env`, expiración corta, refresh rotation con hash SHA-256.
- Rate limiting: login/refresh 30/min+5 intentos por cuenta+IP; API general 120/min.
- CORS configurable por entorno (nunca `*` en producción).
- Auditoría de operaciones sensibles y de autenticación sin secretos.
- Permisos en servidor (CheckPermission) para todas las rutas.
- Usuario autenticado del token (no del body) para acciones.
- Sin secretos en código ni en respuestas.

## Desarrollo y ejecución

Backend: `php artisan serve`
Web Admin: `cd web-admin && npm install && npm run dev` (proxy a :8000)
Tests: `php artisan test`

## Nota de compatibilidad

No se modificaron rutas, campos ni códigos HTTP consumidos por Android. `usuario_id` en `/api/inventario/movimiento` sigue aceptándose (nullable) pero ya no se confía en él.
