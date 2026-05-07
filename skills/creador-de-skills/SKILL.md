---
name: creador-de-skills
description: Crea nuevas skills para el agente Antigravity siguiendo el estándar oficial. Úsalo cuando el usuario solicite crear una nueva habilidad, automatización o herramienta recurrente.
---

# Creador de Skills (Habilidades)

Esta skill te permite crear nuevas habilidades para el agente siguiendo la documentación oficial de Antigravity.

## Instrucciones para crear una Skill

Cuando el usuario te pida crear una nueva skill, debes seguir exactamente estos pasos:

1. **Definir el nombre y propósito:**
   - Deduce o pregunta al usuario el objetivo principal de la skill.
   - Elige un nombre descriptivo en formato `kebab-case` (ejemplo: `revision-codigo`, `deploy-produccion`).

2. **Crear la estructura de carpetas:**
   - Las skills del proyecto Hotel PMS deben guardarse directamente en la ruta: `skills/<nombre-de-la-skill>/` (desde la raíz del proyecto).
   - Crea este directorio usando las herramientas de archivos.
   - Si la skill requiere ejecutar código complejo, crea un subdirectorio `scripts/`.
   - Si necesita plantillas o archivos de soporte, crea un subdirectorio `data/`.

3. **Redactar el archivo `SKILL.md`:**
   - Escribe el archivo `skills/<nombre-de-la-skill>/SKILL.md`.
   - **REQUISITO INDISPENSABLE:** El archivo debe comenzar con un bloque Frontmatter en YAML con las propiedades `name` y `description`. La `description` es fundamental, ya que el agente la lee para decidir **cuándo** debe activar la skill.

   **Plantilla de ejemplo para el `SKILL.md`:**
   ```markdown
   ---
   name: nombre-de-la-skill
   description: Una explicación clara y concisa de lo que hace la skill y en qué situaciones el agente debe usarla.
   ---

   # Título de la Skill

   Instrucciones paso a paso sobre cómo el agente debe comportarse.

   ## Flujo de trabajo
   1. Primer paso...
   2. Segundo paso...
   ```

4. **Aplicar Mejores Prácticas:**
   - **Enfoque único:** Diseña cada skill para una tarea específica y bien definida. Evita crear skills "multiusos".
   - **Descripciones explícitas:** El campo `description` en el frontmatter debe ser muy claro sobre los desencadenantes (triggers) de la skill.
   - **Scripts como "Cajas Negras":** Usa scripts (Python, Bash, Node.js) para lógicas complejas o procesamiento de datos. Mantén el `SKILL.md` centrado en instrucciones de alto nivel y toma de decisiones.
   - **Árboles de decisión:** Para flujos de trabajo complejos, utiliza listas y encabezados en Markdown para proporcionar un camino claro de acción basado en diferentes escenarios.

5. **Finalización:**
   - Notifica al usuario que la skill ha sido creada.
   - Explícale brevemente cómo puede invocarla en futuras conversaciones.
