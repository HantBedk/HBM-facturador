<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import { fetchCompanies } from '@/services/servicesApi.js'
import { fetchEmpleadoHistorialAdmin } from '@/services/employeeHistorialApi.js'
import { useClientSortedRows } from '@/composables/useClientSortedRows.js'

const props = defineProps({
  /** Solo admin: id del empleado en la ruta dinámica */
  userId: { type: String, default: '' },
})

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const isAdmin = computed(() => isAdminPanelRole(auth.user?.rol))

const now = new Date()
const periodYear = ref(now.getFullYear())
const periodMonth = ref(now.getMonth() + 1)

const loading = ref(false)
const error = ref('')
const payload = ref(null)

const companies = ref([])
/** Filtro etapa 3: empresa y texto (query al API, mes ya acotado) */
const filterCompanyId = ref('')
const filterSearch = ref('')
let searchDebounce = null

const employee = computed(() => payload.value?.employee)
const period = computed(() => payload.value?.period)
const summary = computed(() => payload.value?.summary)
const services = computed(() => payload.value?.services || [])
/** Pendiente de abono de referencia (todos los períodos); solo admin en historial. */
const technicianDebt = computed(() => payload.value?.technician_debt)

const {
  sortedRows: sortedHistorialServices,
  toggleSort: toggleHistorialSvcSort,
  sortIndicator: histSvcSortInd,
  ariaSort: histSvcAriaSort,
} = useClientSortedRows(
  services,
  {
    service_date: (s) => s.service_date || '',
    code: (s) => s.code || '',
    company_name: (s) => s.company_name || '',
    service_type: (s) => s.service_type || '',
    description: (s) => s.description || '',
    amount: (s) => Number(s.amount) || 0,
  },
  { initialKey: 'code', initialDir: 'desc' }
)

const detailBase = computed(() => (isAdmin.value ? '/admin/servicios' : '/empleado/servicio'))

const yearOptions = computed(() => {
  const y = now.getFullYear()
  return Array.from({ length: 8 }, (_, i) => y - 3 + i)
})

const monthOptions = computed(() =>
  [
    [1, 'Enero'],
    [2, 'Febrero'],
    [3, 'Marzo'],
    [4, 'Abril'],
    [5, 'Mayo'],
    [6, 'Junio'],
    [7, 'Julio'],
    [8, 'Agosto'],
    [9, 'Septiembre'],
    [10, 'Octubre'],
    [11, 'Noviembre'],
    [12, 'Diciembre'],
  ].map(([value, label]) => ({ value, label }))
)

function parseQueryInt(v, fallback) {
  const n = Number.parseInt(String(v), 10)
  return Number.isFinite(n) ? n : fallback
}

function syncPeriodFromRoute() {
  const q = route.query
  if (q.year != null) periodYear.value = parseQueryInt(q.year, periodYear.value)
  if (q.month != null) periodMonth.value = parseQueryInt(q.month, periodMonth.value)
  if (periodMonth.value < 1) periodMonth.value = 1
  if (periodMonth.value > 12) periodMonth.value = 12
}

function syncFiltersFromRoute() {
  const q = route.query
  if (q.company_id != null && String(q.company_id).trim() !== '') {
    filterCompanyId.value = String(q.company_id)
  } else {
    filterCompanyId.value = ''
  }
  filterSearch.value = q.q != null ? String(q.q) : ''
}

function historialParams() {
  const p = {
    year: periodYear.value,
    month: periodMonth.value,
  }
  if (filterCompanyId.value) p.company_id = filterCompanyId.value
  const t = filterSearch.value.trim()
  if (t) p.q = t
  return p
}

function adminTargetUserId() {
  return props.userId?.trim() || ''
}

async function loadCompaniesList() {
  try {
    companies.value = await fetchCompanies()
  } catch {
    companies.value = []
  }
}

