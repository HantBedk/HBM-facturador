# Requiere Docker Desktop en ejecución.
# Uso: desde la raíz del repo: powershell -ExecutionPolicy Bypass -File .\scripts\docker-backend-setup.ps1

$ErrorActionPreference = "Stop"
Set-Location (Split-Path -Parent $PSScriptRoot)

Write-Host "Construyendo imágenes..." -ForegroundColor Cyan
docker compose build laravel nginx

Write-Host "Instalando dependencias PHP (composer install)..." -ForegroundColor Cyan
docker compose run --rm laravel composer install --no-interaction

Write-Host "Esperando a MySQL..." -ForegroundColor Cyan
docker compose up -d mysql
Start-Sleep -Seconds 12

Write-Host "Migrando esquema (sin borrar datos si la BD ya existía)..." -ForegroundColor Cyan
docker compose run --rm laravel php artisan migrate --force

Write-Host "Primera vez en esta BD: ejecuta una sola vez el seed (usuarios/catálogo demo):" -ForegroundColor Yellow
Write-Host "  docker compose exec laravel php artisan db:seed --force" -ForegroundColor Gray

Write-Host "Listo. Levanta el stack con: docker compose up -d" -ForegroundColor Green
Write-Host "API: http://localhost:8080  |  Frontend: http://localhost:5173  |  Adminer: http://localhost:8081" -ForegroundColor Green
Write-Host "Usuarios seed: admin@hbm.local / password | empleado@hbm.local / password" -ForegroundColor Green
