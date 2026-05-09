@echo off
REM Crea usuarios demo de desarrollo si la tabla users está vacía (EnsureDevLoginSeeder).
REM Requiere contenedor "laravel" en marcha (docker compose up).
cd /d "%~dp0"
docker compose exec -T laravel php artisan hbm:ensure-dev-users
echo.
pause
