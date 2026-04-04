<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import {
  approveServiceCatalogSuggestion,
  bulkDestroyServiceCatalogItems,
  createServiceCatalogItem,
  deleteServiceCatalogItem,
  fetchAdminServiceCatalog,
  fetchCompanies,
  fetchServiceCatalogSuggestions,
  fetchTechnicianCatalogDiscount,
  importServiceCatalogFromSpreadsheet,
  patchServiceCatalogEstado,
  rejectServiceCatalogSuggestion,
  updateServiceCatalogItem,
  updateTechnicianCatalogDiscount,
} from '@/services/servicesApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'

const uiDialog = useUiDialogStore()

const rows = ref([])
const companies = ref([])
const loading = ref(false)
const error = ref('')
const saving = ref(false)
const fieldErrors = ref({})

const pendingRows = ref([])
const pendingLoading = ref(false)

/** % de descuento sobre precio de lista que ve el técnico en GET /service-catalog/active */
const technicianDiscountPercent = ref(10)
const techDiscountSaving = ref(false)
const techDiscountError = ref('')
const techDiscountOk = ref('')

const showModal = ref(false)
const editingId = ref(null)
const form = ref({
  company_id: '',
  name: '',
  description: '',
  base_price: '',
  status: 'activo',
})

/** Selección multi para borrado masivo (ids en la página cargada). */
const selectedCatalogIds = ref([])
const bulkDeleting = ref(false)

const importCompanyId = ref('')
const importBusy = ref(false)
const importFileRef = ref(null)

/** listado: propuestas + tabla; herramientas: importar Excel + descuento técnicos */
const catalogTab = ref('listado')

const showSuggestionModal = ref(false)
const suggestionSaving = ref(false)
const suggestionFieldErrors = ref({})
const suggestionForm = ref({
  id: null,
  name: '',
  description: '',
  base_price: '',
})

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
    catalogSortDir.value = 'asc'
  }
  await load()
}

