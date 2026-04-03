# Respaldo MySQL (requiere mysqldump en PATH o ruta completa en $env:MYSQLDUMP)
param(
    [string]$OutDir = ".\backups"
)
$ErrorActionPreference = "Stop"
New-Item -ItemType Directory -Force -Path $OutDir | Out-Null
$stamp = Get-Date -Format "yyyyMMdd-HHmmss"
$host = if ($env:MYSQL_HOST) { $env:MYSQL_HOST } else { "127.0.0.1" }
$port = if ($env:MYSQL_PORT) { $env:MYSQL_PORT } else { "3306" }
$user = if ($env:MYSQL_USER) { $env:MYSQL_USER } else { "hbm" }
$db = if ($env:MYSQL_DATABASE) { $env:MYSQL_DATABASE } else { "hbm_facturador" }
$bin = if ($env:MYSQLDUMP) { $env:MYSQLDUMP } else { "mysqldump" }
$f = Join-Path $OutDir "hbm-$stamp.sql"
& $bin -h $host -P $port -u $user -p$env:MYSQL_PASSWORD --single-transaction --no-tablespaces $db | Set-Content -Encoding utf8 $f
Write-Host "OK: $f"
