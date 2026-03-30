param(
    [switch]$Build
)

Write-Host "Iniciando contenedores Docker..." -ForegroundColor Cyan
if ($Build) {
    docker-compose up -d --build
} else {
    docker-compose up -d
}

Write-Host "`nIniciando entorno local de Frontend (Vite)..." -ForegroundColor Cyan
cd frontend
npm install
npm run dev