async function loadHistorial() {
  error.value = ''
  payload.value = null

  if (!isAdmin.value) return

  const uid = adminTargetUserId()
  if (!uid) {
    payload.value = null
    return
  }

  loading.value = true
  try {
    const p = historialParams()
    payload.value = await fetchEmpleadoHistorialAdmin(uid, p)
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar el historial.'
  } finally {
    loading.value = false
  }
}

/** Query string alineado con filtros (URL compartible). */
function buildQueryFromState() {
  const q = {
    year: String(periodYear.value),
    month: String(periodMonth.value),
  }
  if (filterCompanyId.value) q.company_id = filterCompanyId.value
  const t = filterSearch.value.trim()
  if (t) q.q = t
  return q
}

function updateRouteQuery() {
  const uid = props.userId?.trim()
  if (!isAdmin.value || !uid) return
  router.replace({ name: 'admin-emp-rendimiento-user', params: { userId: uid }, query: buildQueryFromState() })
}

function shiftMonth(delta) {
  let m = periodMonth.value + delta
  let y = periodYear.value
  while (m < 1) {
    m += 12
    y -= 1
  }
  while (m > 12) {
    m -= 12
    y += 1
  }
  periodMonth.value = m
  periodYear.value = y
  updateRouteQuery()
  loadHistorial()
}

function onFilterCompanyChange() {
  updateRouteQuery()
  loadHistorial()
}

function scheduleSearchReload() {
  clearTimeout(searchDebounce)
  searchDebounce = setTimeout(() => {
    updateRouteQuery()
    loadHistorial()
  }, 400)
}

function onYearMonthSelectChange() {
  updateRouteQuery()
  loadHistorial()
}

onMounted(async () => {
  syncPeriodFromRoute()
  syncFiltersFromRoute()
  await loadCompaniesList()
  await loadHistorial()
})

watch(
  () => props.userId,
  () => {
    if (!isAdmin.value) return
    loadHistorial()
  }
)

onUnmounted(() => {
  clearTimeout(searchDebounce)
})

function formatMoney(value) {
  if (value === undefined || value === null) return '—'
  const n = Number(value)
  if (Number.isNaN(n)) return String(value)
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(n)
}

function formatMoneyAvg(value) {
  if (value === undefined || value === null) return '—'
  const n = Number(value)
  if (Number.isNaN(n)) return String(value)
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(n)
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso.length === 10 ? `${iso}T12:00:00` : iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { dateStyle: 'medium' })
}

function clip(s, n = 64) {
  if (!s) return '—'
  return s.length > n ? `${s.slice(0, n)}…` : s
}

function initialsFromName(name) {
  if (!name || typeof name !== 'string') return '?'
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length >= 2) {
    return `${parts[0][0]}${parts[parts.length - 1][0]}`.toUpperCase()
  }
  if (parts[0].length >= 2) return parts[0].slice(0, 2).toUpperCase()
  return parts[0].toUpperCase()
}

/** Pill de tipo de servicio (estilo tabla referencia). */
function serviceTypeClass(tipo) {
  if (!tipo) return 'pill-tipo--muted'
  const k = String(tipo).toLowerCase()
  if (k.includes('mant')) return 'pill-tipo--blue'
  if (k.includes('instal')) return 'pill-tipo--green'
  if (k.includes('urg') || k.includes('correct')) return 'pill-tipo--red'
  return 'pill-tipo--slate'
}
</script>

