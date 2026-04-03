<script setup>
import { onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  approveServiceCatalogSuggestion,
  createServiceCatalogItem,
  fetchAdminServiceCatalog,
  fetchCompanies,
  fetchServiceCatalogSuggestions,
  fetchTechnicianCatalogDiscount,
  patchServiceCatalogEstado,
  rejectServiceCatalogSuggestion,
  updateServiceCatalogItem,
  updateTechnicianCatalogDiscount,
} from '@/services/servicesApi.js'

const route = useRoute()

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

const showPendientes = () => route.query.pendientes === '1'

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
    const res = await fetchAdminServiceCatalog({ per_page: 100 })
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
  if (!showPendientes()) {
    pendingRows.value = []
    return
  }
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

watch(
  () => route.query.pendientes,
  () => loadPending()
)

watch(showModal, (open) => {
  if (!open) {
    editingId.value = null
    form.value = { company_id: '', name: '', description: '', base_price: '', status: 'activo' }
  }
})

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

function empresaLabel(row) {
  if (row.company?.nombre) return row.company.nombre
  if (row.company_id != null) return `#${row.company_id}`
  return 'Global'
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

async function onApproveSuggestion(s) {
  if (!window.confirm(`¿Aprobar «${s.name}» y darlo de alta en el catálogo de la empresa?`)) return
  try {
    await approveServiceCatalogSuggestion(s.id)
    await loadPending()
    await load()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo aprobar.'
  }
}

async function onRejectSuggestion(s) {
  if (!window.confirm(`¿Descartar la propuesta «${s.name}»?`)) return
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
          Precios base por empresa o globales. Las propuestas «Otro» de los técnicos aparecen cuando abres el enlace con
          <code class="code">?pendientes=1</code>.
        </p>
      </div>
      <button type="button" class="btn primary" @click="openCreate">+ Nuevo ítem</button>
    </header>

    <div class="card pricing-card">
      <h2 class="pricing-title">Vista de precios para técnicos</h2>
      <p class="pricing-lede">
        En el registro de servicios, el empleado ve el catálogo con un descuento sobre el precio de lista (por defecto 10&nbsp;%).
        El valor facturable sigue siendo el que definas aquí en cada ítem; esto solo afecta la referencia mostrada al técnico.
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

    <p v-if="error" class="banner err">{{ error }}</p>

    <div v-if="showPendientes()" class="card pending-block">
      <h2 class="pending-title">Propuestas de catálogo pendientes</h2>
      <p v-if="pendingLoading" class="muted pad">Cargando…</p>
      <table v-else-if="pendingRows.length" class="table">
        <thead>
          <tr>
            <th>Empresa</th>
            <th>Nombre propuesto</th>
            <th class="num">Precio sugerido</th>
            <th class="actions-col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="s in pendingRows" :key="s.id">
            <td>{{ s.company?.nombre || '—' }}</td>
            <td>
              <strong>{{ s.name }}</strong>
              <p v-if="s.description" class="muted tiny">{{ s.description }}</p>
            </td>
            <td class="num">{{ money(s.suggested_price) }}</td>
            <td class="actions-col">
              <button type="button" class="link ok" @click="onApproveSuggestion(s)">Aprobar</button>
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
            <th>Nombre</th>
            <th>Empresa</th>
            <th class="num">Precio base</th>
            <th>Estado</th>
            <th class="actions-col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td>
              <strong>{{ r.name }}</strong>
              <p v-if="r.description" class="muted tiny">{{ r.description }}</p>
            </td>
            <td>{{ empresaLabel(r) }}</td>
            <td class="num">{{ money(r.base_price) }}</td>
            <td>
              <span class="pill" :data-st="r.status">{{ r.status === 'activo' ? 'Activo' : 'Inactivo' }}</span>
            </td>
            <td class="actions-col">
              <button type="button" class="link" @click="openEdit(r)">Editar</button>
              <button type="button" class="link" @click="toggleStatus(r)">
                {{ r.status === 'activo' ? 'Desactivar' : 'Activar' }}
              </button>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="5" class="empty muted">Sin ítems. Cree el primero.</td>
          </tr>
        </tbody>
      </table>
    </div>

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
  gap: 1rem;
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
.pricing-card {
  margin-bottom: 1rem;
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
  margin: 0 0 0.75rem;
  font-size: 1.05rem;
  color: #e2e8f0;
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
