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
# -Levantar: solo backend (evita doble Vite en 5173: contenedor frontend + npm run dev en el host).
$composeServices = if ($Levantar) { @('mysql', 'laravel', 'nginx', 'adminer') } else { @() }
if ($Build) {
    if ($composeServices.Count -gt 0) {
        docker compose up -d --build @composeServices
    } else {
        docker compose up -d --build
    }
} else {
    if ($composeServices.Count -gt 0) {
        docker compose up -d @composeServices
    } else {
        docker compose up -d
    }
}

if ($Levantar) {
    Write-Host "Esperando PHP-FPM (migrate/seed en entrypoint puede tardar ~1 min)..." -ForegroundColor Cyan
    $deadline = (Get-Date).AddMinutes(3)
    $healthy = $false
    while ((Get-Date) -lt $deadline) {
        $state = docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' hbm_php 2>$null
        if ($state -eq 'healthy') { $healthy = $true; break }
        Start-Sleep -Seconds 3
    }
    if (-not $healthy) {
        Write-Host "Aviso: hbm_php aún no marca healthy; nginx puede dar 502 unos segundos. Espere o: docker compose ps" -ForegroundColor Yellow
    }
    docker compose up -d nginx 2>$null | Out-Null
    Write-Host "Modo -Levantar: backend en :8080; Vite en el host (:5173). Sin contenedor frontend (evita puerto ocupado)." -ForegroundColor Green
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