<template>
  <section class="perf-page">
    <header class="perf-head">
      <div>
        <p v-if="isAdmin && adminTargetUserId()" class="perf-crumb">
          <RouterLink :to="{ name: 'admin-emp-rendimiento' }">Empleados</RouterLink>
          <span class="perf-crumb-sep" aria-hidden="true">/</span>
          <span class="muted">Rendimiento</span>
        </p>
        <h1 class="perf-title">
          {{ isAdmin ? 'Rendimiento del técnico' : 'Mi historial de servicios' }}
        </h1>
        <p v-if="isAdmin && employee" class="perf-tech-name muted">{{ employee.nombre }}</p>
        <p class="perf-lede">
          <template v-if="isAdmin">
            Métricas del mes y detalle de servicios del técnico. Los filtros y el periodo se reflejan en la URL.
          </template>
          <template v-else>
            Servicios registrados por mes calendario. Use las flechas o los selectores para cambiar de periodo.
          </template>
        </p>
      </div>
    </header>

    <div v-if="isAdmin" class="panel toolbar-panel">
      <div class="toolbar-grid">
        <label class="tb-field tb-search">
          <span class="tb-lbl">Buscar</span>
          <span class="search-wrap">
            <svg class="search-ico" width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
              <path
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M21 21l-4.35-4.35M10.5 18a7.5 7.5 0 110-15 7.5 7.5 0 010 15z"
              />
            </svg>
            <input
              v-model="filterSearch"
              type="search"
              class="tb-input tb-input--search"
              placeholder="Buscar…"
              autocomplete="off"
              @input="scheduleSearchReload"
            />
          </span>
        </label>
        <label class="tb-field">
          <span class="tb-lbl">Empresa</span>
          <select v-model="filterCompanyId" class="tb-input" @change="onFilterCompanyChange">
            <option value="">Todas</option>
            <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
          </select>
        </label>
        <label class="tb-field tb-narrow">
          <span class="tb-lbl">Año</span>
          <select v-model.number="periodYear" class="tb-input" @change="onYearMonthSelectChange">
            <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
          </select>
        </label>
        <label class="tb-field tb-narrow">
          <span class="tb-lbl">Mes</span>
          <select v-model.number="periodMonth" class="tb-input" @change="onYearMonthSelectChange">
            <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
          </select>
        </label>
        <div class="period-nav" role="group" aria-label="Cambiar mes">
          <button type="button" class="nav-chip" :disabled="loading" title="Mes anterior" @click="shiftMonth(-1)">
            ‹
          </button>
          <span class="period-chip">{{ period?.label || '…' }}</span>
          <button type="button" class="nav-chip" :disabled="loading" title="Mes siguiente" @click="shiftMonth(1)">
            ›
          </button>
        </div>
      </div>
    </div>

    <template v-else>
      <div class="panel toolbar-panel">
        <div class="toolbar-grid toolbar-grid--empleado">
          <div class="period-nav period-nav--solo" role="group" aria-label="Cambiar mes">
            <button type="button" class="nav-chip" :disabled="loading" title="Mes anterior" @click="shiftMonth(-1)">
              ‹
            </button>
            <span class="period-chip">{{ period?.label || '…' }}</span>
            <button type="button" class="nav-chip" :disabled="loading" title="Mes siguiente" @click="shiftMonth(1)">
              ›
            </button>
          </div>
          <label class="tb-field tb-narrow">
            <span class="tb-lbl">Año</span>
            <select v-model.number="periodYear" class="tb-input" @change="onYearMonthSelectChange">
              <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
            </select>
          </label>
          <label class="tb-field tb-narrow">
            <span class="tb-lbl">Mes</span>
            <select v-model.number="periodMonth" class="tb-input" @change="onYearMonthSelectChange">
              <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </label>
        </div>
      </div>
    </template>

    <p v-if="error" class="banner-err" role="alert">{{ error }}</p>

    <template v-if="!isAdmin || adminTargetUserId()">
      <p v-if="loading && !payload" class="state-loading muted">Cargando…</p>

      <template v-else-if="payload">
        <div v-if="employee && !adminTargetUserId()" class="panel who-panel">
          <div class="who-avatar" aria-hidden="true">{{ initialsFromName(employee.nombre) }}</div>
          <div class="who-body">
            <div class="who-line1">
              <strong class="who-name">{{ employee.nombre }}</strong>
              <span class="tag-rol" :data-rol="employee.rol">{{ employee.rol_label || employee.rol }}</span>
              <span class="status-row" :data-st="employee.estado">
                <span class="status-dot" />
                {{ employee.estado_label || employee.estado }}
              </span>
            </div>
            <p class="who-mail muted">{{ employee.correo }}</p>
          </div>
        </div>

        <div
          v-if="isAdmin && technicianDebt && Number(technicianDebt.pending_services_count) > 0"
          class="panel debt-ref-panel"
        >
          <h2 class="debt-ref-title">Pendiente de abono (referencia técnico)</h2>
          <p class="debt-ref-lede muted">
            Suma de importes de referencia sin registrar pago en sistema (cualquier fecha de servicio). Marque el pago en el
            detalle de cada servicio.
          </p>
          <div class="debt-ref-row">
            <div>
              <span class="debt-ref-k">Servicios con saldo ref.</span>
              <p class="debt-ref-v">{{ technicianDebt.pending_services_count }}</p>
            </div>
            <div>
              <span class="debt-ref-k">Total ref. pendiente</span>
              <p class="debt-ref-v">{{ formatMoney(technicianDebt.pending_total) }}</p>
            </div>
            <RouterLink
              v-if="employee?.id"
              class="debt-ref-link"
              :to="{ path: '/admin/servicios', query: { user_id: String(employee.id) } }"
            >
              Ver servicios del técnico →
            </RouterLink>
          </div>
        </div>

        <div class="kpi-strip">
          <article class="kpi-card">
            <h3 class="kpi-lbl">Servicios</h3>
            <p class="kpi-val">{{ summary?.services_count ?? 0 }}</p>
          </article>
          <article class="kpi-card kpi-card--accent">
            <h3 class="kpi-lbl">Total generado</h3>
            <p class="kpi-val">{{ formatMoney(summary?.total_amount) }}</p>
          </article>
          <article class="kpi-card">
            <h3 class="kpi-lbl">Promedio / servicio</h3>
            <p class="kpi-val">{{ formatMoneyAvg(summary?.avg_per_service) }}</p>
          </article>
          <article class="kpi-card">
            <h3 class="kpi-lbl">Días con actividad</h3>
            <p class="kpi-val">{{ summary?.distinct_service_days ?? 0 }}</p>
          </article>
          <article class="kpi-card">
            <h3 class="kpi-lbl">Mayor valor</h3>
            <p class="kpi-val">{{ formatMoney(summary?.max_service_amount) }}</p>
          </article>
          <article class="kpi-card">
            <h3 class="kpi-lbl">Día más activo</h3>
            <p v-if="summary?.busiest_day" class="kpi-val kpi-val--sm">
              <template v-if="isAdmin">
                {{ formatDate(summary.busiest_day.date) }}
                <span class="kpi-sub">({{ summary.busiest_day.services_count }})</span>
              </template>
              <template v-else>
                <span class="kpi-sub">{{ summary.busiest_day.services_count }} servicios (día de mayor carga)</span>
              </template>
            </p>
            <p v-else class="kpi-val kpi-val--muted">—</p>
          </article>
        </div>

        <div class="panel table-panel">
          <div class="table-head-row">
            <h2 class="table-section-title">Servicios del periodo</h2>
            <span class="table-meta muted">Orden: pulse un encabezado</span>
          </div>
          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th v-if="isAdmin" scope="col" :aria-sort="histSvcAriaSort('service_date')">
                    <button type="button" class="th-sort" @click="toggleHistorialSvcSort('service_date')">
                      Fecha<span class="sort-ind" aria-hidden="true">{{ histSvcSortInd('service_date') }}</span>
                    </button>
                  </th>
                  <th scope="col" :aria-sort="histSvcAriaSort('code')">
                    <button type="button" class="th-sort" @click="toggleHistorialSvcSort('code')">
                      Código<span class="sort-ind" aria-hidden="true">{{ histSvcSortInd('code') }}</span>
                    </button>
                  </th>
                  <th scope="col" :aria-sort="histSvcAriaSort('company_name')">
                    <button type="button" class="th-sort" @click="toggleHistorialSvcSort('company_name')">
                      Empresa<span class="sort-ind" aria-hidden="true">{{ histSvcSortInd('company_name') }}</span>
                    </button>
                  </th>
                  <th scope="col" :aria-sort="histSvcAriaSort('service_type')">
                    <button type="button" class="th-sort" @click="toggleHistorialSvcSort('service_type')">
                      Tipo<span class="sort-ind" aria-hidden="true">{{ histSvcSortInd('service_type') }}</span>
                    </button>
                  </th>
                  <th scope="col" :aria-sort="histSvcAriaSort('description')">
                    <button type="button" class="th-sort" @click="toggleHistorialSvcSort('description')">
                      Descripción<span class="sort-ind" aria-hidden="true">{{ histSvcSortInd('description') }}</span>
                    </button>
                  </th>
                  <th class="cell-num" scope="col" :aria-sort="histSvcAriaSort('amount')">
                    <button type="button" class="th-sort th-sort--end" @click="toggleHistorialSvcSort('amount')">
                      Valor<span class="sort-ind" aria-hidden="true">{{ histSvcSortInd('amount') }}</span>
                    </button>
                  </th>
                  <th class="cell-actions">Acciones</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in sortedHistorialServices" :key="s.id">
                  <td v-if="isAdmin">{{ formatDate(s.service_date) }}</td>
                  <td class="mono">{{ s.code }}</td>
                  <td>{{ s.company_name || '—' }}</td>
                  <td>
                    <span class="pill-tipo" :class="serviceTypeClass(s.service_type)">{{ s.service_type || '—' }}</span>
                  </td>
                  <td class="cell-desc" :title="s.description">{{ clip(s.description, 72) }}</td>
                  <td class="cell-num">{{ formatMoney(s.amount) }}</td>
                  <td class="cell-actions">
                    <RouterLink class="action-link" :to="`${detailBase}/${s.id}`">Ver detalle</RouterLink>
                  </td>
                </tr>
              </tbody>
            </table>
            <p v-if="!sortedHistorialServices.length" class="empty-state muted">Sin servicios en este mes.</p>
          </div>
        </div>
      </template>
    </template>

  </section>
