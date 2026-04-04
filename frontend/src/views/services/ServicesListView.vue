<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import AdminServiceDetailPanel from '@/components/admin/AdminServiceDetailPanel.vue'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import { downloadAdminExportCsv } from '@/services/invoicesApi.js'
import { archiveService, fetchCompanies, fetchEmpleados, fetchServices } from '@/services/servicesApi.js'

const auth = useAuthStore()
const router = useRouter()

const companies = ref([])
const empleados = ref([])
const rows = ref([])
const meta = ref(null)
const links = ref(null)
const loading = ref(false)
const exportBusy = ref(false)
const error = ref('')
const archivingId = ref(null)

/** Modal eliminar servicio: confirmación escribiendo código de factura o de servicio. */
const deleteModalOpen = ref(false)
const deleteTarget = ref(null)
const deleteConfirmInput = ref('')
const deleteModalError = ref('')
const deleteInputRef = ref(null)

const detailPanelOpen = ref(false)
const detailServiceId = ref(null)

function openDetailPanel(row) {
  detailServiceId.value = row.id
  detailPanelOpen.value = true
}

function closeDetailPanel() {
  detailPanelOpen.value = false
  detailServiceId.value = null
}

const deleteExpectedCode = computed(() => {
  const t = deleteTarget.value
  if (!t) return ''
  const inv = t.invoices?.[0]
  return inv?.code ?? t.code ?? ''
})

const deleteUsesInvoiceCode = computed(() => Boolean(deleteTarget.value?.invoices?.[0]?.code))

const filters = ref({
  company_id: '',
  user_id: '',
  service_date_from: '',
  service_date_to: '',
  q: '',
  page: 1,
})

const isAdmin = computed(() => isAdminPanelRole(auth.user?.rol))
/** Técnico: editar / eliminar propios servicios (misma columna que admin). */
const showServiceActions = computed(
  () => isAdmin.value || auth.user?.rol === 'empleado'
)
const tableColspan = computed(() => (isAdmin.value ? 9 : showServiceActions.value ? 8 : 7))

/** Columnas ordenables (coinciden con `sort` en la API). */
const SORT_DEFAULT_DIR = {
  code: 'asc',
  service_date: 'desc',
  company_nombre: 'asc',
  client_name: 'asc',
  description: 'asc',
  user_nombre: 'asc',
  amount: 'desc',
  status: 'asc',
}

const sortKey = ref('')
const sortDir = ref('desc')

const listTitle = computed(() => (isAdmin.value ? 'Listado de servicios' : 'Mis servicios'))

const nuevoServicioTo = computed(() =>
  isAdmin.value ? '/admin/servicios/nuevo' : '/empleado/registro-servicio'
)

function detailPath(id) {
  return isAdmin.value ? `/admin/servicios/${id}` : `/empleado/servicio/${id}`
}

function editPath(id) {
  return isAdmin.value ? `/admin/servicios/${id}/editar` : `/empleado/servicio/${id}/editar`
}

function invoiceDetailPath(invoiceId) {
  return `/admin/facturas/${invoiceId}`
}

function openDeleteModal(s) {
  if (s.status === 'eliminado' || archivingId.value != null) return
  deleteTarget.value = s
  deleteConfirmInput.value = ''
  deleteModalError.value = ''
  deleteModalOpen.value = true
}

function closeDeleteModal() {
  deleteModalOpen.value = false
  deleteTarget.value = null
  deleteConfirmInput.value = ''
  deleteModalError.value = ''
}

async function submitDeleteModal() {
  const t = deleteTarget.value
  if (!t || archivingId.value != null) return
  const expected = deleteExpectedCode.value
  const typed = deleteConfirmInput.value.trim()
  if (!expected || typed !== expected) {
    deleteModalError.value =
      'El texto no coincide exactamente con el código indicado (mayúsculas, números y guiones).'
    return
  }
  deleteModalError.value = ''
  archivingId.value = t.id
  try {
    await archiveService(t.id)
    closeDeleteModal()
    if (detailPanelOpen.value && String(detailServiceId.value) === String(t.id)) {
      closeDetailPanel()
    }
    await load()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo eliminar el servicio.'
  } finally {
    archivingId.value = null
  }
}

function onGlobalEscape(ev) {
  if (ev.key !== 'Escape') return
  if (deleteModalOpen.value) {
    ev.preventDefault()
    closeDeleteModal()
    return
  }
  if (isAdmin.value && detailPanelOpen.value) {
    ev.preventDefault()
    closeDetailPanel()
  }
}

