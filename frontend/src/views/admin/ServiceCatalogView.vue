<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import AdminServiceCatalogDetailPanel from '@/components/admin/AdminServiceCatalogDetailPanel.vue'
import {
  bulkDestroyServiceCatalogItems,
  createServiceCatalogItem,
  deleteServiceCatalogItem,
  fetchAdminServiceCatalog,
  fetchTechnicianCatalogDiscount,
  importServiceCatalogFromSpreadsheet,
  patchServiceCatalogEstado,
  updateServiceCatalogItem,
  updateTechnicianCatalogDiscount,
} from '@/services/servicesApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'

const uiDialog = useUiDialogStore()

const rows = ref([])
const loading = ref(false)
const error = ref('')
const saving = ref(false)
const fieldErrors = ref({})

/** % global de diferencia: precio lista (factura) vs importe de referencia que ve el técnico en /service-catalog/active */
const technicianDiscountPercent = ref(10)
const techDiscountSaving = ref(false)
const techDiscountError = ref('')
const techDiscountOk = ref('')

const showModal = ref(false)
const editingId = ref(null)
const form = ref({
  name: '',
  description: '',
  base_price: '',
  status: 'activo',
})

/** Selección multi para borrado masivo (ids en la página cargada). */
const selectedCatalogIds = ref([])
const bulkDeleting = ref(false)

const importBusy = ref(false)
const importFileRef = ref(null)

/** listado: tabla; herramientas: importar Excel + descuento técnicos */
const catalogTab = ref('listado')

const allPageCatalogSelected = computed(
  () =>
    rows.value.length > 0 && rows.value.every((r) => selectedCatalogIds.value.includes(Number(r.id)))
)

const selectedCatalogCount = computed(() => selectedCatalogIds.value.length)

/** Orden del listado: servidor (name | base_price + asc/desc). */
const catalogSortBy = ref('name')
const catalogSortDir = ref('asc')

function catalogSortIndicator(field) {
  if (catalogSortBy.value !== field) return ''
  return catalogSortDir.value === 'asc' ? '▲' : '▼'
}

async function setCatalogSort(field) {
  if (catalogSortBy.value === field) {
    catalogSortDir.value = catalogSortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    catalogSortBy.value = field
    catalogSortDir.value = field === 'base_price' ? 'desc' : 'asc'
  }
  await load()
}