</template>

<style scoped>
/* Paleta alineada con panel admin / mock empleados */
.perf-page {
  max-width: 1200px;
  margin: 0 auto;
  color: #e2e8f0;
}

.perf-head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem 1.25rem;
  margin-bottom: 1.25rem;
}

.perf-title {
  margin: 0 0 0.35rem;
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: #f8fafc;
}

.perf-crumb {
  margin: 0 0 0.5rem;
  font-size: 0.8rem;
}

.perf-crumb a {
  color: #7dd3fc;
  font-weight: 600;
  text-decoration: none;
}

.perf-crumb a:hover {
  text-decoration: underline;
}

.perf-crumb-sep {
  margin: 0 0.35rem;
  color: #64748b;
}

.perf-tech-name {
  margin: 0 0 0.5rem;
  font-size: 0.95rem;
  font-weight: 600;
  color: #cbd5e1;
}

.perf-lede {
  margin: 0;
  max-width: min(40rem, 100%);
  font-size: 0.875rem;
  color: #94a3b8;
  line-height: 1.5;
}

.muted {
  color: #94a3b8;
}

.debt-ref-panel {
  margin-bottom: 1rem;
  padding: 1rem 1.15rem;
  border-color: rgba(251, 191, 36, 0.35);
  background: rgba(30, 41, 59, 0.95);
}

