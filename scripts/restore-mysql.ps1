<#
.SYNOPSIS
  Restaura un archivo .sql en la base hbm_facturador (sobrescribe datos actuales).

.DESCRIPTION
  Copia el archivo al contenedor hbm_mysql y lo importa con mysql cliente.

.EXAMPLE
  .\scripts\restore-mysql.ps1 -Archivo .\backups\hbm_facturador-20260407-120000.sql
#>
param(
    [Parameter(Mandatory = $true)]
    [string]$Archivo
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "Docker no está en el PATH."
}

$running = docker ps -q -f "name=hbm_mysql" 2>$null
if (-not $running) {
    Write-Error "No hay contenedor hbm_mysql en ejecución. Ejecuta: docker compose up -d"
}

if (-not (Test-Path -LiteralPath $Archivo)) {
    Write-Error "No existe el archivo: $Archivo"
}
$Archivo = (Resolve-Path -LiteralPath $Archivo).Path

Write-Host "Restaurando en hbm_facturador (se reemplazan los datos actuales)..." -ForegroundColor Yellow
docker cp $Archivo hbm_mysql:/tmp/hbm_restore.sql
docker exec hbm_mysql sh -c "mysql -uhbm -phbm_secret hbm_facturador < /tmp/hbm_restore.sql"
if ($LASTEXITCODE -ne 0) {
    Write-Error "Falló la importación (mysql)."
}

Write-Host "Restauración completada." -ForegroundColor Green
