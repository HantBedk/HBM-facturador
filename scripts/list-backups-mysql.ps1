<#
.SYNOPSIS
  Lista los volcados .sql en la carpeta backups (más recientes primero).
#>
$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$Root = Split-Path -Parent $ScriptDir
$OutDir = Join-Path $Root 'backups'
if (-not (Test-Path $OutDir)) {
    Write-Host "Aún no hay carpeta backups. Ejecuta primero .\scripts\backup-mysql.ps1" -ForegroundColor Yellow
    exit 0
}
Get-ChildItem $OutDir -Filter '*.sql' | Sort-Object LastWriteTime -Descending | Format-Table Name, @{L='KB';E={[math]::Round($_.Length/1KB,1)}}, LastWriteTime -AutoSize
