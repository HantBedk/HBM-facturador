<script setup>
import { ref } from 'vue'
import {
  downloadServicesRegistryCsv,
  downloadServicesRegistryTemplate,
  importServicesRegistrySpreadsheet,
} from '@/services/servicesRegistryApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'

const uiDialog = useUiDialogStore()

const exportBusy = ref(false)
const importBusy = ref(false)
const dryRunBusy = ref(false)
const feedbackError = ref('')
const feedbackOk = ref('')
const importFileRef = ref(null)
const dryRunFileRef = ref(null)

const filters = ref({
  company_id: '',
  user_id: '',
  kind: '',
  service_date_from: '',
  service_date_to: '',
  q: '',
})

function triggerCsvDownload(blob, filename) {
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = filename
  a.click()
  URL.revokeObjectURL(a.href)
}

function buildExportParams() {
  const params = {}
  if (filters.value.company_id) params.company_id = filters.value.company_id
  if (filters.value.user_id) params.user_id = filters.value.user_id
  if (filters.value.service_date_from) params.service_date_from = filters.value.service_date_from
  if (filters.value.service_date_to) params.service_date_to = filters.value.service_date_to
  if (filters.value.q.trim()) params.q = filters.value.q.trim()
  if (filters.value.kind) params.kind = filters.value.kind
  return params
}

async function exportServices() {
  feedbackError.value = ''
  feedbackOk.value = ''
  exportBusy.value = true
  try {
    const { blob, filename } = await downloadServicesRegistryCsv(buildExportParams())
    triggerCsvDownload(blob, filename)
    feedbackOk.value = 'Exportación descargada. Puede editarla y volver a subirla con el mismo formato.'
  } catch (e) {
    feedbackError.value = e.message || 'No se pudo exportar.'
  } finally {
    exportBusy.value = false
  }
}

async function downloadTemplate() {
  feedbackError.value = ''
  exportBusy.value = true
  try {
    const { blob, filename } = await downloadServicesRegistryTemplate()
    triggerCsvDownload(blob, filename)
  } catch (e) {
    feedbackError.value = e.message || 'No se pudo descargar la plantilla.'
  } finally {
    exportBusy.value = false
  }
}

function formatImportSummary(data) {
  const lines = [data?.message || 'Importación finalizada.']
  const issues = data?.issues
  if (issues?.length) {
    lines.push('')
    lines.push('Detalle por fila (máx. 20):')
    issues.slice(0, 20).forEach((i) => {
      lines.push(`· Fila ${i.line}: ${i.message}`)
    })
    if (issues.length > 20) {
      lines.push(`… y ${issues.length - 20} más.`)
    }
  }
  return lines.join('\n')
}

async function runImport(file, dryRun) {
  if (!file || file.size < 1) {
    feedbackError.value = 'El archivo está vacío. Elija otro CSV o Excel.'
    return
  }
  feedbackError.value = ''
  feedbackOk.value = ''
  if (dryRun) dryRunBusy.value = true
  else importBusy.value = true
  try {
    const data = await importServicesRegistrySpreadsheet(file, { dryRun })
    feedbackOk.value = data?.message || (dryRun ? 'Simulación completada.' : 'Importación completada.')
    await uiDialog.alert({
      title: dryRun ? 'Simulación de importación' : 'Importar servicios',
      message: formatImportSummary(data),
    })
  } catch (e) {
    feedbackError.value = e.data?.message || e.message || 'No se pudo importar.'
    const fe = e.data?.errors?.file
    if (Array.isArray(fe) && fe[0]) {
      feedbackError.value = fe[0]
    }
  } finally {
    importBusy.value = false
    dryRunBusy.value = false
  }
}

function onImportFile(ev) {
  const input = ev.target
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  runImport(file, false)
}

function onDryRunFile(ev) {
  const input = ev.target
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  runImport(file, true)
}

function triggerImportPick() {
  importFileRef.value?.click()
}

function triggerDryRunPick() {
  dryRunFileRef.value?.click()
}
</script>

