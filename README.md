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

### Frontend (Vue + Vite)

```bash
cd frontend
npm ci
npm run dev
```

Por defecto Vite sirve en `http://localhost:5173` y proxifica `/api` al backend (variable `VITE_API_PROXY_TARGET`, p. ej. `http://127.0.0.1:8080`).

### Docker (MySQL + PHP-FPM + Nginx)

```bash
docker compose up -d
```

Backend montado en `./backend`; API en `http://localhost:8080/api`. El frontend se compila aparte (`npm run build`) y puede servirse con Nginx estático o el mismo host cuando definas la vista de producción.

## Variables de entorno importantes

Ver `backend/.env.example`. En **producción** conviene:

| Variable | Notas |
|----------|--------|
| `APP_ENV=production` | |
| `APP_DEBUG=false` | |
| `APP_KEY` | Generada una vez; no versionar |
| `APP_URL` | URL pública con HTTPS |
| `DB_*` | Credenciales reales |
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
- Arranque local (PowerShell, carpeta raíz del repo): **`.\HBM`** (archivo sin extensión que llama a `HBM.ps1`). Alternativas: `.\HBM.ps1` o `HBM.cmd`. Opcional `-Build`.
- Revisar logs (`storage/logs`) y espacio en disco de respaldos (`DB_BACKUP_RETAIN_DAYS`).

## Despliegue

Cuando las vistas estén listas: compilar frontend (`npm run build`), servir `public/` del backend y el build de Vite detrás de HTTPS, y aplicar las variables de entorno de producción anteriores.
