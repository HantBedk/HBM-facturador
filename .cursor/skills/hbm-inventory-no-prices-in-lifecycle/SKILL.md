---
name: hbm-inventory-no-prices-in-lifecycle
description: >-
  La hoja de vida pública y eventos de custodia no almacenan precios ni montos;
  los costos facturables viven en Service/Invoice. Los eventos referencian
  service_id opcionalmente sin duplicar importes en texto libre.
---

# Sin precios en hoja de vida (HBM)

## Reglas

1. Tablas `inventory_lifecycle_events` / timeline: campos tipo, fecha, nota, próximo_mantenimiento; **sin** columnas de dinero.
2. Mantenimiento facturable: precio en `services.amount` / ítems; evento de vida enlaza `service_id`.
3. Portal empresa cliente: filtrar cualquier campo monetario en serializers públicos.
