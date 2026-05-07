---
name: hbm-invoice-flow-guardian
description: Protege cambios del flujo de facturacion en HBM Facturador, asegurando consistencia entre controlador, calculo de totales, recursos API, frontend y pruebas. Usar cuando se modifiquen IVA, lineas, totales, estados o salidas de factura.
disable-model-invocation: true
---

# HBM Invoice Flow Guardian

## Objetivo

Asegurar que cualquier cambio de facturacion mantenga consistencia funcional, contable y contractual entre backend y frontend.

## Archivos foco

- `backend/app/Http/Controllers/Api/AdminInvoiceController.php`
- `backend/app/Support/InvoiceTotalsFromServices.php`
- `backend/app/Http/Resources/AdminInvoiceResource.php`
- `backend/app/Services/AutomaticInvoiceDraftService.php`
- `frontend/src/services/invoicesApi.js`
- `frontend/src/views/admin/InvoiceDetailView.vue`
- `backend/tests/Feature/AdminInvoiceApiTest.php`
- `backend/tests/Unit/InvoiceTotalsFromServicesTest.php`

## Flujo de trabajo recomendado

1. Mapear impacto del cambio:
   - entrada valida (request)
   - reglas de negocio (totales, impuestos, estados)
   - salida API (`Resource`)
   - consumo en frontend
2. Mantener una sola fuente de verdad para calculo de totales (evitar duplicidad).
3. Preservar contrato API o versionarlo si cambia forma de respuesta.
4. Actualizar pruebas de unidad y feature en el mismo cambio.
5. Verificar casos limite: redondeo, IVA cero, listas vacias, descuentos, anulaciones.

## Reglas de calidad

- DRY/KISS: no replicar logica de totales en controlador y vista.
- Manejo seguro de errores: mensajes controlados, sin filtrar stack sensible.
- Consistencia ACID: si una operacion de factura toca multiples tablas, envolver en transaccion.
- Principios SOLID: separar orquestacion (controller) de calculo (support/service).

## Disparadores de uso

- "ajusta IVA"
- "cambia el total"
- "agrega campo a factura"
- "corrige draft/final"
- "no cuadra frontend vs backend"

## Salida esperada al usuario

- Lista de capas impactadas y cambios aplicados.
- Confirmacion de contrato API y pruebas ajustadas.
- Riesgos residuales y pruebas manuales sugeridas.
