---
name: hbm-safe-db-ops
description: Estandariza operaciones de base de datos seguras en HBM Facturador (backup, migraciones no destructivas, seed mínimo y validaciones). Usar cuando el usuario pida migrar, sincronizar esquema, recuperar entorno local o tocar comandos de datos.
disable-model-invocation: true
---

# HBM Safe DB Ops

## Objetivo

Evitar perdida de datos y mantener operaciones reproducibles sobre MySQL local en Docker.

## Flujo obligatorio

1. Identificar si la accion modifica datos o esquema.
2. Si hay riesgo, ejecutar backup antes del cambio con `scripts/backup-mysql.ps1` (Windows) o `scripts/backup-mysql.sh` (Unix).
3. Preferir cambios de esquema no destructivos:
   - `php artisan migrate --force`
   - `php artisan hbm:sync --migrate-only`
4. Si se requiere login de desarrollo y `users` vacio, usar:
   - `php artisan db:seed --class=Database\\Seeders\\EnsureDevLoginSeeder`
5. Verificar estado final (migraciones y salud basica de API) y reportar resultados.

## Comandos prohibidos sin confirmacion explicita del usuario

- `php artisan migrate:fresh`
- `php artisan migrate:refresh`
- `php artisan db:wipe`
- `php artisan hbm:sync --fresh`
- `docker compose down -v`
- Borrado/truncate manual de tablas

Si el usuario no los pide literalmente, detenerse y proponer alternativa segura.

## Checklist de seguridad y consistencia

- Confirmar alcance: solo esquema vs esquema + datos.
- Mantener atomicidad operativa (no mezclar pasos destructivos y de recuperacion en un solo bloque ciego).
- No exponer secretos en comandos, logs ni respuestas.
- Si hay error de migracion, no recomendar limpieza destructiva por defecto.

## Ejemplos de disparo

- "migrar cambios"
- "sincroniza base"
- "rompi el esquema"
- "levantar entorno de dev"
- "faltan usuarios para login"

## Salida esperada al usuario

- Comandos ejecutados o propuestos (seguros).
- Confirmacion de que no se usaron comandos destructivos.
- Riesgos detectados y siguiente paso minimo para recuperar consistencia.
