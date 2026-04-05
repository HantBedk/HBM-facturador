# Arranque completo: Docker, migrate, seed, Vite. Opcion: -Build
# En PowerShell, desde la raiz del repo: .\HBM
param(
    [switch]$Build
)

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Root

Write-Host "Iniciando contenedores Docker..." -ForegroundColor Cyan
if ($Build) {
    docker compose up -d --build
} else {
    docker compose up -d
}

Write-Host "Esperando MySQL y aplicando migraciones..." -ForegroundColor Cyan
Start-Sleep -Seconds 6
docker compose exec -T laravel php artisan migrate --force

Write-Host "Datos iniciales (usuarios demo, empresas, catalogo)..." -ForegroundColor Cyan
docker compose exec -T laravel php artisan db:seed --force

Write-Host ""
Write-Host "Iniciando entorno local de Frontend (Vite)..." -ForegroundColor Cyan
Set-Location "$Root\frontend"
npm install
npm run dev