watch(deleteModalOpen, async (open) => {
  if (open) {
    await nextTick()
    deleteInputRef.value?.focus()
  }
})

function onRowClick(id, ev) {
  if (isAdmin.value) return
  openRow(id, ev)
}

function onRowEnter(id) {
  if (!isAdmin.value) router.push(detailPath(id))
}

let searchTimer = null
let filterTimer = null

async function load() {
  error.value = ''
  loading.value = true
  try {
    const params = { page: filters.value.page, per_page: 15 }
    if (filters.value.company_id) params.company_id = filters.value.company_id
    if (isAdmin.value && filters.value.user_id) params.user_id = filters.value.user_id
    if (filters.value.service_date_from) params.service_date_from = filters.value.service_date_from
    if (filters.value.service_date_to) params.service_date_to = filters.value.service_date_to
    if (filters.value.q.trim()) params.q = filters.value.q.trim()
    if (sortKey.value) {
      params.sort = sortKey.value
      params.sort_dir = sortDir.value
    }

    const res = await fetchServices(params)
    rows.value = res.data
    meta.value = res.meta
    links.value = res.links
  } catch (e) {
    error.value = e.data?.message || e.message || 'Error al cargar servicios.'
  } finally {
    loading.value = false
  }
}

function scheduleFilterLoad() {
  clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    filters.value.page = 1
    load()
  }, 400)
}

onMounted(async () => {
  document.addEventListener('keydown', onGlobalEscape)
  try {
    companies.value = await fetchCompanies()
    if (isAdmin.value) {
      empleados.value = await fetchEmpleados()
    }
  } catch {
    /* filtros opcionales */
  }
  await load()
})

onUnmounted(() => {
  document.removeEventListener('keydown', onGlobalEscape)
  clearTimeout(searchTimer)
  clearTimeout(filterTimer)
})

watch(
  () => filters.value.q,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filters.value.page = 1
      load()
    }, 350)
  }
)

watch(
  () => [
    filters.value.company_id,
    filters.value.user_id,
    filters.value.service_date_from,
    filters.value.service_date_to,
  ],
  () => scheduleFilterLoad()
)

function goPage(p) {
  filters.value.page = p
  load()
}

function toggleSort(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = SORT_DEFAULT_DIR[key] ?? 'asc'
  }
  filters.value.page = 1
  load()
}

function thAriaSort(key) {
  return sortKey.value === key ? (sortDir.value === 'asc' ? 'ascending' : 'descending') : undefined
}

function sortIndicator(key) {
  if (sortKey.value !== key) return ''
  return sortDir.value === 'asc' ? '▲' : '▼'
}

function openRow(id, ev) {
  if (ev?.target?.closest?.('a, button, .icon-act')) return
  router.push(detailPath(id))
}

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return v
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

function clip(s, n = 72) {
  if (!s) return '—'
  return s.length > n ? `${s.slice(0, n)}…` : s
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso + (iso.length === 10 ? 'T12:00:00' : ''))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

/** Etiqueta de estado para la tabla (API usa slugs en minúsculas). */
function estadoLabel(status) {
  const m = { activo: 'Activo', corregido: 'Corregido', eliminado: 'Eliminado' }
  return m[status] ?? status ?? '—'
}

const pageSummary = computed(() => {
  const m = meta.value
  if (!m || !m.total) return ''
  return `${m.from ?? 0}–${m.to ?? 0} de ${m.total} servicio(s)`
})

async function exportServicesCsv() {
  if (!isAdmin.value) return
  error.value = ''
  exportBusy.value = true
  try {
    const params = {}
    if (filters.value.company_id) params.company_id = filters.value.company_id
    if (filters.value.user_id) params.user_id = filters.value.user_id
    if (filters.value.service_date_from) params.service_date_from = filters.value.service_date_from
    if (filters.value.service_date_to) params.service_date_to = filters.value.service_date_to
    const { blob, filename } = await downloadAdminExportCsv('/admin/export/services', params)
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = filename
    a.click()
    URL.revokeObjectURL(a.href)
  } catch (e) {
    error.value = e.message || 'No se pudo exportar.'
  } finally {
    exportBusy.value = false
  }
}
</script>

