---
name: hbm-multitenant-scoping
description: >-
  Aislamiento multiempresa en HBM-facturador: todo query que exponga datos de
  cliente debe filtrar por company_id / tenant_company_id validado en backend,
  nunca confiar solo en el frontend. Usar en inventario por empresa, portal OTP,
  mantenimientos y exports.
---

# Multi-tenant scoping (HBM)

## Reglas

1. **Validar contexto en el controlador**: `tenant_company_id` del request debe coincidir con el recurso (`InventoryLot::tenant_company_id`, `Service::company_id`).
2. **Sin listados cruzados**: empleados solo ven empresas/equipos autorizados (política por rol documentada en controlador).
3. **Tests**: dos empresas A/B; usuario de A no puede `GET/PATCH` recurso de B (403 o 404).

## Patrón Eloquent

```php
$query->when($companyId, fn ($q, $id) => $q->where('tenant_company_id', $id));
```

## Anti-patrones

- Confiar en `company_id` solo en query string sin verificar pertenencia del lote.
- Usar `find($id)` sin scope de empresa en rutas de cliente.
