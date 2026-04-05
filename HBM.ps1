# Arranque completo: Docker, hbm:sync (migrate+seed), Vite.
#   .\HBM              → up, php artisan hbm:sync, Vite
#   .\HBM -Build       → rebuild imágenes
#   .\HBM -SoloMigrar  → up, hbm:sync sin Vite (migrate + seed; evita BD sin usuarios)
#   migrate:fresh+datos: docker compose exec laravel php artisan hbm:sync --fresh
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

Write-Host "Esperando MySQL: migraciones + datos iniciales (hbm:sync)..." -ForegroundColor Cyan
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
