---
name: stockgo-architecture
description: Arquitectura de StockGo (Android/Web → Laravel API → PostgreSQL/Neon). Usar al crear módulos, decidir dónde vive una regla de negocio, o estructurar un nuevo endpoint.
when: Al diseñar nuevas features, mover lógica entre capas, o decidir si un cambio va en API, Web Admin o Android.
---

# stockgo-architecture

## Cuándo usar esta skill
- Al añadir un módulo nuevo (endpoint, servicio, modelo).
- Al decidir si una regla de negocio pertenece a la API.
- Al estructurar un controlador o servicio nuevo.

## Reglas

1. La API es la única puerta a PostgreSQL/Neon. Android y Web nunca tocan la BD.
2. Toda regla de negocio (stock, estados de pedido, permisos, 2FA, auditoría) vive en la API: controladores → servicios → modelos. No duplicar reglas en Android ni Web.
3. Controladores API en `app/Http/Controllers/Api/`, JSON siempre, validación con `$request->validate(...)`.
4. Operaciones sensibles dentro de `DB::transaction(...)` con `lockForUpdate()` sobre los productos/pedidos involucrados.
5. Toda mutación de inventario/pedido crea un registro en `movimientos` y, si corresponde, audita vía `AuditoriaService`.
6. No introducir lógica de negocio en `routes/api.php`; solo middleware + controlador.
7. Respuestas JSON coherentes; ante duda revisar el formato existente por módulo y homologarlo deliberadamente, no al azar.