.debt-ref-title {
  margin: 0 0 0.35rem;
  font-size: 1rem;
  font-weight: 700;
  color: #fde68a;
}

.debt-ref-lede {
  margin: 0 0 0.85rem;
  font-size: 0.8rem;
  line-height: 1.45;
}

.debt-ref-row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 1rem 1.5rem;
}

.debt-ref-k {
  display: block;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #94a3b8;
  margin-bottom: 0.2rem;
}

.debt-ref-v {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 700;
  color: #f8fafc;
}

.debt-ref-link {
  margin-left: auto;
  font-size: 0.88rem;
  font-weight: 600;
  color: #7dd3fc;
  text-decoration: none;
}

.debt-ref-link:hover {
  text-decoration: underline;
}

.panel {
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.18);
  background: #1e293b;
  box-shadow: 0 4px 24px rgba(0, 0, 0, 0.2);
  padding: 1.1rem 1.25rem;
  margin-bottom: 1rem;
}

.toolbar-grid {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.85rem 1rem;
}

.toolbar-grid--empleado {
  align-items: flex-end;
}

.tb-field {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.tb-search {
  flex: 1 1 200px;
  min-width: 180px;
}

.tb-narrow {
  flex: 0 0 auto;
  min-width: 7.5rem;
}

.tb-lbl {
  font-size: 0.78rem;
  font-weight: 500;
  color: #94a3b8;
  margin-bottom: 0.35rem;
}

.tb-input {
  width: 100%;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.28);
  background: rgba(15, 23, 42, 0.65);
  color: #f8fafc;
  padding: 0.5rem 0.65rem;
  font: inherit;
  font-size: 0.875rem;
}

