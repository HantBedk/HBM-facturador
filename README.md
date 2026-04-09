# HBM Facturador

Sistema de registro de servicios, facturación, pagos y consulta pública para clientes (sin login).

## Requisitos

- PHP 8.2+, Composer, Node.js 20+ (frontend)
- MySQL 8 (o SQLite para desarrollo/tests)
- Opcional: Docker (ver `docker-compose.yml`)

## Levantar en desarrollo

### Backend (Laravel)

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed   # opcional: datos demo
php artisan serve     # o usar Docker/nginx en :8080
```

Sincronización manual alternativa (migraciones + seeders controlados por el comando interno):

```bash
php artisan hbm:sync              # migrate + EnsureDevLoginSeeder; DatabaseSeeder solo si no hay empresas o con --demo
php artisan hbm:sync --migrate-only # solo migraciones, sin seeders
```

Evita en datos reales: `hbm:sync --fresh` (equivale a `migrate:fresh` y borra tablas).

### Frontend (Vue + Vite)

En el host:

```bash
cd frontend
npm ci
npm run dev
```

Por defecto Vite sirve en `http://localhost:5173` y proxifica `/api` al backend (variable `VITE_API_PROXY_TARGET`, p. ej. `http://127.0.0.1:8080`).

### Docker (MySQL + Laravel + Nginx + Vite + Adminer)

El `docker-compose.yml` levanta:

| Servicio | Rol |
|----------|-----|
| `mysql` | MySQL 8 (datos en volumen `mysql_data`) |
| `laravel` | PHP-FPM; código en `./backend` |
| `nginx` | HTTP; sirve el backend Laravel |
| `frontend` | Node 22; `npm ci && npm run dev` (Vite en el contenedor) |
| `adminer` | Consola web para MySQL |

Puertos publicados en el **host** (ajustados para no chocar con MySQL local u otros stacks):

| Puerto host | Destino | Uso |
|-------------|---------|-----|
| **13306** | mysql:3306 | Cliente MySQL desde el host (`127.0.0.1:13306`) |
| **8080** | nginx:80 | API / backend: `http://localhost:8080/api` |
| **5173** | frontend:5173 | SPA en desarrollo (Vite en contenedor; `VITE_API_PROXY_TARGET=http://nginx`) |
| **8082** | adminer:8080 | Adminer: `http://localhost:8082` |

**Primera vez con Docker** (hace falta `vendor/` antes de que arranque bien Laravel):

```bash
docker compose build laravel
docker compose run --rm laravel composer install --no-interaction
cp backend/.env.example backend/.env   # si aún no existe
docker compose run --rm laravel php artisan key:generate
docker compose up -d
```

Tras cambios en `docker/php/Dockerfile` o `docker/php/docker-entrypoint.sh`: `docker compose build laravel && docker compose up -d`.

**Arranque automático del contenedor `laravel`:** el entrypoint ejecuta `php artisan migrate --force` (solo migraciones pendientes; no borra filas) y, si está definido, `db:seed --class=…` según variables de entorno (ver tabla siguiente). Reintenta la conexión a MySQL unos segundos si aún no está listo.

**Adminer:** en el formulario de login, sistema **MySQL**, servidor **`mysql`** (nombre del servicio en la red de Compose), usuario/contraseña como en `docker-compose` (`hbm` / `hbm_secret` o root), base `hbm_facturador`. No expongas Adminer a Internet sin protección adicional.

**Alternativa:** frontend solo en el host (`cd frontend && npm run dev`) y no levantar el servicio `frontend` en Compose si prefieres un solo proceso Vite.

En la raíz del repo también puedes usar **`.\HBM`** / **`.\HBM.ps1`** / **`HBM.cmd`** (opcional `-Build`). Hay un script de apoyo: `scripts/docker-backend-setup.ps1`.

## Variables de entorno importantes

Ver `backend/.env.example`. En **producción** conviene:

| Variable | Notas |
|----------|--------|
| `APP_ENV=production` | |
| `APP_DEBUG=false` | |
| `APP_KEY` | Generada una vez; no versionar |
| `APP_URL` | URL pública con HTTPS |
| `DB_*` | Credenciales reales |
| `HBM_AUTO_DB_SYNC` | En Docker: `true` ejecuta `migrate --force` al arrancar; `false` lo desactiva |
| `HBM_AUTO_DB_SYNC_RETRIES` | Reintentos si MySQL aún no acepta conexión (por defecto 20) |
| `HBM_AUTO_DB_SYNC_DELAY` | Segundos entre reintentos (por defecto 3) |
| `HBM_AUTO_SEED_CLASS` | Seeder opcional al arrancar (p. ej. `Database\Seeders\EnsureDevLoginSeeder` en dev; vacío o sin definir en producción si no quieres seed automático) |
| `CORS_ALLOWED_ORIGINS` | Origen(es) del frontend |
| `LOG_LEVEL=warning` | Reducir ruido |
| `DB_BACKUP_ENABLED=true` | Activar respaldos automáticos |
| `DB_BACKUP_PATH` | Directorio de salida (por defecto `storage/app/backups`) |
| `MYSQLDUMP_PATH` | Ruta al binario `mysqldump` si no está en el PATH (típico en Windows) |

## Seguridad

- API autenticada con **Laravel Sanctum** (tokens con caducidad configurada en login).
- Consulta pública de facturas: **código de factura + código de verificación** (no solo el código).
- Límite de peticiones: login `throttle:15,1`; rutas autenticadas `throttle:180,1`; rutas públicas de factura `throttle:30,1`.

## Monitorización

- `GET /api/health` — JSON con estado de la aplicación y conexión a base de datos (útil para balanceadores o uptime).
- Laravel ya registra excepciones en los canales configurados en `LOG_CHANNEL` / `LOG_STACK`.

## Cron (producción)

Programar en el servidor:

```bash
* * * * * cd /ruta/al/backend && php artisan schedule:run >> /dev/null 2>&1
```

Incluye: corte de facturación, recordatorios y **respaldo diario de BD** si `DB_BACKUP_ENABLED=true`.

## Respaldo de base de datos

Comando:

```bash
php artisan db:backup
```

Con `DB_BACKUP_ENABLED=false` (por defecto) el comando solo informa; use `--force` para forzar una copia manual.

- **MySQL/MariaDB**: requiere `mysqldump` en el PATH o `MYSQLDUMP_PATH`.
- **SQLite** (archivo): copia el fichero `.sqlite` al directorio de respaldos.

Scripts de referencia: `scripts/backup-mysql.sh` y `scripts/backup-mysql.ps1` (wrapper opcional alrededor de `mysqldump`).

## Pruebas automatizadas

```bash
cd backend
php artisan test
```

Incluye flujos completos (servicio → factura → aprobación → envío → pago → PDF → consulta pública), límites de paginación, permisos por rol y endpoint de salud.

## Mantenimiento

- Actualizar dependencias: `composer update` / `npm update` con revisión de changelog.
- Tras cambios de esquema: `php artisan migrate` (solo aplica migraciones nuevas; **no borra** filas existentes).
- **No uses** en una BD con datos reales: `migrate:fresh`, `migrate:refresh` ni `db:wipe` (recrean o vacían tablas). Con Docker, **no** uses `docker compose down -v` (el `-v` elimina el volumen de MySQL y pierdes todo).
- Revisar logs (`storage/logs`) y espacio en disco de respaldos (`DB_BACKUP_RETAIN_DAYS`).

## Despliegue

Cuando las vistas estén listas: compilar frontend (`npm run build`), servir `public/` del backend y el build de Vite detrás de HTTPS, y aplicar las variables de entorno de producción anteriores.
