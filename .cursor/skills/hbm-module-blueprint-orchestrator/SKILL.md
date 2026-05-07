---
name: hbm-module-blueprint-orchestrator
description: Convierte una idea funcional del usuario en un blueprint markdown con checklist por fases para crear modulos o vistas, asignando skills existentes por etapa y definiendo responsabilidades concretas de cada skill. Usar cuando el usuario pida planificar implementacion integral antes de codificar.
disable-model-invocation: true
---

# HBM Module Blueprint Orchestrator

## Objetivo

Transformar la idea del usuario en un documento markdown ejecutable, auditable y chuleable para construir un modulo o vista de principio a fin.

## Cuándo usar esta skill

Activar cuando el usuario diga o implique:

- "crea un nuevo modulo"
- "hagamos una vista nueva"
- "te paso la idea completa"
- "primero planifiquemos todo"
- "quiero checklist por fases"

## Resultado obligatorio

Generar un archivo markdown en el repositorio con:

1. alcance funcional
2. fases de implementacion
3. checklist chuleable por tareas
4. skills a usar en cada fase
5. responsabilidades de cada skill en esa fase
6. criterios de aceptacion y pruebas
7. riesgos y controles (seguridad, ACID, contratos API)

No limitarse a responder en chat cuando el usuario pida el documento.

## Ubicación y nombre del documento

Guardar el plan en una ruta consistente y visible para el equipo:

- `docs/blueprints/<yyyy-mm-dd>-<slug-modulo>.md`

Si `docs/blueprints` no existe, crearlo.

`<slug-modulo>` debe ser corto y kebab-case.

## Flujo de trabajo

1. Extraer objetivos, actores, reglas de negocio y restricciones de la idea del usuario.
2. Delimitar impacto por capas:
   - backend (modelo, migracion, controlador, resource, servicio)
   - frontend (servicio API, vista, componentes, router)
   - pruebas (feature/unit/integracion)
3. Definir fases con dependencias claras y entregables verificables.
4. Asignar skills existentes por fase y explicar exactamente como participan.
5. Redactar checklist chuleable por tarea.
6. Incluir comandos de verificacion y criterios de cierre.

## Skills base a orquestar

Cuando existan en el proyecto, mapear explicitamente:

- `hbm-safe-db-ops`
- `hbm-invoice-flow-guardian` (si el modulo toca facturacion)
- `hbm-inventory-module-guard` (si el modulo toca inventario)
- skills built-in segun necesidad (`babysit`, `split-to-prs`, `create-hook`, `create-rule`)

## Regla clave de orquestacion

Por cada fase, incluir una subseccion:

- "Skills aplicadas en esta fase"
- para cada skill: objetivo, entradas esperadas, acciones concretas y salida esperada

Evitar asignaciones vagas tipo "usar skill X"; debe quedar operativo.

## Plantilla obligatoria del markdown

Usar esta estructura base:

```markdown
# Blueprint: <Nombre del modulo/vista>

## 1) Contexto y objetivo
- Problema que resuelve:
- Usuarios/roles involucrados:
- Restricciones tecnicas:

## 2) Alcance funcional
### Incluye
- [ ] ...
### No incluye
- [ ] ...

## 3) Impacto tecnico por capa
### Backend
- [ ] Modelos:
- [ ] Migraciones:
- [ ] Controllers/Services:
- [ ] Resources/validacion:

### Frontend
- [ ] Servicios API:
- [ ] Vistas/componentes:
- [ ] Rutas/navegacion:

### Datos y consistencia
- [ ] Reglas ACID relevantes:
- [ ] Riesgos de concurrencia:

## 4) Fases de implementacion
## Fase 0 - Alineacion y contrato
### Checklist
- [ ] Definir payload de entrada/salida
- [ ] Confirmar errores esperados (4xx/5xx)
### Skills aplicadas en esta fase
- **<skill>**
  - Objetivo:
  - Entradas:
  - Acciones:
  - Salida:

## Fase 1 - Base de datos y dominio
### Checklist
- [ ] ...
### Skills aplicadas en esta fase
- **hbm-safe-db-ops**
  - Objetivo:
  - Entradas:
  - Acciones:
  - Salida:

## Fase 2 - API y reglas de negocio
### Checklist
- [ ] ...
### Skills aplicadas en esta fase
- **<skill dominio>**
  - Objetivo:
  - Entradas:
  - Acciones:
  - Salida:

## Fase 3 - Frontend y UX
### Checklist
- [ ] ...
### Skills aplicadas en esta fase
- **<skill>**
  - Objetivo:
  - Entradas:
  - Acciones:
  - Salida:

## Fase 4 - Pruebas y validacion
### Checklist
- [ ] Pruebas unitarias
- [ ] Pruebas feature/integracion
- [ ] Pruebas manuales guiadas
### Skills aplicadas en esta fase
- **<skill>**
  - Objetivo:
  - Entradas:
  - Acciones:
  - Salida:

## Fase 5 - PR y despliegue controlado
### Checklist
- [ ] Split de cambios (si aplica)
- [ ] Evidencia de pruebas
- [ ] Riesgos y rollback
### Skills aplicadas en esta fase
- **split-to-prs / babysit (si aplica)**
  - Objetivo:
  - Entradas:
  - Acciones:
  - Salida:

## 5) Criterios de aceptacion
- [ ] Criterio 1
- [ ] Criterio 2

## 6) Plan de pruebas
- [ ] Comandos backend:
- [ ] Casos frontend:
- [ ] Casos de borde:

## 7) Riesgos y mitigaciones
- Riesgo:
  - Mitigacion:

## 8) Entregables finales
- [ ] Codigo implementado por fases
- [ ] Tests verdes
- [ ] PR listo para revision
```

## Reglas de calidad del blueprint

- Debe ser accionable sin ambiguedad.
- Debe ser DRY/KISS: fases claras, sin duplicar tareas.
- Debe incluir seguridad y manejo de errores seguros.
- Debe tratar consistencia ACID cuando haya mutaciones de datos.
- Debe separar claramente plan vs implementacion.

## Cierre esperado en la respuesta al usuario

1. Confirmar ruta del markdown generado.
2. Resumir fases en 4-8 lineas maximo.
3. Preguntar solo si falta informacion bloqueante para ejecutar la Fase 0.
