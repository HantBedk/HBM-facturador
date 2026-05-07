---
name: hbm-code-audit-reporter
description: Audita el codigo para detectar errores potenciales, valores hardcodeados, riesgos de seguridad y deuda tecnica, y genera un reporte markdown en archivo con evidencia y propuesta de solucion por prioridad. Usar cuando el usuario pida revisar calidad, bugs o hardcodes antes de continuar.
disable-model-invocation: true
---

# HBM Code Audit Reporter

## Objetivo

Detectar problemas relevantes del sistema y dejar evidencia auditable en un archivo markdown con acciones correctivas concretas.

## Cuándo usar esta skill

Activar cuando el usuario pida:

- buscar errores
- revisar calidad del codigo
- detectar hardcodes
- auditar seguridad basica
- preparar reporte tecnico de hallazgos

## Resultado obligatorio

Crear un reporte en:

- `docs/audits/<yyyy-mm-dd>-code-audit-<scope>.md`

`<scope>` debe representar el area auditada (ejemplo: `backend-invoice`, `frontend-inventory`, `full-project`).

Si `docs/audits` no existe, crearla.

## Flujo de auditoria

1. Definir alcance (backend, frontend o modulo puntual).
2. Recolectar evidencia:
   - diagnosticos (`ReadLints`)
   - patrones con busqueda (`rg`) para hardcodes y anti-patrones
   - revision de archivos clave del dominio
3. Clasificar hallazgos por severidad.
4. Proponer solucion concreta para cada hallazgo.
5. Emitir reporte en markdown.

## Qué buscar (checklist tecnico)

### Errores potenciales

- manejo incompleto de errores
- validaciones faltantes o inconsistentes
- accesos nulos no controlados
- flujos sin transaccion en operaciones multi-tabla

### Hardcodes

- URLs hardcodeadas
- tokens/credenciales embebidas
- rutas absolutas en codigo
- IDs, porcentajes, limites o estados "quemados" sin constante/config
- mensajes de negocio duplicados en multiples archivos

### Seguridad y robustez

- posibles exposiciones de informacion sensible en logs/errores
- falta de sanitizacion de entradas
- ausencia de controles de autorizacion en endpoints sensibles

### Mantenibilidad

- duplicidad de logica (rompe DRY)
- bloques complejos sin encapsular (afecta SOLID/KISS)
- contratos API inconsistentes entre backend y frontend

## Comandos/patrones sugeridos

Usar busquedas dirigidas, por ejemplo:

- `TODO|FIXME|HACK`
- `http://|https://` (para detectar endpoints inline)
- `process\.env|env\(` (verificar uso correcto de configuracion)
- literales repetidos de negocio en controladores/vistas

No reportar como hallazgo algo que ya sea una constante central valida.

## Formato obligatorio del reporte

```markdown
# Auditoria de codigo: <scope>

## 1) Resumen ejecutivo
- Fecha:
- Alcance:
- Total hallazgos:
- Criticos:
- Altos:
- Medios:
- Bajos:

## 2) Hallazgos priorizados
### [CRITICO|ALTO|MEDIO|BAJO] Titulo corto
- Evidencia: `ruta/archivo.ext`
- Problema:
- Riesgo:
- Solucion propuesta:
- Esfuerzo estimado: (S|M|L)

## 3) Hardcodes detectados
- [ ] Item 1 (archivo, valor, recomendacion)
- [ ] Item 2 (archivo, valor, recomendacion)

## 4) Plan de remediacion por fases
### Fase 1 (urgente)
- [ ] ...
### Fase 2 (estabilizacion)
- [ ] ...
### Fase 3 (mejora continua)
- [ ] ...

## 5) Validacion posterior
- [ ] Lints sin errores nuevos
- [ ] Pruebas relevantes ejecutadas
- [ ] Sin secretos/hardcodes criticos pendientes
```

## Reglas de calidad del hallazgo

- Cada hallazgo debe incluir evidencia concreta de archivo.
- No inventar fallos sin evidencia.
- Priorizar impacto real de negocio y seguridad.
- Diferenciar bug confirmado vs riesgo potencial.
- Proponer solucion accionable, no generica.

## Integracion con otras skills

Si el alcance toca dominios especificos, complementar con:

- `hbm-safe-db-ops` para riesgos de BD/migraciones
- `hbm-invoice-flow-guardian` para facturacion
- `hbm-inventory-module-guard` para inventario
- `hbm-module-blueprint-orchestrator` si el resultado deriva en plan de implementacion por fases

## Cierre esperado al usuario

1. Informar ruta del archivo generado.
2. Resumir top 3 hallazgos y accion inmediata sugerida.
3. Indicar si se puede proceder a correccion directa o requiere aprobacion.