async function load() {
  error.value = ''
  loading.value = true
  try {
    const res = await fetchAdminServiceCatalog({
      per_page: 100,
      sort: catalogSortBy.value,
      direction: catalogSortDir.value,
    })
    rows.value = res.data || []
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar el catálogo.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

async function loadTechnicianDiscount() {
  techDiscountError.value = ''
  try {
    const d = await fetchTechnicianCatalogDiscount()
    technicianDiscountPercent.value = Number(d?.technician_catalog_discount_percent ?? 10)
  } catch (e) {
    techDiscountError.value = e.data?.message || e.message || 'No se pudo cargar el ajuste de precios.'
  }
}

async function saveTechnicianDiscount() {
  techDiscountOk.value = ''
  techDiscountError.value = ''
  techDiscountSaving.value = true
  try {
    await updateTechnicianCatalogDiscount(Number(technicianDiscountPercent.value))
    techDiscountOk.value =
      'Porcentaje guardado. Cada línea de catálogo usará esta diferencia salvo que el ítem tenga un % propio.'
  } catch (e) {
    techDiscountError.value = e.data?.message || e.message || 'No se pudo guardar.'
    if (e.data?.errors) {
      const first = Object.values(e.data.errors).flat()[0]
      if (first) techDiscountError.value = first
    }
  } finally {
    techDiscountSaving.value = false
  }
}

const detailPanelOpen = ref(false)
const detailCatalogId = ref(null)
const detailPanelRef = ref(null)

function openDetailPanel(row) {
  detailCatalogId.value = row.id
  detailPanelOpen.value = true
}

function closeDetailPanel() {
  detailPanelOpen.value = false
  detailCatalogId.value = null
}

function onGlobalEscape(ev) {
  if (ev.key !== 'Escape') return
  if (!detailPanelOpen.value) return
  ev.preventDefault()
  closeDetailPanel()
}

onMounted(async () => {
  document.addEventListener('keydown', onGlobalEscape)
  await loadTechnicianDiscount()
  await load()
})

onUnmounted(() => {
  document.removeEventListener('keydown', onGlobalEscape)
})

watch(showModal, (open) => {
  if (!open) {
    editingId.value = null
    form.value = {
      name: '',
      description: '',
      base_price: '',
      status: 'activo',
    }
  }
})

watch(rows, (list) => {
  const allowed = new Set(list.map((r) => Number(r.id)))
  selectedCatalogIds.value = selectedCatalogIds.value.filter((id) => allowed.has(id))
})

function isCatalogRowSelected(id) {
  return selectedCatalogIds.value.includes(Number(id))
}

function toggleCatalogRowSelect(id) {
  const n = Number(id)
  const i = selectedCatalogIds.value.indexOf(n)
  if (i >= 0) selectedCatalogIds.value.splice(i, 1)
  else selectedCatalogIds.value.push(n)
}

function toggleSelectAllCatalogPage() {
  const pageIds = rows.value.map((r) => Number(r.id))
  if (!pageIds.length) return
  if (allPageCatalogSelected.value) {
    const drop = new Set(pageIds)
    selectedCatalogIds.value = selectedCatalogIds.value.filter((id) => !drop.has(id))
  } else {
    selectedCatalogIds.value = [...new Set([...selectedCatalogIds.value, ...pageIds])]
  }
}

async function onDeleteCatalogRow(row) {
  const ok = await uiDialog.confirm({
    title: 'Eliminar ítem del catálogo',
    message: `¿Eliminar «${row.name}»? Los servicios que lo referenciaban quedarán sin vínculo al catálogo (catalog_id en null).`,
    danger: true,
    confirmLabel: 'Eliminar',
  })
  if (!ok) return
  error.value = ''
  try {
    await deleteServiceCatalogItem(row.id)
    selectedCatalogIds.value = selectedCatalogIds.value.filter((id) => id !== Number(row.id))
    if (detailPanelOpen.value && String(detailCatalogId.value) === String(row.id)) {
      closeDetailPanel()
    }
    await load()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo eliminar.'
  }
}

async function onBulkDeleteCatalog() {
  const n = selectedCatalogIds.value.length
  if (n < 1) return
  const ok = await uiDialog.confirm({
    title: 'Eliminar ítems seleccionados',
    message: `¿Eliminar ${n} ítem(s) del catálogo? Los servicios afectados perderán la referencia al catálogo.`,
    danger: true,
    confirmLabel: `Eliminar ${n}`,
  })
  if (!ok) return
  error.value = ''
  bulkDeleting.value = true
  try {
    await bulkDestroyServiceCatalogItems([...selectedCatalogIds.value])
    selectedCatalogIds.value = []
    await load()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo completar el borrado masivo.'
  } finally {
    bulkDeleting.value = false
  }
}

function openCreate() {
  editingId.value = null
  form.value = {
    name: '',
    description: '',
    base_price: '',
    status: 'activo',
  }
  showModal.value = true
}

function openEdit(row) {
  editingId.value = row.id
  form.value = {
    name: row.name,
    description: row.description || '',
    base_price: String(row.base_price),
    status: row.status,
  }
  showModal.value = true
}

/** Etiqueta para columna: override por ítem o referencia al % global cargado. */
function technicianMarginLabel(row) {
  const ov = row.technician_discount_percent
  if (ov != null && ov !== '') {
    const n = Number(ov)
    if (!Number.isNaN(n)) return `${n}% · ítem`
  }
  const g = Number(technicianDiscountPercent.value)
  const pct = Number.isNaN(g) ? '—' : `${g}%`
  return `Global (${pct})`
}

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

async function onSave() {
  saving.value = true
  error.value = ''
  fieldErrors.value = {}
  const savedId = editingId.value
  try {
    const base = {
      name: form.value.name.trim(),
      description: form.value.description.trim() || null,
      base_price: Number(form.value.base_price),
    }
    if (editingId.value) {
      // No enviar % técnico por ítem: se conserva el valor en servidor.
      await updateServiceCatalogItem(editingId.value, {
        ...base,
        status: form.value.status,
      })
    } else {
      // Siempre global; % técnico solo desde «Importar y precios» (global o futuro import).
      await createServiceCatalogItem(base)
    }
    showModal.value = false
    await load()
    if (detailPanelOpen.value && savedId != null && String(detailCatalogId.value) === String(savedId)) {
      await detailPanelRef.value?.reload?.()
    }
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo guardar.'
    if (e.data?.errors) fieldErrors.value = e.data.errors
  } finally {
    saving.value = false
  }
}

async function toggleStatus(row) {
  const next = row.status === 'activo' ? 'inactivo' : 'activo'
  try {
    await patchServiceCatalogEstado(row.id, next)
    await load()
    if (detailPanelOpen.value && String(detailCatalogId.value) === String(row.id)) {
      await detailPanelRef.value?.reload?.()
    }
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo actualizar.'
  }
}

function formatImportSummary(data) {
  const lines = [data?.message || 'Importación finalizada.']
  const issues = data?.issues
  if (issues?.length) {
    lines.push('')
    lines.push('Detalle por fila (máx. 20):')
    issues.slice(0, 20).forEach((i) => {
      const tag = i.code === 'duplicate' ? 'duplicado' : 'aviso'
      lines.push(`· Fila ${i.row} (${tag}): ${i.message}`)
    })
    if (issues.length > 20) {
      lines.push(`… y ${issues.length - 20} más.`)
    }
  }
  return lines.join('\n')
}

async function onImportFile(ev) {
  const input = ev.target
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  if (file.size < 1) {
    error.value =
      'El archivo está vacío (0 bytes). Elija otro archivo o vuelva a exportar el CSV/Excel.'
    return
  }
  importBusy.value = true
  error.value = ''
  try {
    const data = await importServiceCatalogFromSpreadsheet(file)
    await load()
    await uiDialog.alert({
      title: 'Importación de catálogo',
      message: formatImportSummary(data),
    })
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo importar el archivo.'
    const fe = e.data?.errors?.file
    if (Array.isArray(fe) && fe[0]) {
      error.value = fe[0]
    }
  } finally {
    importBusy.value = false
  }
}

function triggerImportPick() {
  importFileRef.value?.click()
}

function onDetailEdit(catalogItem) {
  openEdit(catalogItem)
}

async function onDetailToggleStatus(catalogItem) {
  await toggleStatus(catalogItem)
}

function onDetailDelete(catalogItem) {
  return onDeleteCatalogRow(catalogItem)
}
</script>

<template>
  <section class="page page--fluid">
    <header class="head">
      <div>
        <h1>Catálogo de servicios</h1>
        <p class="lede">
          <strong>Precios orientativos</strong> por ítem (el importe facturable lo arma el técnico al cargar el servicio). Clic en el <strong>código</strong> (CAT-…) abre el panel lateral con el detalle;
          en <strong>Importar y precios</strong> cargas Excel/CSV y el <strong>% global</strong> de margen técnico → factura a la empresa.
        </p>
      </div>
      <div class="head-actions">
        <button
          v-if="selectedCatalogCount > 0"
          type="button"
          class="btn danger"
          :disabled="bulkDeleting"
          @click="onBulkDeleteCatalog"
        >
          {{ bulkDeleting ? 'Eliminando…' : `Eliminar seleccionados (${selectedCatalogCount})` }}
        </button>
        <button type="button" class="btn primary" @click="openCreate">+ Nuevo ítem</button>
      </div>
    </header>

    <div class="catalog-tabs" role="tablist" aria-label="Secciones del catálogo">
      <button
        type="button"
        class="catalog-tab"
        role="tab"
        :aria-selected="catalogTab === 'listado'"
        @click="catalogTab = 'listado'"
      >
        Catálogo
      </button>
      <button
        type="button"
        class="catalog-tab"
        role="tab"
        :aria-selected="catalogTab === 'herramientas'"
        @click="catalogTab = 'herramientas'"
      >
        Importar y precios
      </button>
    </div>

    <p v-if="error" class="banner err">{{ error }}</p>

    <div v-show="catalogTab === 'listado'" class="tab-panel" role="tabpanel">
    <div class="card table-wrap">
      <div v-if="loading" class="muted pad">Cargando…</div>
      <table v-else class="table">
        <thead>
          <tr>
            <th class="th-check" scope="col">
              <input
                type="checkbox"
                class="check"
                :checked="allPageCatalogSelected"
                :disabled="!rows.length"
                aria-label="Seleccionar todos los ítems de esta página"
                @click.prevent="toggleSelectAllCatalogPage"
              />
            </th>
            <th
              scope="col"
              :aria-sort="catalogSortBy === 'code' ? (catalogSortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
            >
              <button type="button" class="th-sort" @click="setCatalogSort('code')">
                Código<span class="sort-ind" aria-hidden="true">{{ catalogSortIndicator('code') }}</span>
              </button>
            </th>
            <th scope="col" :aria-sort="catalogSortBy === 'name' ? (catalogSortDir === 'asc' ? 'ascending' : 'descending') : 'none'">
              <button type="button" class="th-sort" @click="setCatalogSort('name')">
                Nombre<span class="sort-ind" aria-hidden="true">{{ catalogSortIndicator('name') }}</span>
              </button>
            </th>
            <th
              scope="col"
              class="num"
              :aria-sort="catalogSortBy === 'base_price' ? (catalogSortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
            >
              <button type="button" class="th-sort th-sort--end" @click="setCatalogSort('base_price')">
                Orientativo<span class="sort-ind" aria-hidden="true">{{ catalogSortIndicator('base_price') }}</span>
              </button>
            </th>
            <th scope="col" class="nowrap">Margen técnico</th>
            <th
              scope="col"
              :aria-sort="catalogSortBy === 'status' ? (catalogSortDir === 'asc' ? 'ascending' : 'descending') : 'none'"
            >
              <button type="button" class="th-sort" @click="setCatalogSort('status')">
                Estado<span class="sort-ind" aria-hidden="true">{{ catalogSortIndicator('status') }}</span>
              </button>
            </th>
            <th class="actions-col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td class="td-check">
              <input
                type="checkbox"
                class="check"
                :checked="isCatalogRowSelected(r.id)"
                :aria-label="'Seleccionar ' + r.name"
                @click.prevent="toggleCatalogRowSelect(r.id)"
              />
            </td>
            <td class="mono">
              <button type="button" class="code-link" @click="openDetailPanel(r)">
                {{ r.code || `CAT-${r.id}` }}
              </button>
            </td>
            <td>
              <strong>{{ r.name }}</strong>
              <p v-if="r.description" class="muted tiny">{{ r.description }}</p>
            </td>
            <td class="num">{{ money(r.base_price) }}</td>
            <td class="tiny muted">{{ technicianMarginLabel(r) }}</td>
            <td>
              <span class="pill" :data-st="r.status">{{ r.status === 'activo' ? 'Activo' : 'Inactivo' }}</span>
            </td>
            <td class="actions-col">
              <button type="button" class="link" @click="openEdit(r)">Editar</button>
              <button type="button" class="link" @click="toggleStatus(r)">
                {{ r.status === 'activo' ? 'Desactivar' : 'Activar' }}
              </button>
              <button type="button" class="link danger" @click="onDeleteCatalogRow(r)">Eliminar</button>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="7" class="empty muted">Sin ítems. Cree el primero.</td>
          </tr>
        </tbody>
      </table>
    </div>
    </div>

    <div v-show="catalogTab === 'herramientas'" class="tab-panel" role="tabpanel">
      <div class="card import-card">
        <h2 class="import-title">Importar desde Excel o CSV</h2>
        <p class="lede import-lede">
          Use la <strong>primera hoja</strong> del libro (.xlsx, .xls) o un archivo <strong>CSV UTF-8</strong>. Puede incluir una fila de encabezados con textos como
          <em>Nombre</em>, <em>Descripción</em> (opcional) y <em>Precio</em> / <em>Valor</em>. Sin encabezados: columna A = nombre, B = descripción si hay tres o más columnas, última columna numérica = precio; con solo dos columnas: A = nombre, B = precio. Máximo
          2000 filas; archivo hasta 5&nbsp;MB. Los nombres duplicados respecto al catálogo actual se omiten.
        </p>
        <div class="import-row">
          <input
            ref="importFileRef"
            type="file"
            class="import-file-input"
            accept=".xlsx,.xls,.csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel,text/csv"
            :disabled="importBusy"
            @change="onImportFile"
          />
          <button type="button" class="btn secondary import-btn" :disabled="importBusy" @click="triggerImportPick">
            {{ importBusy ? 'Importando…' : 'Elegir archivo…' }}
          </button>
        </div>
      </div>

      <div class="card pricing-card">
        <h2 class="pricing-title">Margen técnico → factura</h2>
        <p class="pricing-lede">
          El <strong>precio base</strong> del catálogo es <strong>solo orientativo</strong>. Al cargar el servicio, el técnico escribe el <strong>importe de referencia</strong> por línea; con el <strong>% global</strong> (por defecto 10&nbsp;%) se calcula lo <strong>facturable a la empresa</strong>, igual que en las líneas «Otro».
          Puede definir un <strong>% distinto por ítem</strong> en el panel lateral del ítem (sustituye al global solo para esa línea al facturar).
        </p>
        <div class="pricing-row">
          <label class="pricing-label">
            <span>Porcentaje global (%)</span>
            <input
              v-model.number="technicianDiscountPercent"
              type="number"
              min="0"
              max="95"
              step="0.5"
              class="input pricing-input"
            />
          </label>
          <button type="button" class="btn primary" :disabled="techDiscountSaving" @click="saveTechnicianDiscount">
            {{ techDiscountSaving ? 'Guardando…' : 'Guardar porcentaje' }}
          </button>
        </div>
        <p v-if="techDiscountError" class="banner err inline">{{ techDiscountError }}</p>
        <p v-else-if="techDiscountOk" class="banner ok inline">{{ techDiscountOk }}</p>
      </div>
    </div>

    <AdminServiceCatalogDetailPanel
      ref="detailPanelRef"
      :open="detailPanelOpen"
      :catalog-id="detailCatalogId"
      @close="closeDetailPanel"
      @edit="onDetailEdit"
      @toggle-status="onDetailToggleStatus"
      @delete="onDetailDelete"
    />

    <Teleport to="body">
      <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
        <div class="modal card">
          <h2>{{ editingId ? 'Editar ítem' : 'Nuevo ítem' }}</h2>
          <form class="modal-form" @submit.prevent="onSave">
            <label>
              <span>Nombre</span>
              <input v-model="form.name" required class="input" maxlength="255" />
            </label>
            <label>
              <span>Descripción</span>
              <textarea v-model="form.description" class="input" rows="3" />
            </label>
            <label>
              <span>Precio orientativo (COP)</span>
              <input v-model="form.base_price" type="number" min="0.01" step="0.01" required class="input" />
              <small class="hint">Importe de lista (factura). Los ítems nuevos son globales para todas las empresas.</small>
            </label>
            <label v-if="editingId">
              <span>Estado</span>
              <select v-model="form.status" class="input">
                <option value="activo">Activo</option>
                <option value="inactivo">Inactivo</option>
              </select>
            </label>
            <p v-if="fieldErrors.name" class="field-err">{{ fieldErrors.name[0] }}</p>
            <div class="modal-actions">
              <button type="button" class="btn secondary" @click="showModal = false">Cancelar</button>
              <button type="submit" class="btn primary" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.page.page--fluid {
  width: 100%;
  max-width: none;
  min-width: 0;
  margin: 0;
  box-sizing: border-box;
}
.head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1rem;
}
.head-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}
.catalog-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin-bottom: 0.85rem;
  padding: 0.2rem;
  border-radius: 12px;
  background: rgba(15, 23, 42, 0.65);
  border: 1px solid rgba(148, 163, 184, 0.2);
}
.catalog-tab {
  flex: 1 1 auto;
  min-width: 10rem;
  padding: 0.55rem 1rem;
  border: none;
  border-radius: 9px;
  font: inherit;
  font-weight: 600;
  font-size: 0.88rem;
  cursor: pointer;
  color: #94a3b8;
  background: transparent;
  transition:
    background 0.12s ease,
    color 0.12s ease;
}
.catalog-tab:hover {
  color: #e2e8f0;
  background: rgba(56, 189, 248, 0.08);
}
.catalog-tab[aria-selected='true'] {
  color: #f8fafc;
  background: rgba(56, 189, 248, 0.18);
  box-shadow: inset 0 0 0 1px rgba(56, 189, 248, 0.35);
}
.tab-panel {
  margin-bottom: 1rem;
}
h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  color: #f8fafc;
}
.lede {
  margin: 0;
  color: #94a3b8;
  font-size: 0.9rem;
  max-width: 42rem;
}
.code {
  font-size: 0.8em;
  padding: 0.1rem 0.35rem;
  border-radius: 6px;
  background: rgba(15, 23, 42, 0.9);
  border: 1px solid rgba(148, 163, 184, 0.25);
}
.card {
  padding: 1rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
}
.import-card {
  margin-bottom: 1rem;
  border-color: rgba(56, 189, 248, 0.28);
  background: rgba(12, 74, 110, 0.12);
}
.import-title {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
  color: #e0f2fe;
}
.import-lede {
  margin: 0 0 1rem;
  max-width: 48rem;
  line-height: 1.5;
  font-size: 0.86rem;
}
.import-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.75rem 1rem;
}
.import-field {
  flex: 1 1 220px;
  min-width: 180px;
}
.import-field span {
  display: block;
  font-size: 0.78rem;
  color: #94a3b8;
  margin-bottom: 0.3rem;
}
.import-file-input {
  position: absolute;
  width: 1px;
  height: 1px;
  padding: 0;
  margin: -1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
  border: 0;
}
.import-btn {
  flex-shrink: 0;
}
.pricing-card {
  margin-bottom: 0;
  border-color: rgba(45, 212, 191, 0.25);
  background: rgba(6, 78, 59, 0.12);
}