<template>
  <section class="page page--fluid">
    <header class="head">
      <div>
        <h1>{{ listTitle }}</h1>
        <p class="lede">
          {{
            isAdmin
              ? 'Clic en el código de servicio abre el panel lateral con el detalle; debajo, el código de factura (si existe) enlaza a la factura.'
              : 'Solo tus servicios visibles. Toca una fila para ver el detalle.'
          }}
        </p>
      </div>
      <div class="head-btns">
        <button
          v-if="isAdmin"
          type="button"
          class="btn secondary"
          :disabled="exportBusy"
          @click="exportServicesCsv"
        >
          {{ exportBusy ? 'Exportando…' : 'Exportar CSV (Excel)' }}
        </button>
        <RouterLink class="btn primary" :to="nuevoServicioTo">+ Nuevo servicio</RouterLink>
      </div>
    </header>

    <p v-if="error" class="banner" role="alert">{{ error }}</p>

    <div class="filters card">
      <label>
        <span>Empresa</span>
        <select v-model="filters.company_id">
          <option value="">Todas</option>
          <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
        </select>
      </label>
      <label v-if="isAdmin">
        <span>Empleado / técnico</span>
        <select v-model="filters.user_id">
          <option value="">Todos</option>
          <option v-for="u in empleados" :key="u.id" :value="String(u.id)">{{ u.nombre }}</option>
        </select>
      </label>
      <label>
        <span>Fecha desde</span>
        <input v-model="filters.service_date_from" type="date" />
      </label>
      <label>
        <span>Fecha hasta</span>
        <input v-model="filters.service_date_to" type="date" />
      </label>
      <label class="grow">
        <span>Búsqueda (código, cliente, descripción, tipo)</span>
        <input v-model="filters.q" type="search" placeholder="Ej. 20260329, SYF, instalación…" />
      </label>
    </div>
    <p class="filter-hint muted">
      <template v-if="isAdmin">
        Los filtros y la búsqueda se aplican automáticamente al cambiar valores. El listado incluye servicios
        eliminados; se distinguen por el estado «Eliminado». Pulsa un encabezado de columna para ordenar (▲/▼).
      </template>
      <template v-else>
        Los filtros y la búsqueda se aplican automáticamente al cambiar valores. Pulsa un encabezado de columna para
        ordenar (▲/▼).
      </template>
    </p>

    <div class="card table-wrap">
      <div v-if="loading" class="muted pad">Cargando…</div>
      <template v-else>
        <p v-if="pageSummary" class="meta-line muted">{{ pageSummary }}</p>
        <table class="table">
          <thead>
            <tr>
              <th scope="col" :aria-sort="thAriaSort('code')">
                <button type="button" class="th-sort" @click="toggleSort('code')">
                  Código<span class="sort-ind" aria-hidden="true">{{ sortIndicator('code') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="thAriaSort('service_date')">
                <button type="button" class="th-sort" @click="toggleSort('service_date')">
                  Fecha<span class="sort-ind" aria-hidden="true">{{ sortIndicator('service_date') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="thAriaSort('company_nombre')">
                <button type="button" class="th-sort" @click="toggleSort('company_nombre')">
                  Empresa<span class="sort-ind" aria-hidden="true">{{ sortIndicator('company_nombre') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="thAriaSort('client_name')">
                <button type="button" class="th-sort" @click="toggleSort('client_name')">
                  Cliente<span class="sort-ind" aria-hidden="true">{{ sortIndicator('client_name') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="thAriaSort('description')">
                <button type="button" class="th-sort" @click="toggleSort('description')">
                  Descripción<span class="sort-ind" aria-hidden="true">{{ sortIndicator('description') }}</span>
                </button>
              </th>
              <th v-if="isAdmin" scope="col" :aria-sort="thAriaSort('user_nombre')">
                <button type="button" class="th-sort" @click="toggleSort('user_nombre')">
                  Técnico<span class="sort-ind" aria-hidden="true">{{ sortIndicator('user_nombre') }}</span>
                </button>
              </th>
              <th scope="col" class="num" :aria-sort="thAriaSort('amount')">
                <button type="button" class="th-sort th-sort--end" @click="toggleSort('amount')">
                  Valor<span class="sort-ind" aria-hidden="true">{{ sortIndicator('amount') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="thAriaSort('status')">
                <button type="button" class="th-sort" @click="toggleSort('status')">
                  Estado<span class="sort-ind" aria-hidden="true">{{ sortIndicator('status') }}</span>
                </button>
              </th>
              <th v-if="showServiceActions" scope="col" class="actions-col">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="s in rows"
              :key="s.id"
              class="row-data"
              :class="{ 'row-data--clickable': !isAdmin }"
              :tabindex="isAdmin ? undefined : 0"
              :role="isAdmin ? undefined : 'link'"
              :title="isAdmin ? undefined : 'Ver detalle ' + s.code"
              @click="onRowClick(s.id, $event)"
              @keydown.enter.prevent="onRowEnter(s.id)"
            >
              <td class="code-cell">
                <div class="code-cell-inner">
                  <button
                    v-if="isAdmin"
                    type="button"
                    class="code-link"
                    @click.stop="openDetailPanel(s)"
                  >
                    {{ s.code }}
                  </button>
                  <span v-else class="link">{{ s.code }}</span>
                  <template v-if="isAdmin && s.invoices?.length">
                    <RouterLink
                      v-for="inv in s.invoices"
                      :key="inv.id"
                      class="link link-invoice code-cell__invoice"
                      :to="invoiceDetailPath(inv.id)"
                      :title="'Ver factura ' + inv.code"
                      @click.stop
                    >
                      {{ inv.code }}
                    </RouterLink>
                  </template>
                </div>
              </td>
              <td>{{ formatDate(s.service_date) }}</td>
              <td>{{ s.company?.nombre || '—' }}</td>
              <td>{{ s.client_name || '—' }}</td>
              <td class="desc">{{ clip(s.description) }}</td>
              <td v-if="isAdmin">{{ s.empleado?.nombre || '—' }}</td>
              <td class="num">{{ money(s.amount) }}</td>
              <td><span class="pill" :data-st="s.status">{{ estadoLabel(s.status) }}</span></td>
              <td v-if="showServiceActions" class="actions-col" @click.stop>
                <div class="actions-icons">
                  <RouterLink
                    class="icon-act"
                    :to="editPath(s.id)"
                    title="Editar servicio"
                    aria-label="Editar servicio"
                  >
                    <svg class="icon-svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                      />
                    </svg>
                  </RouterLink>
                  <button
                    type="button"
                    class="icon-act icon-act--danger"
                    :disabled="archivingId === s.id || s.status === 'eliminado'"
                    title="Eliminar servicio (marcar como eliminado)"
                    aria-label="Eliminar servicio"
                    @click="openDeleteModal(s)"
                  >
                    <svg class="icon-svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                      />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td :colspan="tableColspan" class="muted center empty-msg">
                No hay servicios con los filtros actuales. Prueba ampliar fechas o limpiar la búsqueda.
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="meta && meta.last_page > 1" class="pager">
          <button type="button" class="btn secondary" :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">
            Anterior
          </button>
          <span class="muted">Página {{ meta.current_page }} / {{ meta.last_page }}</span>
          <button
            type="button"
            class="btn secondary"
            :disabled="meta.current_page >= meta.last_page"
            @click="goPage(meta.current_page + 1)"
          >
            Siguiente
          </button>
        </div>
      </template>
    </div>

    <Teleport to="body">
      <div
        v-if="deleteModalOpen && deleteTarget"
        class="modal-backdrop"
        role="presentation"
        @click.self="closeDeleteModal"
      >
        <div
          class="modal-panel"
          role="dialog"
          aria-modal="true"
          aria-labelledby="delete-modal-title"
          @click.stop
        >
          <h2 id="delete-modal-title" class="modal-title">Confirmar eliminación del servicio</h2>
          <p class="modal-lede">
            Vas a marcar como <strong>eliminado</strong> el servicio. Revisa los datos y escribe el código que se
            indica abajo para confirmar.
          </p>
          <dl class="modal-dl">
            <div><dt>Servicio</dt><dd>{{ deleteTarget.code }}</dd></div>
            <div><dt>Empresa</dt><dd>{{ deleteTarget.company?.nombre || '—' }}</dd></div>
            <div><dt>Cliente</dt><dd>{{ deleteTarget.client_name || '—' }}</dd></div>
            <div><dt>Valor</dt><dd>{{ money(deleteTarget.amount) }}</dd></div>
            <div><dt>Estado</dt><dd>{{ estadoLabel(deleteTarget.status) }}</dd></div>
          </dl>
          <div v-if="deleteUsesInvoiceCode" class="modal-highlight">
            <p class="modal-highlight-label">Factura asociada (escribe este código exacto)</p>
            <p class="modal-highlight-code" aria-live="polite">{{ deleteExpectedCode }}</p>
          </div>
          <div v-else class="modal-highlight modal-highlight--muted">
            <p class="modal-highlight-label">Sin factura asociada</p>
            <p class="modal-highlight-code">{{ deleteExpectedCode }}</p>
            <p class="modal-hint muted">Confirma escribiendo el <strong>código del servicio</strong> mostrado arriba.</p>
          </div>
          <label class="modal-field">
            <span>Confirmación</span>
            <input
              ref="deleteInputRef"
              v-model="deleteConfirmInput"
              type="text"
              autocomplete="off"
              :placeholder="deleteUsesInvoiceCode ? 'Código de factura' : 'Código del servicio'"
              @keydown.enter.prevent="submitDeleteModal"
            />
          </label>
          <p v-if="deleteModalError" class="modal-error" role="alert">{{ deleteModalError }}</p>
          <div class="modal-actions">
            <button type="button" class="btn secondary" :disabled="archivingId != null" @click="closeDeleteModal">
              Cancelar
            </button>
            <button
              type="button"
              class="btn danger"
              :disabled="archivingId != null || !deleteConfirmInput.trim()"
              @click="submitDeleteModal"
            >
              {{ archivingId != null ? 'Eliminando…' : 'Eliminar servicio' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <AdminServiceDetailPanel
      v-if="isAdmin"
      :open="detailPanelOpen"
      :service-id="detailServiceId"
      @close="closeDetailPanel"
    />
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
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.head-btns {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}

.head h1 {
  margin: 0;
  font-size: 1.35rem;
}

.lede {
  margin: 0.35rem 0 0;
  font-size: 0.875rem;
  color: #94a3b8;
  max-width: min(48rem, 100%);
  line-height: 1.45;
}

.banner {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}

.filter-hint {
  font-size: 0.78rem;
  margin: -0.35rem 0 1rem;
}

.card {
  padding: 1rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 1rem;
}

.filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr));
  gap: 0.75rem;
  align-items: end;
}

.filters label span {
  display: block;
  font-size: 0.8rem;
  color: #94a3b8;
  margin-bottom: 0.25rem;
}

.filters input,
.filters select {
  width: 100%;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.35);
  color: #f8fafc;
  padding: 0.45rem 0.6rem;
}

.grow {
  grid-column: span 2;
}

@media (max-width: 720px) {
  .grow {
    grid-column: span 1;
  }
}

.table-wrap {
  width: 100%;
  min-width: 0;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  /* Evita que el borde derecho de la última columna se recorte 1px con el scroll. */
  padding-inline-end: 2px;
}

.meta-line {
  font-size: 0.8rem;
  margin: 0 0 0.65rem;
}

.table {
  width: 100%;
  border-collapse: collapse;
  border-spacing: 0;
  font-size: 0.9rem;
}

.table th,
.table td {
  padding: 0.55rem 0.45rem;
  text-align: left;
  vertical-align: top;
}

/* Una línea continua por fila (evita saltos al usar flex u otros displays en celdas sueltas). */
.table thead tr {
  border-bottom: 1px solid rgba(148, 163, 184, 0.42);
}

.table thead th {
  border-bottom: none;
}

.table tbody tr {
  border-bottom: 1px solid rgba(148, 163, 184, 0.3);
}

.table tbody td {
  border-bottom: none;
}

.table th {
  color: #94a3b8;
  font-weight: 600;
}

.table thead th:first-child {
  white-space: nowrap;
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
  opacity: 0.85;
  white-space: nowrap;
}

.row-data {
  transition: background 0.12s ease;
}

.row-data--clickable {
  cursor: pointer;
}

.row-data--clickable:hover {
  background: rgba(56, 189, 248, 0.06);
}

.row-data--clickable:focus-visible {
  outline: 2px solid rgba(56, 189, 248, 0.5);
  outline-offset: -2px;
}

.desc {
  max-width: min(36rem, 42vw);
  word-break: break-word;
}

@media (max-width: 900px) {
  .desc {
    max-width: none;
  }
}

.num {
  white-space: nowrap;
  text-align: right;
}

.link {
  color: #7dd3fc;
  font-weight: 600;
  text-decoration: none;
}

.link:hover {
  text-decoration: underline;
}

.code-link {
  margin: 0;
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  font-family: ui-monospace, monospace;
  font-size: inherit;
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

/* No usar flex en el <td>: rompe la altura de fila y desalinea bordes horizontales. */
.code-cell {
  vertical-align: top;
}

.code-cell-inner {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.2rem;
}

/* Código del servicio en una sola línea (el guion no debe partir SERV-…). */
.code-cell-inner > :first-child {
  white-space: nowrap;
  flex-shrink: 0;
}

.code-cell__invoice {
  font-size: 0.8rem;
  font-weight: 500;
  white-space: nowrap;
}

.actions-col {
  white-space: nowrap;
  vertical-align: middle;
  width: 1%;
}

.table thead th.actions-col {
  vertical-align: bottom;
}

.actions-icons {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.15rem;
}

.icon-act {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.35rem;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: #94a3b8;
  cursor: pointer;
  text-decoration: none;
  line-height: 0;
}

.icon-act:hover:not(:disabled) {
  color: #7dd3fc;
  background: rgba(56, 189, 248, 0.08);
}

.icon-act--danger:hover:not(:disabled) {
  color: #fecaca;
  background: rgba(248, 113, 113, 0.1);
}

.icon-act:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.icon-svg {
  width: 1.15rem;
  height: 1.15rem;
}

.tiny {
  font-size: 0.75rem;
}

.pill {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
  font-size: 0.75rem;
  text-transform: capitalize;
  border: 1px solid rgba(148, 163, 184, 0.35);
}

.pill[data-st='activo'] {
  border-color: rgba(74, 222, 128, 0.45);
  color: #bbf7d0;
}
.pill[data-st='corregido'] {
  border-color: rgba(56, 189, 248, 0.45);
  color: #bae6fd;
}
.pill[data-st='eliminado'] {
  border-color: rgba(248, 113, 113, 0.45);
  color: #fecaca;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin-top: 1rem;
}

.muted {
  color: #94a3b8;
}

.pad {
  padding: 1rem;
}

.center {
  text-align: center;
}

.empty-msg {
  padding: 1.5rem 1rem;
  line-height: 1.5;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem 0.9rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  text-decoration: none;
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
  background: rgba(220, 38, 38, 0.22);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
}

.btn.danger:hover:not(:disabled) {
  background: rgba(220, 38, 38, 0.38);
  color: #fff;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(2, 6, 23, 0.75);
  backdrop-filter: blur(5px);
}

.modal-panel {
  width: 100%;
  max-width: 26rem;
  max-height: min(92vh, 100%);
  overflow-y: auto;
  padding: 1.25rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.28);
  background: #1e293b;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}

.modal-title {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
  color: #f8fafc;
}

.modal-lede {
  margin: 0 0 1rem;
  font-size: 0.8rem;
  color: #94a3b8;
  line-height: 1.45;
}

.modal-dl {
  margin: 0 0 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-size: 0.8rem;
}

.modal-dl > div {
  display: grid;
  grid-template-columns: 5.25rem 1fr;
  gap: 0.35rem;
  align-items: baseline;
}

.modal-dl dt {
  margin: 0;
  color: #94a3b8;
  font-weight: 600;
}

.modal-dl dd {
  margin: 0;
  color: #e2e8f0;
  word-break: break-word;
}

.modal-highlight {
  margin: 0 0 1rem;
  padding: 0.75rem 0.85rem;
  border-radius: 10px;
  background: rgba(56, 189, 248, 0.1);
  border: 1px solid rgba(56, 189, 248, 0.38);
}

.modal-highlight--muted {
  background: rgba(148, 163, 184, 0.08);
  border-color: rgba(148, 163, 184, 0.28);
}

.modal-highlight-label {
  margin: 0 0 0.35rem;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #94a3b8;
}

.modal-highlight-code {
  margin: 0;
  font-family: ui-monospace, 'Cascadia Code', monospace;
  font-size: 1.05rem;
  font-weight: 700;
  color: #7dd3fc;
  word-break: break-all;
}

.modal-highlight--muted .modal-highlight-code {
  color: #f1f5f9;
}

.modal-hint {
  margin: 0.5rem 0 0;
  font-size: 0.75rem;
  line-height: 1.4;
}

.modal-field {
  display: block;
  margin-bottom: 0.5rem;
}

.modal-field span {
  display: block;
  font-size: 0.78rem;
  color: #94a3b8;
  margin-bottom: 0.35rem;
}

.modal-field input {
  width: 100%;
  box-sizing: border-box;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.4);
  background: rgba(2, 6, 23, 0.55);
  color: #f8fafc;
  padding: 0.55rem 0.65rem;
  font-size: 0.9rem;
}

.modal-error {
  margin: 0 0 0.75rem;
  font-size: 0.78rem;
  color: #fecaca;
}

.modal-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  justify-content: flex-end;
  margin-top: 0.25rem;
}
</style>
