<#
.SYNOPSIS
  Volcado SQL de la base local hbm_facturador (Docker: contenedor hbm_mysql).

.DESCRIPTION
  Genera backups\hbm_facturador-AAAAMMDD-HHmmss.sql
  Requiere: docker compose up (contenedor hbm_mysql en ejecución).

.EXAMPLE
  .\scripts\backup-mysql.ps1
  .\scripts\backup-mysql.ps1 -Salida D:\copias
#>
param(
    [string]$Salida = ''
)

$ErrorActionPreference = 'Stop'
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $ScriptDir

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "Docker no está en el PATH."
}

$running = docker ps -q -f "name=hbm_mysql" 2>$null
if (-not $running) {
    Write-Error "No hay contenedor hbm_mysql en ejecución. Ejecuta: docker compose up -d"
}

$OutDir = if ($Salida) { $Salida } else { Join-Path $Root 'backups' }
if (-not (Test-Path $OutDir)) {
    New-Item -ItemType Directory -Path $OutDir -Force | Out-Null
}

$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$outFile = Join-Path $OutDir "hbm_facturador-$stamp.sql"

docker exec hbm_mysql sh -c "mysqldump -uhbm -phbm_secret --single-transaction --routines --triggers --set-gtid-purged=OFF hbm_facturador 2>/dev/null > /tmp/hbm_dump.sql"
$dumpExit = $LASTEXITCODE
if ($dumpExit -ne 0) {
    Write-Error "mysqldump falló (código $dumpExit)."
}

docker cp "hbm_mysql:/tmp/hbm_dump.sql" $outFile
if (-not (Test-Path $outFile) -or (Get-Item $outFile).Length -lt 50) {
    Write-Error "No se generó un volcado válido en: $outFile"
}

$sizeKb = [math]::Round((Get-Item $outFile).Length / 1KB, 1)
Write-Host "Copia guardada ($sizeKb KB): $outFile" -ForegroundColor Green
Write-Host "Restaurar:" -ForegroundColor Cyan
Write-Host "  .\scripts\restore-mysql.ps1 -Archivo `"$outFile`"" -ForegroundColor Gray
