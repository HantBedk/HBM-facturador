param(
    [switch]$Build
)

Write-Host "Iniciando contenedores Docker..." -ForegroundColor Cyan
if ($Build) {
    docker compose up -d --build
} else {
    docker compose up -d
}

Write-Host "Esperando MySQL y aplicando migraciones..." -ForegroundColor Cyan
Start-Sleep -Seconds 6
docker compose exec -T laravel php artisan migrate --force
Write-Host "Datos iniciales (usuarios demo, empresas, catálogo)..." -ForegroundColor Cyan
docker compose exec -T laravel php artisan db:seed --force

Write-Host "`nIniciando entorno local de Frontend (Vite)..." -ForegroundColor Cyan
cd frontend
npm install
npm run dev
