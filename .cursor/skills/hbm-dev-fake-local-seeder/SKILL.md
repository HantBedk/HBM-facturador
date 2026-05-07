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
6. **Inventario:** lotes con SKU bajo prefijo acordado (p. ej. `FAKE-LOCAL-*`); antes de recrear, borrar en orden hijos (movimientos, líneas de venta/alquiler) para no dejar huérfanos; incluir ventas, alquiler activo, alquiler cerrado con devolución, lotes inactivos/stock cero para filtros UI.
7. **Ajustes (`app_settings`):** si el frontend depende de claves JSON (p. ej. `inventory_locations`), sembrar solo si no existe fila, para no pisar la configuración manual del desarrollador.

## Convenciones de implementación

- **Idempotencia parcial:** inventario fake se puede reciclar borrando por prefijo SKU; servicios/facturas “ancla” usar `updateOrCreate` por `code` / `code` de factura estable (`FAKE-LOCAL-*`, `FAC-FAKE-LOCAL-*`) para no multiplicar basura en cada ejecución.
- **Transacciones:** operaciones que tocan stock + movimientos + líneas en la misma venta/alquiler deben ir en `DB::transaction`.
- **Faker:** locale `es_CO` para coherencia con el resto del proyecto.

## Tras modificar el seeder

- Ejecutar el seeder en local y abrir las vistas admin/empleado afectadas.
- Si existen tests de Feature que dependen de datos concretos, **no** acoplarlos a este seeder; los tests deben seguir usando factories / `RefreshDatabase`.
