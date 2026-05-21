@echo off
REM Usuarios demo (si users vacio) + respaldo/restauracion de Empresa del sistema.
REM Si ya registro datos en Configuracion - Empresa del sistema, este comando los conserva en BD
REM y actualiza storage/app/local-dev/empresa-sistema.snapshot.json para el proximo entorno vacio.
REM Requiere contenedor "laravel" en marcha (docker compose up).
cd /d "%~dp0"
docker compose exec -T laravel php artisan hbm:ensure-dev-users
echo.
pause
