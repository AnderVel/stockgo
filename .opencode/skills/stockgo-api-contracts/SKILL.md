---
name: stockgo-api-contracts
description: Cómo documentar y evolucionar contratos de API sin romper Android. Usar al cambiar request/response, rutas o campos.
when: Antes de tocar cualquier endpoint consumido por Android o Web.
---

# stockgo-api-contracts

## Regla de oro

El Android ya probado (login, 2FA, dashboard, productos, scanner, picking/recepción) **no debe romperse**.

## Antes de modificar un endpoint

1. Localizar consumidores (buscar en el repo referencias a la ruta y al campo; asumir que Android lo usa si es público).
2. Revisar request actual: validaciones, tipos, nombres exactos.
3. Revisar response actual: estructura (`status`, `message`/`mensaje`, datos) y códigos HTTP.
4. Revisar códigos HTTP de error esperados por el cliente.
5. Evaluar impacto en Android → si hay impacto, **NO** cambiar el contrato directamente.
6. Proponer migración compatible: nuevo campo opcional, nuevo endpoint, o versión (`/api/v2/...`) con periodo de convivencia.
7. Deprecar formalmente lo viejo (documentar) antes de eliminarlo.

## Formato de documentación de contrato (cuando agregues o cambies uno)

```
METHOD /ruta
Auth: abilities + permission
Request: { campos, tipos, requeridos }
Response 200/201: { estructura real }
Errores: { código: causa }
Consumidores: Android/Web/etc.
```

## Contrato de autenticación (intocable sin revisión previa)

`POST /auth/login` → `{ two_factor_setup_required? | two_factor_required?, setup_token | challenge_token, expires_in, user }`
`POST /auth/2fa/setup|confirm|verify` → token `api-access`
`GET /auth/me`, `POST /auth/logout`
