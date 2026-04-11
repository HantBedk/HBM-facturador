#!/bin/sh
set -e
# Vistas compiladas pueden ir a /tmp (VIEW_COMPILED_PATH) para evitar permisos en volumen montado.
mkdir -p /tmp/laravel-views
chmod 1777 /tmp/laravel-views 2>/dev/null || chmod 777 /tmp/laravel-views 2>/dev/null || true
# Laravel necesita escribir en storage (PDF DomPDF, logs, caché, etc.).
# Con volumen montado desde el host (p. ej. Windows + Docker Desktop) el propietario
# puede impedir escritura a www-data; se normalizan permisos al arrancar el contenedor.
if [ -d /var/www/html/storage ]; then
  mkdir -p \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache
  chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
  chmod -R ug+rwx /var/www/html/storage /var/www/html/bootstrap/cache 2>/dev/null || true
  # Si chown falla (algunos montajes NTFS), permitir escritura al grupo/otros en desarrollo.
  chmod -R 777 /var/www/html/storage/framework/views \
    /var/www/html/storage/framework/cache \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache 2>/dev/null || true
fi

# Sin vendor/ Laravel no arranca; el volumen montado puede ser un clone sin dependencias.
if [ -f /var/www/html/composer.json ] && [ ! -f /var/www/html/vendor/autoload.php ]; then
  echo "[entrypoint] vendor/ ausente; ejecutando composer install..."
  composer install --no-interaction --prefer-dist --optimize-autoloader
fi

# Sincroniza esquema al iniciar (idempotente, no destructivo) y permite seeder opcional.
# Variables:
# - HBM_AUTO_DB_SYNC=true|false (default: true)
# - HBM_AUTO_DB_SYNC_RETRIES=<int> (default: 20)
# - HBM_AUTO_DB_SYNC_DELAY=<segundos> (default: 3)
# - HBM_AUTO_SEED_CLASS=<FQCN Seeder> (default: vacío; no seed)
if [ "${HBM_AUTO_DB_SYNC:-true}" = "true" ] && [ -f /var/www/html/artisan ]; then
  retries="${HBM_AUTO_DB_SYNC_RETRIES:-20}"
  delay="${HBM_AUTO_DB_SYNC_DELAY:-3}"
  attempt=1
  synced=0

  while [ "$attempt" -le "$retries" ]; do
    echo "[entrypoint] DB sync intento ${attempt}/${retries}..."
    if php /var/www/html/artisan migrate --force; then
      synced=1
      break
    fi

    echo "[entrypoint] migrate falló; reintentando en ${delay}s..."
    sleep "$delay"
    attempt=$((attempt + 1))
  done

  if [ "$synced" -ne 1 ]; then
    echo "[entrypoint] ERROR: no se pudo aplicar migrate tras ${retries} intentos."
    exit 1
  fi

  if [ -n "${HBM_AUTO_SEED_CLASS:-}" ]; then
    echo "[entrypoint] Ejecutando seeder ${HBM_AUTO_SEED_CLASS}..."
    if ! php /var/www/html/artisan db:seed --class="${HBM_AUTO_SEED_CLASS}" --force; then
      echo "[entrypoint] ADVERTENCIA: el seeder automático falló; php-fpm arranca igual. Ejecute db:seed manualmente si hace falta."
    fi
  fi
fi

exec docker-php-entrypoint "$@"
