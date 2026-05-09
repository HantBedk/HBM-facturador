# Arranque del proyecto HBM.
#
# En Windows: NO hagas doble clic en este .ps1 (sale "Abrir con"). Usa:
#   - Terminal:  .\HBM.ps1 -Levantar
#   - O:         HBM.cmd -Levantar
#   - O doble clic: HBM-Levantar.cmd
#
# RECOMENDADO día a día (no toca la BD, no seeders, no migraciones):
#   .\HBM.ps1 -Levantar     → Docker + Vite solamente
#
# Otros:
#   .\HBM.ps1               → Docker + hbm:sync (migrate + seeds si no hay empresas) + Vite
#   .\HBM.ps1 -Build        → igual que arriba pero rebuild de imágenes
#   .\HBM.ps1 -SoloMigrar   → Docker + solo migrate (sin seeders), sin Vite
#   .\HBM.ps1 -SoloMigra    → alias de -SoloMigrar
#
# Tabla users vacía (sin login): doble clic HBM-UsuariosDev.cmd o:
#   docker compose exec -T laravel php artisan hbm:ensure-dev-users
#
# Tras git pull con migraciones nuevas (solo esquema, sin resembrar):
#   docker compose exec laravel php artisan hbm:sync --migrate-only
#   # o: docker compose exec laravel php artisan migrate --force
#
#   hbm:sync sin --migrate-only: si NO hay empresas, corre DatabaseSeeder completo (demo).
#   Resembrar demo:  docker compose exec laravel php artisan hbm:sync --demo
#   Borrar toda la BD: docker compose exec laravel php artisan hbm:sync --fresh
#
# Desde la raíz del repo: .\HBM.ps1 ...
param(
    [switch]$Build,
    [Alias('SoloMigra')]
    [switch]$SoloMigrar,
    [switch]$Levantar
)

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
Set-Location $Root

Write-Host "Iniciando contenedores Docker..." -ForegroundColor Cyan
if ($Build) {
    docker compose up -d --build
} else {
    docker compose up -d
}

if ($Levantar) {
    Start-Sleep -Seconds 3
    Write-Host "Modo -Levantar: sin migraciones ni seeders (BD intacta)." -ForegroundColor Green
} elseif ($SoloMigrar) {
    Write-Host "Esperando MySQL (solo migraciones, sin seeders)..." -ForegroundColor Cyan
    Start-Sleep -Seconds 6
    docker compose exec -T laravel php artisan hbm:sync --no-interaction --migrate-only
    Write-Host "Migraciones aplicadas. Fin (-SoloMigrar / -SoloMigra)." -ForegroundColor Green
    exit 0
} else {
    Write-Host "Esperando MySQL (hbm:sync: migrate + seeds si aplica)..." -ForegroundColor Cyan
    Start-Sleep -Seconds 6
    docker compose exec -T laravel php artisan hbm:sync --no-interaction
}

Write-Host ""
Write-Host "Iniciando entorno local de Frontend (Vite)..." -ForegroundColor Cyan
Set-Location "$Root\frontend"
npm install
npm run dev
