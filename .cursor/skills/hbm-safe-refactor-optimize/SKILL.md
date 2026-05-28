---
name: hbm-safe-refactor-optimize
description: >-
  Reduce tamaño y duplicidad en el frontend (y ocasionalmente PHP) de HBM-facturador
  extrayendo lógica pura, sin cambiar contratos API ni comportamiento observable. Usar
  cuando el usuario pida optimizar, refactorizar vistas grandes, reducir líneas o
  eliminar código duplicado de forma segura.
---

# Refactor seguro / optimización (HBM)

## Objetivo

Menos líneas y mejor mantenibilidad **sin** cambiar flujos, textos de UI salvo equivalentes, ni peticiones al backend.

## Inventario rápido (cuándo empezar)

En PowerShell desde la raíz del repo:

```powershell
Get-ChildItem -Recurse frontend\src -Include *.vue,*.js -File |
  Where-Object { $_.FullName -notmatch 'node_modules|dist' } |
  ForEach-Object {
    $n = (Get-Content $_.FullName | Measure-Object -Line).Lines
    [pscustomobject]@{ Lines = $n; Path = $_.FullName }
  } | Where-Object { $_.Lines -ge 500 } | Sort-Object Lines -Descending | Select-Object -First 30
```

Umbral orientativo: **≥500 líneas** en un `.vue` o script largo = candidato. Backend: controladores **≥400 líneas** = candidato a extraer a Action/Service (fuera del alcance mínimo de esta skill si no hay tests).

## Orden de intervención (del más seguro al más arriesgado)

1. **Funciones y constantes puras** → `*Helpers.js` junto a la vista, o `@/utils/*.js` si se repiten en 2+ módulos (formato COP entero, fechas CO, etiquetas de estado).
2. **Composables** (`useThing.js`) → estado + efectos compartidos, sin tocar plantilla.
3. **Subcomponentes** → solo con props/emits claros y prueba manual o E2E; mayor riesgo de regresión visual.

## Convenciones ya usadas en el repo

| Área | Ejemplos |
|------|----------|
| Inventario | `frontend/src/views/inventory/inventoryViewHelpers.js` |
| Empresas | `frontend/src/views/admin/companiesListHelpers.js` |
| Servicios listado | `frontend/src/views/services/servicesListHelpers.js` |
| Empleados | `frontend/src/views/admin/empleadosListHelpers.js` |
| Consulta pública factura | `frontend/src/views/public/publicInvoiceConsultHelpers.js` |
| Facturas admin (listado) | `frontend/src/views/admin/invoicesListHelpers.js` |
| Detalle servicio | `frontend/src/views/services/serviceDetailHelpers.js` |
| COP entero «—» si NaN | `frontend/src/utils/moneyFormatCo.js` (`moneyCOPIntegerOrDash`) |

Reutilizar helpers existentes antes de duplicar (p. ej. `formatDate` admin entre `companiesListHelpers` y `EmpleadosListView`).

## Reglas de seguridad

- **No** cambiar URLs de API, payloads ni interpretación de errores sin revisar tests y flujo manual.
- **No** `migrate:fresh`, `db:wipe` ni volúmenes Docker destructivos (ver regla `hbm-database-safety`).
- Mantener **multi-tenant** y portal OTP: no debilitar filtros ni mensajes genéricos en errores (skills `hbm-multitenant-scoping`, `hbm-public-portal-otp`).
- Tras el cambio: **`npm run build`** en `frontend/`; si tocó PHP, **`php artisan test`** (o el subconjunto de tests del módulo).

## Lista de candidatos frecuentes (actualizar al descubrir nuevos)

**Frontend (>500 líneas):** `InventoryView.vue`, `CompaniesListView.vue`, `ServicesListView.vue`, `EmpleadosListView.vue`, `PublicInvoiceConsultView.vue`, `ServiceRegisterEmpleadoForm.vue`, `ServiceDetailView.vue`, `InvoicesListView.vue`, `EmployeeHistorialView.vue`, `AdminInvoiceDetailPanel.vue`, `AdminBillingAutomationView.vue`, `ServiceCatalogView.vue`, `InvoiceDetailView.vue`, `useServiceRegisterFlow.js`.

**Backend PHP (>400 líneas en app):** `ServiceController.php`, `AdminInvoiceController.php`, `InventoryLotController.php`, importers en `app/Services/`.

## Checklist antes de cerrar

- [ ] Diff mínimo: solo lo necesario para la extracción.
- [ ] Nombres exportados claros; sin sombrear imports.
- [ ] `npm run build` OK (frontend).
- [ ] Sin nuevos secretos ni datos sensibles en logs.
