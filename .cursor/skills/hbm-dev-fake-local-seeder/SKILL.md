---
name: hbm-dev-fake-local-seeder
description: >-
  Mantiene el seeder local `DevFakeDataLocalSeeder` de HBM-facturador con datos
  ficticios completos para pruebas manuales (usuarios, empresas, servicios,
  catálogo con IVA, facturas en varios estados, pagos, inventario FAKE-LOCAL-*).
  Usar cuando el usuario pida poblar datos de demo local, actualizar el seeder
  de pruebas, o tras añadir un módulo/API que requiera filas relacionadas para
  el frontend. El archivo del seeder está en `.gitignore` y no debe subirse a GitHub.
---

# Seeder local de datos ficticios (HBM facturador)

## Alcance

- **Inventario interno versionado (Git):** `InventoryInternalDemoSeeder` + trait `Concerns/SeedsInventoryInternalDemo.php`. Se ejecuta al correr `DatabaseSeeder`; también: `php artisan db:seed --class=Database\\Seeders\\InventoryInternalDemoSeeder`.
- **Custodia empresa + servicios con lote (Git):** `CompanyCustodyAndMaintenanceDemoSeeder` — lotes `FAKE-TENANT-DEMO-*` en custodia para **Ferretería SYF** (`factura_sigla` SYF) y servicios `DEMO-MAINT-SYF-01` (mantenimiento) / `DEMO-SVC-LOT-SYF-01` (servicio) enlazados a `FAKE-LOCAL-001`. Correo SYF para OTP del portal: `portal-inventario-syf@hbm.local`; NIT normalizado para pruebas manuales: `9001112223` (equivale a `900111222-3`).
- **Dataset local opcional (Gitignore):** `DevFakeDataLocalSeeder.php` — servicios, facturas y demás; reutiliza el mismo trait para inventario.
- **Ejecución típica del seeder local completo:** desde `backend/`: `php artisan db:seed --class=Database\\Seeders\\DevFakeDataLocalSeeder`
- **Prerrequisito:** esquema migrado; conviene haber corrido antes `DatabaseSeeder` o `EnsureDevLoginSeeder` para compañías/usuarios base si hacen falta en el entorno.

## Reglas de seguridad de datos

- Respetar las reglas del proyecto: no ejecutar `migrate:fresh`, `db:wipe` ni comandos que borren volúmenes MySQL sin confirmación explícita del usuario.
- Datos solo ficticios; NIT/teléfonos/correos con sufijos `@hbm.local` / `fake` / `LOCAL`.

## Checklist: qué debe cubrir el seeder (actualizar al evolucionar el producto)

Al cambiar modelos, APIs o pantallas, revisar y ampliar el seeder para incluir **al menos un ejemplo** de:

1. **Usuarios:** admin dedicado fake, varios empleados, opcional super_admin fake; contraseñas coherentes con `EnsureDevLoginSeeder` (misma convención que desarrollo).
2. **Empresas:** varias compañías activas con NIT y `factura_sigla` únicos.
3. **Catálogo de servicios (`service_catalog`):** ítems con `iva_percent` y `technician_discount_percent` cuando existan en el esquema; estado activo.
4. **Servicios (`services`):** mezcla empresa / mostrador (`company_id` null); parte de ellos con `catalog_id`; fechas dispersas; algunos con `assignment_status` (`awaiting_completion`, `rejected`) y al menos uno con `technician_paid_at` si el panel lo muestra.
5. **Facturas (`invoices`):** borrador con servicios; aprobada (mostrador); **parcialmente pagada** con fila en `payments`; opcional **enviada** con `sent_at`; totales alineados con la suma de servicios vinculados.
6. **Inventario interno:** lotes `FAKE-LOCAL-*` con columnas de **custodia** (`serial_number`, `asset_type`, `brand`, `model`, `site_label`, fechas de garantía/compra/custodia, `responsible_*`); estados **`lifecycle_status`** (`activo`, `reparacion`, `baja` con `decommission_*`, `vendido` con stock 0); `allow_sale` / `allow_rental` donde aplique; ventas, alquiler activo y cerrado con devolución; **solicitud pendiente** en `inventory_lifecycle_transition_requests` (p. ej. baja sobre un UPS); **adjunto** en `inventory_lot_attachments` con archivo real bajo `storage/app/public` (disk `public`).
7. **Inventario en custodia (tenant):** lotes con `tenant_company_id` = empresa cliente (SKU dedicados, p. ej. `FAKE-TENANT-DEMO-*`), sin precios en API pública; empresa con **`correo`** informado para flujo OTP del portal.
8. **Servicios (`kind`, `inventory_lot_id`):** al menos un **`mantenimiento`** y un **`servicio`** vinculados al mismo lote interno de prueba para listados y formularios.
9. **Catálogo (`service_catalog`):** `iva_percent` y `technician_discount_percent` alineados con facturación y descuentos a técnico.
10. **Ajustes (`app_settings`):** si el frontend depende de claves JSON (p. ej. `inventory_locations`), sembrar solo si no existe fila, para no pisar la configuración manual del desarrollador.

## Convenciones de implementación

- **Idempotencia parcial:** inventario fake se puede reciclar borrando por prefijo SKU; servicios/facturas “ancla” usar `updateOrCreate` por `code` / `code` de factura estable (`FAKE-LOCAL-*`, `FAC-FAKE-LOCAL-*`) para no multiplicar basura en cada ejecución.
- **Transacciones:** operaciones que tocan stock + movimientos + líneas en la misma venta/alquiler deben ir en `DB::transaction`.
- **Faker:** locale `es_CO` para coherencia con el resto del proyecto.

## Tras modificar el seeder

- Ejecutar el seeder en local y abrir las vistas admin/empleado afectadas.
- Si existen tests de Feature que dependen de datos concretos, **no** acoplarlos a este seeder; los tests deben seguir usando factories / `RefreshDatabase`.
