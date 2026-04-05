# Arranque: Docker, hbm:sync (migrate + login demo si users vacío; seeder completo solo si no hay empresas), Vite.
#   .\HBM              → up, hbm:sync, Vite
#   .\HBM -Build       → rebuild imágenes
#   .\HBM -SoloMigrar  → up, hbm:sync sin Vite (no ejecuta DatabaseSeeder completo si ya hay empresas)
#   Resembrar demo completo: docker compose exec laravel php artisan hbm:sync --demo
#   Borrar TODA la base:   docker compose exec laravel php artisan hbm:sync --fresh
# En PowerShell, desde la raiz del repo: .\HBM
param(
    [switch]$Build,
    [switch]$SoloMigrar
)

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Root

Write-Host "Iniciando contenedores Docker..." -ForegroundColor Cyan
if ($Build) {
    docker compose up -d --build
} else {
    docker compose up -d
}

Write-Host "Esperando MySQL (hbm:sync: migrate; seed completo solo si la BD no tiene empresas)..." -ForegroundColor Cyan
Start-Sleep -Seconds 6
docker compose exec -T laravel php artisan hbm:sync --no-interaction

if ($SoloMigrar) {
    Write-Host "hbm:sync completado. Fin (-SoloMigrar)." -ForegroundColor Green
    exit 0
}

Write-Host ""
Write-Host "Iniciando entorno local de Frontend (Vite)..." -ForegroundColor Cyan
Set-Location "$Root\frontend"
npm install
npm run dev