<template>
  <div class="services-data-page w-full max-w-[900px]">
    <header class="page-head mb-6">
      <h1 class="page-title">Servicios y mantenimientos (CSV)</h1>
      <p class="page-lede">
        Exporte el listado, edítelo en Excel y vuelva a subirlo. Las filas con
        <strong>código de servicio</strong> existente se actualizan; las filas sin código generan un registro nuevo.
        Use la misma plantilla o el archivo exportado (UTF-8, separador punto y coma).
      </p>
    </header>

    <p v-if="feedbackError" class="banner err" role="alert">{{ feedbackError }}</p>
    <p v-else-if="feedbackOk" class="banner ok" role="status">{{ feedbackOk }}</p>

    <section class="card block">
      <h2 class="block-title">Filtros de exportación (opcional)</h2>
      <p class="block-hint">Solo afectan la descarga; la importación usa los datos del archivo.</p>
      <div class="filters-grid">
        <label class="field">
          <span>Empresa (ID)</span>
          <input v-model="filters.company_id" type="text" inputmode="numeric" placeholder="Vacío = todas" />
        </label>
        <label class="field">
          <span>Técnico (ID usuario)</span>
          <input v-model="filters.user_id" type="text" inputmode="numeric" placeholder="Vacío = todos" />
        </label>
        <label class="field">
          <span>Clase</span>
          <select v-model="filters.kind">
            <option value="">Todas</option>
            <option value="servicio">Servicio</option>
            <option value="mantenimiento">Mantenimiento</option>
          </select>
        </label>
        <label class="field">
          <span>Desde</span>
          <input v-model="filters.service_date_from" type="date" />
        </label>
        <label class="field">
          <span>Hasta</span>
          <input v-model="filters.service_date_to" type="date" />
        </label>
        <label class="field field-wide">
          <span>Búsqueda</span>
          <input v-model="filters.q" type="search" placeholder="Código, cliente, descripción…" />
        </label>
      </div>
    </section>

    <section class="card block">
      <h2 class="block-title">Descargar</h2>
      <div class="actions">
        <button type="button" class="btn secondary" :disabled="exportBusy" @click="downloadTemplate">
          Plantilla vacía
        </button>
        <button type="button" class="btn secondary" :disabled="exportBusy" @click="exportServices">
          {{ exportBusy ? 'Exportando…' : 'Exportar servicios (CSV)' }}
        </button>
      </div>
    </section>

    <section class="card block">
      <h2 class="block-title">Subir</h2>
      <p class="block-hint">
        Revise el archivo antes de aplicar. «Simular» valida filas sin guardar cambios.
      </p>
      <input
        ref="importFileRef"
        type="file"
        class="sr-only"
        accept=".csv,.txt,.xlsx,.xls,.xlsm"
        :disabled="importBusy || dryRunBusy"
        @change="onImportFile"
      />
      <input
        ref="dryRunFileRef"
        type="file"
        class="sr-only"
        accept=".csv,.txt,.xlsx,.xls,.xlsm"
        :disabled="importBusy || dryRunBusy"
        @change="onDryRunFile"
      />
      <div class="actions">
        <button
          type="button"
          class="btn secondary"
          :disabled="importBusy || dryRunBusy"
          @click="triggerDryRunPick"
        >
          {{ dryRunBusy ? 'Simulando…' : 'Simular importación…' }}
        </button>
        <button type="button" class="btn primary" :disabled="importBusy || dryRunBusy" @click="triggerImportPick">
          {{ importBusy ? 'Importando…' : 'Importar y aplicar…' }}
        </button>
      </div>
    </section>
  </div>
</template>

<style scoped>
.page-title {
  margin: 0 0 0.5rem;
  font-size: 1.5rem;
  font-weight: 700;
  color: #f8fafc;
}
.page-lede {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.55;
  color: #94a3b8;
  max-width: 42rem;
}
.card.block {
  margin-bottom: 1.25rem;
  padding: 1.1rem 1.25rem;
  border-radius: 1rem;
  border: 1px solid rgba(148, 163, 184, 0.22);
  background: rgba(15, 23, 42, 0.55);
}
.block-title {
  margin: 0 0 0.5rem;
  font-size: 1rem;
  font-weight: 600;
  color: #e2e8f0;
}
.block-hint {
  margin: 0 0 0.85rem;
  font-size: 0.82rem;
  color: #94a3b8;
  line-height: 1.45;
}
.filters-grid {
  display: grid;
  gap: 0.75rem;
  grid-template-columns: repeat(auto-fill, minmax(10rem, 1fr));
}
.field-wide {
  grid-column: 1 / -1;
}
.field span {
  display: block;
  margin-bottom: 0.35rem;
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #64748b;
}
.field input,
.field select {
  width: 100%;
  padding: 0.5rem 0.65rem;
  border-radius: 0.5rem;
  border: 1px solid rgba(100, 116, 139, 0.45);
  background: #0f172a;
  color: #f1f5f9;
  font-size: 0.88rem;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.banner {
  margin-bottom: 1rem;
  padding: 0.65rem 0.85rem;
  border-radius: 0.5rem;
  font-size: 0.88rem;
}
.banner.err {
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
}
.banner.ok {
  background: rgba(52, 211, 153, 0.1);
  border: 1px solid rgba(52, 211, 153, 0.35);
  color: #a7f3d0;
}
.btn {
  display: inline-flex;
  align-items: center;
  padding: 0.5rem 0.9rem;
  border-radius: 0.5rem;
  font-weight: 600;
  font-size: 0.88rem;
  cursor: pointer;
  border: 1px solid rgba(148, 163, 184, 0.35);
}
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.btn.secondary {
  background: rgba(2, 6, 23, 0.35);
  color: #e2e8f0;
}
.btn.primary {
  background: linear-gradient(135deg, #0ea5e9, #2563eb);
  border-color: transparent;
  color: #fff;
}
.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  border: 0;
}
</style>
