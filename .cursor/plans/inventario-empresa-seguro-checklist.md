# Checklist: inventario empresa / portal / plan maestro

Referencia única para tachar avance (el doc corto `plan-inventario-mantenimientos.md` no lista estos ítems).

| # | Ítem | Estado |
|---|------|--------|
| 1 | Columnas dominio en `inventory_lots` + modelo + API admin | Hecho |
| 2 | Filtros listado (`asset_type`, `brand`, `q`, orden) + URL en UI empresa | Hecho |
| 3 | Import CSV lotes custodia (`dry_run` / aplicar) + tests | Hecho |
| 4 | Semáforo garantía en recurso, PDF y tests `show` | Hecho |
| 5 | Export CSV servicios (`kind`, columnas inventario, `q`) + tests | Hecho |
| 6 | Portal público JSON alineado a custodia (serial, descripción, fechas, responsable, garantía) + tests | Hecho |
| 7 | `q` en **GET `/api/services`** y **export admin** también busque por datos del **lote** enlazado | Hecho |
| 8 | Pruebas import CSV: duplicado serial/MAC, cantidad fuera de rango, archivo vacío, solo cabecera | Hecho |
| 9 | Correo producción: `MAIL_*` + Brevo documentado en `.env.example`; verificación con `php artisan hbm:mail-test email@dominio.com` | Hecho (ops: credenciales reales en `.env` del servidor) |
|10 | UX inventario: controles táctiles import CSV; adjuntos por evento (`inventory_audit_event_id`) + subida múltiple en ficha activo | Hecho |
|11 | Cron dedicado inventario / permisos finos extra | Pendiente (definir si aplica; facturación ya tiene `schedule` en `bootstrap/app.php`) |

**Anulación venta/alquiler y stock:** ya existe reversión en backend (`InventorySaleController` / `InventoryRentalController`); si el plan decía «0.5» como caso distinto (p. ej. reversa parcial por línea), hay que **definir el requisito** en un ticket.

**Última revisión:** 2026-05-10 — ítems 9–10 cerrados en código (comando correo, migración adjuntos↔auditoría, API, UI, tests).
