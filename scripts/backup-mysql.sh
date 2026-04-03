#!/usr/bin/env bash
# Respaldo MySQL usando mysqldump. Ajuste usuario/host según su entorno.
set -euo pipefail
OUT_DIR="${1:-./backups}"
mkdir -p "$OUT_DIR"
STAMP=$(date +%Y%m%d-%H%M%S)
: "${MYSQL_HOST:=127.0.0.1}"
: "${MYSQL_PORT:=3306}"
: "${MYSQL_USER:=hbm}"
: "${MYSQL_DATABASE:=hbm_facturador}"
F="$OUT_DIR/hbm-${STAMP}.sql"
mysqldump -h "$MYSQL_HOST" -P "$MYSQL_PORT" -u "$MYSQL_USER" -p"$MYSQL_PASSWORD" \
  --single-transaction --no-tablespaces "$MYSQL_DATABASE" > "$F"
echo "OK: $F"
