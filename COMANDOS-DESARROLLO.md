# Comandos de desarrollo HBM Facturador

Ejecuta los de Docker desde la **raíz del repo** (`c:\Cursor\HBM-facturador`).  
Los de Vite desde la carpeta **`frontend`**.

---

## 1. Levantar el proyecto

**Windows:** no hagas **doble clic** en `HBM.ps1` (Windows muestra “Abrir con”). Usa una de estas:

- Doble clic en **`HBM-Levantar.cmd`** (solo levantar sin tocar BD).
- O en **PowerShell / terminal del repo:** `.\HBM.ps1 -Levantar`
- O en **CMD:** `HBM.cmd -Levantar`

| Comando | Para qué sirve |
|--------|------------------|
| `docker compose up -d` | Arranca MySQL, PHP (Laravel), Nginx (API en **8080**), Adminer. |
| `.\HBM.ps1 -Levantar` o `HBM.cmd -Levantar` o `HBM-Levantar.cmd` | Sube Docker **sin** migraciones ni seeders; luego `npm install` + `npm run dev` (front en **5173**). **Recomendado día a día** si no quieres tocar la BD. |
| **`HBM-UsuariosDev.cmd`** (doble clic) o `docker compose exec -T laravel php artisan hbm:ensure-dev-users` | Si **`users` está vacía**, crea admin / super_admin / empleado demo (`EnsureDevLoginSeeder`). No borra datos. |
| `.\HBM.ps1` | Docker + `hbm:sync` (migrate + seeders según reglas del proyecto) + Vite. Útil tras clonar o cuando quieres sincronizar BD y front de una vez. |
| `.\HBM.ps1 -Build` | Igual que `.\HBM.ps1` pero reconstruye imágenes Docker antes del `up`. |
| `cd frontend` → `npm install` → `npm run dev` | Solo el front con hot reload (**http://localhost:5173**). Requiere API arriba (Docker o backend en 8080). |

Si el puerto **5173** está ocupado por Vite local, puedes levantar solo backend:  
`docker compose up -d mysql laravel nginx adminer`

---

## 2. Base de datos: migraciones (sin borrar toda la BD)

| Comando | Para qué sirve |
|--------|------------------|
| `docker compose exec laravel php artisan migrate --force` | Aplica **solo** migraciones pendientes. **No** es `migrate:fresh` (no vacía tablas). |
| `docker exec hbm_php php artisan migrate --force` | Mismo efecto usando el nombre del contenedor PHP. |
| `docker compose exec laravel php artisan hbm:sync --migrate-only --force` | Solo `migrate`; **sin** seeders (no resembrar datos). |
| `.\HBM.ps1 -SoloMigrar` | Sube Docker, espera MySQL y ejecuta `hbm:sync --migrate-only`; **no** abre Vite (termina ahí). |

---

## 3. Seeders (datos demo / usuarios)

| Comando | Para qué sirve |
|--------|------------------|
| `docker compose exec laravel php artisan db:seed --force` | Ejecuta **`DatabaseSeeder`** (empresas demo, usuarios, catálogo, `DemoPublicInvoiceSeeder`, etc.). Puede **actualizar** filas que coincidan con claves del seed. |
| `docker compose exec laravel php artisan db:seed --class="Database\Seeders\EnsureDevLoginSeeder" --force` | Igual que `hbm:ensure-dev-users` (solo usuarios si `users` está vacía). |
| `docker compose exec laravel php artisan hbm:ensure-dev-users` | Atajo con mensaje claro; mismo seeder que la fila anterior. |
| `docker compose exec laravel php artisan hbm:sync --demo --force` | Migrate + **fuerza** `DatabaseSeeder` completo (demo explícito). |
| `docker compose exec laravel php artisan hbm:sync --force --no-interaction` | Migrate + `EnsureDevLoginSeeder` + `DatabaseSeeder` **solo si no hay empresas** en `companies` (o con `--demo`). |

---

## 4. Peligroso (borra o recrea mucha información)

| Comando | Para qué sirve |
|--------|------------------|
| `docker compose exec laravel php artisan hbm:sync --fresh` | **`migrate:fresh` + seed**: **borra y recrea** tablas. Solo si quieres BD desde cero. |
| `docker compose down -v` | Baja contenedores y **elimina volúmenes** (pierdes datos de MySQL). **Evitar** salvo que sea intencional. |

---

## 5. Copia de seguridad MySQL (local)

| Comando | Para qué sirve |
|--------|------------------|
| `.\scripts\backup-mysql.ps1` | Volcado SQL de `hbm_facturador` en `backups\` (con Docker/MySQL arriba). |
| `.\scripts\restore-mysql.ps1 -Archivo .\backups\archivo.sql` | Restaura un volcado (**sustituye** datos actuales de la base). |
| `.\scripts\list-backups-mysql.ps1` | Lista los `.sql` en `backups\`. |

---

## 6. Utilidades

| Comando | Para qué sirve |
|--------|------------------|
| `docker compose ps` | Ver qué contenedores están corriendo y puertos. |
| `docker compose down` | Para contenedores **sin** `-v` (conserva volumen de datos MySQL). |

---

## URLs habituales

- Front (Vite): **http://localhost:5173**
- API (Nginx): **http://localhost:8080**
- Adminer: según `docker-compose` (a veces **8081** u **8082**; revisa `docker compose ps`).

---

## Orden mínimo típico “todo arriba”

1. `docker compose up -d`  
2. `cd frontend` → `npm run dev`  

O en un solo paso (sin tocar BD): **`.\HBM.ps1 -Levantar`**
