# Plan: Inventario empresa + mantenimientos facturables

Última actualización: 2026-05-10.

## Por qué parece que «no avanzamos»

- Los ítems del **plan maestro** estaban resumidos en **una sola línea** abajo y el path `.cursor/plans/inventario-empresa-seguro_…` **no existía** en el repo: no había checklist de 10 filas que tachar.
- El trabajo sí avanzó en código; faltaba **visibilidad**: ahora hay **`.cursor/plans/inventario-empresa-seguro-checklist.md`** con tabla numerada (hecho / pendiente).

## Completado

### Inventario (custodia / interno)

- Dominio por empresa: `tenant_company_id`, titular vs ejecutor (skills `hbm-owner-vs-executor`, `hbm-multitenant-scoping`).
- Stock y ciclo de vida: disponible, vendido, reparación, baja; `units_on_rent`; reversión en venta/alquiler (`hbm-inventory-stock-lifecycle`).
- API listado con filtros (`q`, fechas, orden), movimientos, adjuntos por lote, PDF hoja de vida (sin precios en custodia; `hbm-inventory-no-prices-in-lifecycle`).
- Portal público inventario empresa: NIT + OTP (`hbm-public-portal-otp`).
- Técnicos: lectura de custodia con `for_maintenance=1` + `tenant_company_id` (detalle, movimientos, PDF, adjuntos; mutación adjuntos sigue siendo titular/admin).
- **SKU retirado de producto en UX y JSON:** columna BD `sku` sigue como referencia interna oculta; API expone `internal_code` (helper `InventoryLotInternalCode`, modelo `InventoryLot` con `$hidden`/`internal_code`).
- Docker: `storage:link --force` en entrypoint PHP.

### Mantenimientos facturables

- Modelo/API servicio: `kind` = `servicio` | `mantenimiento`, `inventory_lot_id`, validación empresa ↔ lote custodia; auditoría de vínculo en hoja de vida.
- Rutas UI: `/admin/mantenimientos`, `/empleado/mantenimientos`, registro dedicado, listados filtrables por `kind`.
- Flujo registro: empresa obligatoria, equipo de inventario obligatorio; tests `ServiceMaintenanceTest`.
- **Desde hoja de vida:** botón «Registrar mantenimiento» → registro con query `company_id` + `inventory_lot_id` (precarga en `useServiceRegisterFlow`).
- **Detalle de servicio:** bloque «Equipo (inventario)» + enlace a ficha del activo; API `show` carga `inventoryLot` y devuelve `inventory_lot` en `ServiceResource`.
- **Técnico en ficha de activo:** `?tenant_company_id=` en URL para API de custodia al abrir desde el servicio.
- Tras crear mantenimiento (vista completa): redirect a listado de mantenimientos (admin/empleado), no al listado SAV genérico.
- **Login:** enlace «Consultar inventario» bajo «Consultar factura» (`/consulta-empresa`).

### Correcciones de calidad

- `validateBeforeSubmit` en `useServiceRegisterFlow`: orden de definición de `rk` (bug que rompía validación).

## Qué sigue (prioridad sugerida)

1. ~~**Listados:** columna «Equipo» en `ServicesListView` (listado mantenimientos o filtro tipo Mantenimiento); API índice con `inventoryLot` cargado; enlace a hoja de vida.~~ **Hecho.**
2. ~~**Panel admin de registro:** redirect a listados de mantenimientos tras alta desde panel.~~ **Hecho.**
3. ~~**Pruebas:** `inventory_lot` en show e índice filtrado; `Cache-Control` en catálogo activo (orden de directivas).~~ **Hecho.**
4. ~~**Facturación:** desde detalle admin de mantenimiento → `POST /admin/invoices` (borrador periodo = mes de `service_date`) + test `ServiceMaintenanceTest::test_admin_can_create_invoice_borrador_from_maintenance_service`.~~ **Hecho.**
5. ~~**Export CSV servicios:** columnas `Clase registro`, `ID activo inventario`, equipo (nombre + código interno); filtros opcionales `kind` y `q` alineados al listado admin.~~ **Hecho.**

## Qué sigue (fuera de este doc corto)

- **Checklist explícito (10 filas):** [`.cursor/plans/inventario-empresa-seguro-checklist.md`](plans/inventario-empresa-seguro-checklist.md) — allí están dominio, filtros, import, export, portal, pendientes (`q`+lote en servicios, más tests import, Brevo prod, backlog UX).
- **Pruebas** export/import internos + garantía en `show`: ya cubiertas en `ServiceMaintenanceTest` e `InventoryModuleTest`. Portal público: `PublicCompanyInventoryTest` (incl. custodia en JSON y semáforo garantía).

## Referencias útiles

- Skills: `.cursor/skills/hbm-multitenant-scoping`, `hbm-public-portal-otp`, `hbm-owner-vs-executor`, `hbm-inventory-no-prices-in-lifecycle`, `hbm-inventory-stock-lifecycle`.
- Reglas: `.cursor/rules/hbm-database-safety.mdc`, `hbm-skills-first.mdc`.
