---
name: hbm-owner-vs-executor
description: >-
  Inventario interno comercial: el titular económico del lote es owner_user_id
  (admin que registró el equipo). sold_by_user_id / created_by_user_id son solo
  trazabilidad. Los devengos van al titular; el ejecutor no recibe comisión por
  venta/alquiler de inventario.
---

# Titular vs ejecutor (inventario HBM)

## Datos

- `InventoryLot.owner_user_id` → titular.
- `InventorySale.sold_by_user_id`, `InventoryRental.created_by_user_id` → ejecutor.
- `inventory_sale_lines.owner_user_id` snapshot del titular al vender.

## UI

- Banner si ejecutor ≠ titular: la operación no genera comisión para el técnico.

## Dashboard técnico

- Solo sumar `Service` para comisiones/referencias, no `InventorySale`/`InventoryRental`.