.pricing-title {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
  color: #ccfbf1;
}
.pricing-lede {
  margin: 0 0 0.85rem;
  font-size: 0.85rem;
  color: #94a3b8;
  max-width: 44rem;
  line-height: 1.45;
}
.pricing-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.75rem;
}
.pricing-label span {
  display: block;
  font-size: 0.78rem;
  color: #94a3b8;
  margin-bottom: 0.25rem;
}
.pricing-input {
  width: 7rem;
}
.banner.ok {
  padding: 0.55rem 0.75rem;
  border-radius: 10px;
  background: rgba(34, 197, 94, 0.12);
  border: 1px solid rgba(74, 222, 128, 0.4);
  color: #bbf7d0;
  margin: 0.5rem 0 0;
}
.banner.inline {
  margin-bottom: 0;
}
.table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}
.table th,
.table td {
  padding: 0.55rem 0.45rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
  text-align: left;
}
.table th {
  color: #94a3b8;
  font-weight: 600;
}
.th-sort {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  max-width: 100%;
  margin: 0;
  padding: 0;
  border: none;
  background: transparent;
  font: inherit;
  font-weight: 600;
  color: inherit;
  cursor: pointer;
  text-align: inherit;
  border-radius: 6px;
}
.th-sort:hover {
  color: #e2e8f0;
}
.th-sort:focus-visible {
  outline: 2px solid rgba(56, 189, 248, 0.55);
  outline-offset: 2px;
}
.th-sort--end {
  justify-content: flex-end;
  width: 100%;
}
.sort-ind {
  font-size: 0.7rem;
  opacity: 0.9;
  white-space: nowrap;
}
.th.num,
.num {
  text-align: right;
}
.mono {
  font-family: ui-monospace, monospace;
  font-size: 0.82rem;
}
.code-link {
  margin: 0;
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  font-family: ui-monospace, monospace;
  font-size: 0.82rem;
  font-weight: 600;
  color: #7dd3fc;
  cursor: pointer;
  text-align: left;
  text-decoration: underline;
  text-decoration-color: rgba(125, 211, 252, 0.45);
}
.code-link:hover {
  color: #bae6fd;
}
.pill {
  display: inline-block;
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}
.pill[data-st='activo'] {
  color: #86efac;
  border: 1px solid rgba(74, 222, 128, 0.4);
}
.pill[data-st='inactivo'] {
  color: #94a3b8;
  border: 1px solid rgba(148, 163, 184, 0.35);
}
.link {
  background: none;
  border: none;
  color: #7dd3fc;
  cursor: pointer;
  font-weight: 600;
  margin-right: 0.75rem;
}
.link.ok {
  color: #86efac;
}
.link.danger {
  color: #fca5a5;
}
.actions-col {
  white-space: nowrap;
}
.muted {
  color: #94a3b8;
}
.tiny {
  font-size: 0.8rem;
  margin: 0.25rem 0 0;
}
.nowrap {
  white-space: nowrap;
}
.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}
.field-err {
  color: #fca5a5;
  font-size: 0.85rem;
  margin: 0;
}
.empty {
  padding: 1.5rem;
  text-align: center;
}
.pad {
  padding: 1rem;
}
.btn {
  display: inline-flex;
  padding: 0.5rem 0.9rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
}
.btn.primary {
  background: linear-gradient(90deg, #2563eb, #7c3aed);
  color: #fff;
}
.btn.secondary {
  border-color: rgba(148, 163, 184, 0.35);
  color: #e2e8f0;
  background: transparent;
}
.btn.danger {
  border: 1px solid rgba(248, 113, 113, 0.5);
  color: #fecaca;
  background: rgba(127, 29, 29, 0.28);
}
.btn.danger:hover:not(:disabled) {
  background: rgba(153, 27, 27, 0.4);
}
.btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.th-check,
.td-check {
  width: 2.35rem;
  padding-left: 0.35rem;
  vertical-align: middle;
}
.check {
  width: 1.05rem;
  height: 1.05rem;
  accent-color: #38bdf8;
  cursor: pointer;
}
.modal-lede {
  font-size: 0.85rem;
  color: #94a3b8;
  margin: 0 0 1rem;
  line-height: 1.45;
}
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 80;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.modal {
  width: min(100%, 440px);
  max-height: 90vh;
  overflow-y: auto;
}
.modal-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  margin-top: 1rem;
}
.modal-form span {
  display: block;
  font-size: 0.78rem;
  color: #94a3b8;
  margin-bottom: 0.25rem;
}
.hint {
  display: block;
  font-size: 0.72rem;
  color: #64748b;
  margin-top: 0.25rem;
}
.input {
  width: 100%;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.35);
  color: #f8fafc;
  padding: 0.45rem 0.55rem;
  font: inherit;
}
.modal-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  margin-top: 0.5rem;
}
</style>