.tb-input:focus {
  outline: none;
  border-color: #3b82f6;
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
}

.search-wrap {
  position: relative;
  display: block;
}

.search-ico {
  position: absolute;
  left: 0.65rem;
  top: 50%;
  transform: translateY(-50%);
  color: #64748b;
  pointer-events: none;
}

.tb-input--search {
  padding-left: 2.25rem;
}

.period-nav {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  flex: 0 0 auto;
  margin-left: auto;
}

.period-nav--solo {
  margin-left: 0;
}

.nav-chip {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 10px;
  border: 1px solid rgba(59, 130, 246, 0.45);
  background: rgba(59, 130, 246, 0.12);
  color: #93c5fd;
  font-size: 1.2rem;
  line-height: 1;
  cursor: pointer;
}

.nav-chip:hover:not(:disabled) {
  background: rgba(59, 130, 246, 0.22);
  color: #fff;
}

.nav-chip:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.period-chip {
  padding: 0.45rem 0.75rem;
  border-radius: 10px;
  background: rgba(15, 23, 42, 0.5);
  border: 1px solid rgba(148, 163, 184, 0.2);
  font-size: 0.8rem;
  font-weight: 600;
  color: #f1f5f9;
  white-space: nowrap;
}

.banner-err {
  padding: 0.65rem 0.9rem;
  border-radius: 10px;
  margin-bottom: 1rem;
  background: rgba(239, 68, 68, 0.12);
  border: 1px solid rgba(239, 68, 68, 0.4);
  color: #fecaca;
  font-size: 0.875rem;
}

.state-loading {
  padding: 1.5rem;
  text-align: center;
}

.who-panel {
  display: flex;
  align-items: center;
  gap: 1rem;
}

.who-avatar {
  flex-shrink: 0;
  width: 3rem;
  height: 3rem;
  border-radius: 999px;
  background: linear-gradient(135deg, #3b82f6, #6366f1);
  color: #fff;
  font-weight: 700;
  font-size: 0.95rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 2px solid rgba(148, 163, 184, 0.25);
}

.who-body {
  min-width: 0;
}

.who-line1 {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.65rem;
}

.who-name {
  font-size: 1.05rem;
  color: #f8fafc;
}

.who-mail {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
}

.tag-rol {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 600;
}

.tag-rol[data-rol='admin'],
.tag-rol[data-rol='super_admin'] {
  color: #86efac;
  border: 1px solid rgba(34, 197, 94, 0.45);
  background: rgba(34, 197, 94, 0.12);
}

.tag-rol[data-rol='empleado'] {
  color: #7dd3fc;
  border: 1px solid rgba(56, 189, 248, 0.4);
  background: rgba(56, 189, 248, 0.1);
}

.tag-rol:not([data-rol='admin']):not([data-rol='super_admin']):not([data-rol='empleado']) {
  color: #cbd5e1;
  border: 1px solid rgba(148, 163, 184, 0.35);
}

.status-row {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.78rem;
  font-weight: 600;
}

.status-dot {
  width: 0.5rem;
  height: 0.5rem;
  border-radius: 50%;
  background: #64748b;
}

.status-row[data-st='activo'] {
  color: #86efac;
}

.status-row[data-st='activo'] .status-dot {
  background: #22c55e;
  box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.25);
}