async function loadCompanies() {
  try {
    companies.value = await fetchCompanies()
  } catch {
    companies.value = []
  }
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
    techDiscountOk.value = 'Porcentaje guardado. Los técnicos verán el catálogo con el nuevo descuento.'
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

async function loadPending() {
  pendingLoading.value = true
  try {
    const res = await fetchServiceCatalogSuggestions({ pendientes: 1, per_page: 50 })
    pendingRows.value = res.data || []
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar las propuestas.'
    pendingRows.value = []
  } finally {
    pendingLoading.value = false
  }
}

onMounted(async () => {
  await loadCompanies()
  await loadTechnicianDiscount()
  await load()
  await loadPending()
})

watch(showModal, (open) => {
  if (!open) {
    editingId.value = null
    form.value = { company_id: '', name: '', description: '', base_price: '', status: 'activo' }
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

function openSuggestionApprove(s) {
  suggestionFieldErrors.value = {}
  suggestionForm.value = {
    id: s.id,
    name: s.name || '',
    description: s.description || '',
    base_price: String(s.suggested_price ?? ''),
  }
  showSuggestionModal.value = true
}

watch(showSuggestionModal, (open) => {
  if (!open) {
    suggestionForm.value = { id: null, name: '', description: '', base_price: '' }
    suggestionFieldErrors.value = {}
  }
})

async function onSubmitSuggestionApprove() {
  const id = suggestionForm.value.id
  if (id == null) return
  suggestionSaving.value = true
  error.value = ''
  suggestionFieldErrors.value = {}
  try {
    await approveServiceCatalogSuggestion(id, {
      name: suggestionForm.value.name.trim(),
      description: suggestionForm.value.description.trim() || null,
      base_price: Number(suggestionForm.value.base_price),
    })
    showSuggestionModal.value = false
    await loadPending()
    await load()
  } catch (e) {
    if (e.data?.errors) suggestionFieldErrors.value = e.data.errors
    error.value = e.data?.message || e.message || 'No se pudo aprobar.'
  } finally {
    suggestionSaving.value = false
  }
}

function openCreate() {
  editingId.value = null
  form.value = { company_id: '', name: '', description: '', base_price: '', status: 'activo' }
  showModal.value = true
}

function openEdit(row) {
  editingId.value = row.id
  form.value = {
    company_id: row.company_id != null ? String(row.company_id) : '',
    name: row.name,
    description: row.description || '',
    base_price: String(row.base_price),
    status: row.status,
  }
  showModal.value = true
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
  try {
    const base = {
      name: form.value.name.trim(),
      description: form.value.description.trim() || null,
      base_price: Number(form.value.base_price),
    }
    const scopedCompany =
      form.value.company_id !== '' && form.value.company_id != null ? Number(form.value.company_id) : null
    if (editingId.value) {
      await updateServiceCatalogItem(editingId.value, {
        ...base,
        company_id: scopedCompany,
        status: form.value.status,
      })
    } else {
      const body = { ...base }
      if (scopedCompany != null) body.company_id = scopedCompany
      await createServiceCatalogItem(body)
    }
    showModal.value = false
    await load()
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
  importBusy.value = true
  error.value = ''
  try {
    const data = await importServiceCatalogFromSpreadsheet(file, importCompanyId.value)
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

async function onRejectSuggestion(s) {
  const ok = await uiDialog.confirm({
    title: 'Descartar propuesta',
    message: `¿Descartar la propuesta «${s.name}»?`,
    danger: true,
    confirmLabel: 'Descartar',
  })
  if (!ok) return
  try {
    await rejectServiceCatalogSuggestion(s.id)
    await loadPending()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo descartar.'
  }
}
</script>

<template>
  <section class="page">
    <header class="head">
      <div>
        <h1>Catálogo de servicios</h1>
        <p class="lede">
          Precios base del catálogo (globales o por empresa en cada ítem). En la pestaña <strong>Catálogo</strong> gestionas ítems y
          propuestas pendientes; en <strong>Importar y precios</strong> cargas Excel/CSV y el descuento que ven los técnicos.
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
    <div class="card pending-block">
      <h2 class="pending-title">Propuestas de catálogo pendientes</h2>
      <p class="pending-sub muted">
        Ítems nuevos que proponen los empleados al registrar un servicio con línea «Otro». Revísalos aquí antes de darlos de alta.
      </p>
      <p v-if="pendingLoading" class="muted pad">Cargando…</p>
      <table v-else-if="pendingRows.length" class="table">
        <thead>
          <tr>
            <th>Nombre propuesto</th>
            <th class="num">Precio sugerido</th>
            <th class="actions-col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in pendingRows" :key="s.id">
            <td>
              <strong>{{ s.name }}</strong>
              <p v-if="s.description" class="muted tiny">{{ s.description }}</p>
            </td>
            <td class="num">{{ money(s.suggested_price) }}</td>
            <td class="actions-col">
              <button type="button" class="link ok" @click="openSuggestionApprove(s)">Revisar y aprobar</button>
              <button type="button" class="link danger" @click="onRejectSuggestion(s)">Descartar</button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-else class="muted pad">No hay propuestas pendientes.</p>
    </div>

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
                Precio base<span class="sort-ind" aria-hidden="true">{{ catalogSortIndicator('base_price') }}</span>
              </button>
            </th>
            <th>Estado</th>
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
            <td>
              <strong>{{ r.name }}</strong>
              <p v-if="r.description" class="muted tiny">{{ r.description }}</p>
            </td>
            <td class="num">{{ money(r.base_price) }}</td>
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
            <td colspan="5" class="empty muted">Sin ítems. Cree el primero.</td>
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
          2000 filas; archivo hasta 5&nbsp;MB. Los nombres duplicados en el mismo ámbito (global o empresa) se omiten.
        </p>
        <div class="import-row">
          <label class="import-field">
            <span>Ámbito</span>
            <select v-model="importCompanyId" class="input" :disabled="importBusy">
              <option value="">Global (todas las empresas)</option>
              <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
            </select>
          </label>
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
        <h2 class="pricing-title">Vista de precios para técnicos</h2>
        <p class="pricing-lede">
          En el registro de servicios, el empleado ve el catálogo con un descuento sobre el precio de lista (por defecto 10&nbsp;%).
          El valor facturable sigue siendo el que definas en cada ítem; esto solo afecta la referencia mostrada al técnico.
        </p>
        <div class="pricing-row">
          <label class="pricing-label">
            <span>Descuento (%)</span>
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
            {{ techDiscountSaving ? 'Guardando…' : 'Guardar descuento' }}
          </button>
        </div>
        <p v-if="techDiscountError" class="banner err inline">{{ techDiscountError }}</p>
        <p v-else-if="techDiscountOk" class="banner ok inline">{{ techDiscountOk }}</p>
      </div>
    </div>

    <Teleport to="body">
      <div v-if="showSuggestionModal" class="modal-backdrop" @click.self="showSuggestionModal = false">
        <div class="modal card" role="dialog" aria-labelledby="sugg-modal-title">
          <h2 id="sugg-modal-title">Revisar propuesta y dar de alta</h2>
          <p class="modal-lede">
            Ajusta nombre, descripción o precio si hace falta. Al confirmar, el ítem queda en el catálogo global (disponible para cualquier empresa).
          </p>
          <form class="modal-form" @submit.prevent="onSubmitSuggestionApprove">
            <label>
              <span>Nombre del ítem</span>
              <input v-model="suggestionForm.name" required class="input" maxlength="255" />
            </label>
            <p v-if="suggestionFieldErrors.name?.[0]" class="field-err">{{ suggestionFieldErrors.name[0] }}</p>
            <label>
              <span>Descripción (opcional)</span>
              <textarea v-model="suggestionForm.description" class="input" rows="3" />
            </label>
            <label>
              <span>Precio base (COP)</span>
              <input v-model="suggestionForm.base_price" type="number" min="0.01" step="0.01" required class="input" />
            </label>
            <p v-if="suggestionFieldErrors.base_price?.[0]" class="field-err">{{ suggestionFieldErrors.base_price[0] }}</p>
            <div class="modal-actions">
              <button type="button" class="btn secondary" @click="showSuggestionModal = false">Cancelar</button>
              <button type="submit" class="btn primary" :disabled="suggestionSaving">
                {{ suggestionSaving ? 'Guardando…' : 'Dar de alta en catálogo' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
        <div class="modal card">
          <h2>{{ editingId ? 'Editar ítem' : 'Nuevo ítem' }}</h2>
          <form class="modal-form" @submit.prevent="onSave">
            <label>
              <span>Empresa</span>
              <select v-model="form.company_id" class="input">
                <option value="">Global (todas las empresas)</option>
                <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
              </select>
              <small class="hint">Vacío = ítem global; si no, solo aplica a esa empresa.</small>
            </label>
            <label>
              <span>Nombre</span>
              <input v-model="form.name" required class="input" maxlength="255" />
            </label>
            <label>
              <span>Descripción (opcional)</span>
              <textarea v-model="form.description" class="input" rows="3" />
            </label>
            <label>
              <span>Precio base (COP)</span>
              <input v-model="form.base_price" type="number" min="0.01" step="0.01" required class="input" />
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
.page {
  max-width: 960px;
  margin: 0 auto;
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
.pending-block {
  margin-bottom: 1rem;
}
.pending-title {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
  color: #e2e8f0;
}
.pending-sub {
  margin: 0 0 0.85rem;
  font-size: 0.82rem;
  line-height: 1.45;
  max-width: 44rem;
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
