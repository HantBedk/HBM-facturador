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
exec docker-php-entrypoint "$@"
