---
name: hbm-inventory-module-guard
description: Guía cambios seguros del modulo de inventario en HBM Facturador, validando stock, movimientos, ventas y liquidaciones con enfoque ACID y trazabilidad. Usar cuando se editen lotes, ventas, accruals o movimientos de inventario.
disable-model-invocation: true
---

# HBM Inventory Module Guard

## Objetivo

Reducir regresiones de inventario garantizando consistencia de stock, trazabilidad de movimientos y salidas API coherentes.

## Archivos foco

- `backend/app/Http/Controllers/Api/InventoryLotController.php`
- `backend/app/Http/Controllers/Api/InventorySaleController.php`
- `backend/app/Http/Controllers/Api/InventoryOwnerAccrualController.php`
- `backend/app/Models/InventoryLot.php`
- `backend/app/Models/InventoryMovement.php`
- `backend/app/Models/InventorySale.php`
- `backend/app/Models/InventorySaleLine.php`
- `backend/app/Http/Resources/InventoryLotResource.php`
- `backend/app/Http/Resources/InventorySaleResource.php`
- `frontend/src/services/inventoryApi.js`
- `frontend/src/views/inventory/InventoryView.vue`
- `backend/tests/Feature/InventoryModuleTest.php`

## Flujo de trabajo recomendado

1. Identificar el evento de dominio: alta de lote, venta, ajuste, liquidacion.
2. Definir invariantes antes y despues:
   - stock nunca negativo
   - sumatoria de movimientos consistente con saldo final
   - relacion venta-lineas valida
3. En operaciones concurrentes, usar transacciones y locking apropiado (`lockForUpdate` cuando aplique).
4. Persistir movimiento de inventario como evidencia de cambio (auditable).
5. Sincronizar response resources y consumo frontend.
6. Cubrir con pruebas feature los caminos feliz y de error.

## Reglas de consistencia y seguridad

- ACID: toda mutacion multi-tabla debe ser atomica.
- No ocultar errores de integridad; devolver respuesta clara y segura.
- No exponer datos sensibles o internos en mensajes de error.
- Mantener POO/SOLID: servicios o soporte para reglas complejas, no controladores monoliticos.

## Disparadores de uso

- "registrar venta de inventario"
- "ajuste de stock"
- "lotes"
- "accrual por propietario"
- "inconsistencia de existencias"

## Salida esperada al usuario

- Invariantes verificados antes/despues.
- Evidencia de transaccion/locking cuando aplique.
- Contrato API y pruebas actualizadas.
