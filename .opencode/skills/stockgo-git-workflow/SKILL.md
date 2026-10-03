---
name: stockgo-git-workflow
description: Reglas Git de StockGo. Usar antes y después de cualquier cambio.
when: Antes de empezar una tarea y después de terminarla.
---

# stockgo-git-workflow

## Antes de cambios

```bash
git status
```

## Después de cambios

```bash
git diff
```

## Prohibiciones

- `git reset --hard`
- `git push --force`
- Borrar migraciones ya aplicadas
- Modificar `.env` automáticamente
- Exponer secretos (`.env`, tokens, secrets de 2FA)
- Commits automáticos salvo solicitud explícita

## Adicionales

- Revisa siempre `git diff` antes de proponer commit.
- Un cambio de migración se añade; nunca se edita una migración ya corrida.
- Si el usuario pide WIP/checkpoint, prefiere commits claros y pequeños sobre un solo commit grande.