.status-row[data-st='inactivo'] {
  color: #fca5a5;
}

.status-row[data-st='inactivo'] .status-dot {
  background: #ef4444;
  box-shadow: 0 0 0 3px rgba(239, 68, 68, 0.2);
}

.kpi-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.kpi-card {
  border-radius: 12px;
  border: 1px solid rgba(148, 163, 184, 0.15);
  background: #1e293b;
  padding: 0.9rem 1rem;
}

.kpi-card--accent .kpi-val {
  color: #3b82f6;
}

.kpi-lbl {
  margin: 0;
  font-size: 0.65rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #94a3b8;
}

.kpi-val {
  margin: 0.4rem 0 0;
  font-size: 1.2rem;
  font-weight: 700;
  color: #f8fafc;
}

.kpi-val--sm {
  font-size: 0.95rem;
}

.kpi-val--muted {
  color: #64748b;
}

.kpi-sub {
  display: block;
  margin-top: 0.2rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #94a3b8;
}

.table-panel {
  padding: 0;
  overflow: hidden;
}

.table-head-row {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 1rem 1.25rem 0.65rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
}

.table-section-title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 600;
  color: #f1f5f9;
}

.table-meta {
  font-size: 0.78rem;
}

.table-scroll {
  overflow-x: auto;
}

.data-table {
  width: 100%;
  min-width: 760px;
  border-collapse: collapse;
  font-size: 0.875rem;
}

.data-table thead th {
  padding: 0.75rem 1rem;
  text-align: left;
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #94a3b8;
  background: rgba(15, 23, 42, 0.4);
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
}

.data-table tbody tr:nth-child(even) {
  background: rgba(15, 23, 42, 0.35);
}

.data-table tbody tr:hover {
  background: rgba(59, 130, 246, 0.08);
}

.data-table td {
  padding: 0.7rem 1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.08);
  vertical-align: middle;
  color: #e2e8f0;
}

.mono {
  font-family: ui-monospace, 'Cascadia Code', monospace;
  font-size: 0.8rem;
  color: #cbd5e1;
}

.cell-desc {
  max-width: 200px;
  color: #94a3b8;
  font-size: 0.82rem;
}

.cell-num {
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.cell-actions {
  white-space: nowrap;
}

.pill-tipo {
  display: inline-block;
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  padding: 0.2rem 0.5rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 600;
  vertical-align: middle;
}

.pill-tipo--blue {
  color: #7dd3fc;
  border: 1px solid rgba(56, 189, 248, 0.4);
  background: rgba(56, 189, 248, 0.1);
}

.pill-tipo--green {
  color: #86efac;
  border: 1px solid rgba(34, 197, 94, 0.4);
  background: rgba(34, 197, 94, 0.1);
}

.pill-tipo--red {
  color: #fca5a5;
  border: 1px solid rgba(239, 68, 68, 0.4);
  background: rgba(239, 68, 68, 0.1);
}

.pill-tipo--slate {
  color: #cbd5e1;
  border: 1px solid rgba(148, 163, 184, 0.35);
}

.pill-tipo--muted {
  color: #64748b;
  border: 1px solid rgba(100, 116, 139, 0.35);
}

.action-link {
  color: #ef4444;
  font-weight: 600;
  font-size: 0.8rem;
  text-decoration: none;
}

.action-link:hover {
  text-decoration: underline;
  color: #f87171;
}

.empty-state {
  padding: 2rem 1rem;
  text-align: center;
  font-size: 0.875rem;
}

@media (max-width: 768px) {
  .period-nav {
    margin-left: 0;
    width: 100%;
    justify-content: center;
  }
}
</style>
