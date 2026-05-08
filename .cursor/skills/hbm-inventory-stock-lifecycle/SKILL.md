---
name: hbm-inventory-stock-lifecycle
description: >-
  Stock y estados de lotes: quantity_available, lifecycle_status (activo,
  vendido, reparacion, baja), venta total → vendido (no confundir con baja por
  daño), units_on_rent desde líneas de alquiler activas, reversión al anular
  venta/alquiler dentro de transacción con lockForUpdate.
---

# Stock y lifecycle (inventario HBM)

## Constantes

- `activo`, `reparacion`, `baja`, `vendido` (venta que agota stock; distinto de baja por inservible).

## Movimientos

- Tipos en `inventory_movements`: venta, alquiler_salida, alquiler_devolucion, venta_reversa, alquiler_reversa.

## Anular venta

1. `DB::transaction` + `lockForUpdate` en lotes y líneas.
2. Sumar cantidades devueltas a `quantity_available`.
3. Si `lifecycle_status === vendido`, restaurar a `activo` y flags de venta según negocio.

## units_on_rent

- Suma `inventory_rental_lines.quantity` donde `returned_at` IS NULL y rental `status = active`.
