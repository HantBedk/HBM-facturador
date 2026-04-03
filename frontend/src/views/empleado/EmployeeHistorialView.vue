<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import { fetchCompanies, fetchEmpleados } from '@/services/servicesApi.js'
import { fetchEmpleadoHistorialAdmin, fetchMyHistorial } from '@/services/employeeHistorialApi.js'

const props = defineProps({
  /** Solo admin: id del empleado en la ruta dinámica */
  userId: { type: String, default: '' },
})

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const isAdmin = computed(() => isAdminPanelRole(auth.user?.rol))

const empleados = ref([])
const selectedEmpleadoId = ref('')

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
  const fromProp = props.userId?.trim()
  if (fromProp) return fromProp
  return selectedEmpleadoId.value || ''
}

async function loadEmpleados() {
  if (!isAdmin.value) return
  try {
    empleados.value = await fetchEmpleados()
    const fromRoute = props.userId?.trim()
    if (fromRoute) {
      selectedEmpleadoId.value = fromRoute
    }
  } catch {
    empleados.value = []
  }
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

  if (isAdmin.value) {
    const uid = adminTargetUserId()
    if (!uid) {
      payload.value = null
      return
    }
  }

  loading.value = true
  try {
    const p = historialParams()
    if (isAdmin.value) {
      payload.value = await fetchEmpleadoHistorialAdmin(adminTargetUserId(), p)
    } else {
      payload.value = await fetchMyHistorial(p)
    }
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
  const q = buildQueryFromState()
  if (isAdmin.value && props.userId) {
    router.replace({ name: 'admin-emp-rendimiento-user', params: { userId: props.userId }, query: q })
  } else if (isAdmin.value && selectedEmpleadoId.value) {
    router.replace({
      name: 'admin-emp-rendimiento-user',
      params: { userId: selectedEmpleadoId.value },
      query: q,
    })
  } else if (!isAdmin.value) {
    router.replace({ path: '/empleado/historial', query: q })
  }
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

function onEmpleadoChange() {
  if (!selectedEmpleadoId.value) return
  router.push({
    name: 'admin-emp-rendimiento-user',
    params: { userId: selectedEmpleadoId.value },
    query: buildQueryFromState(),
  })
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
  if (isAdmin.value) {
    await loadEmpleados()
    if (!props.userId && empleados.value.length === 1) {
      const only = String(empleados.value[0].id)
      await router.replace({
        name: 'admin-emp-rendimiento-user',
        params: { userId: only },
        query: buildQueryFromState(),
      })
      return
    }
  } else if (route.path === '/empleado/historial' && (route.query.year == null || route.query.month == null)) {
    await router.replace({
      path: '/empleado/historial',
      query: buildQueryFromState(),
    })
    return
  }
  await loadHistorial()
})

/** Misma instancia de vista al pasar de /rendimiento a /rendimiento/:userId */
watch(
  () => props.userId,
  (uid) => {
    if (!isAdmin.value) return
    if (uid) selectedEmpleadoId.value = String(uid)
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
</script>

<template>
  <div class="page">
    <header class="head">
      <div>
        <h1>{{ isAdmin ? 'Historial y rendimiento por empleado' : 'Mi historial de servicios' }}</h1>
        <p class="lede">
          {{
            isAdmin
              ? 'Consulte servicios por mes, totales y detalle para validar actividad y productividad.'
              : 'Servicios registrados por mes calendario. Use las flechas o los selectores para cambiar de periodo.'
          }}
        </p>
      </div>
      <button type="button" class="btn secondary" :disabled="loading" @click="loadHistorial">
        {{ loading ? 'Actualizando…' : 'Actualizar' }}
      </button>
    </header>

    <div v-if="isAdmin" class="card block-a">
      <h2 class="h2">A. Empleado</h2>
      <label class="field">
        <span>Técnico</span>
        <select v-model="selectedEmpleadoId" class="input" @change="onEmpleadoChange">
          <option value="">Seleccione un empleado…</option>
          <option v-for="e in empleados" :key="e.id" :value="String(e.id)">
            {{ e.nombre }} — {{ e.correo }}
          </option>
        </select>
      </label>
      <p v-if="!adminTargetUserId()" class="hint">Elija un empleado para cargar métricas y el listado del mes.</p>
    </div>

    <div class="card block-b">
      <h2 class="h2">B. Periodo</h2>
      <div class="period-row">
        <button type="button" class="nav-m" :disabled="loading" title="Mes anterior" @click="shiftMonth(-1)">
          ‹
        </button>
        <span class="period-label">{{ period?.label || '…' }}</span>
        <button type="button" class="nav-m" :disabled="loading" title="Mes siguiente" @click="shiftMonth(1)">
          ›
        </button>
        <div class="selects">
          <label>
            <span>Año</span>
            <select v-model.number="periodYear" class="input" @change="onYearMonthSelectChange">
              <option v-for="y in yearOptions" :key="y" :value="y">{{ y }}</option>
            </select>
          </label>
          <label>
            <span>Mes</span>
            <select v-model.number="periodMonth" class="input" @change="onYearMonthSelectChange">
              <option v-for="m in monthOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
            </select>
          </label>
        </div>
      </div>
    </div>

    <div v-if="!isAdmin || adminTargetUserId()" class="card block-c">
      <h2 class="h2">C. Filtros del listado</h2>
      <p class="hint">Opcional. Afinan la tabla y las métricas del mes (no cargan otros meses).</p>
      <div class="filters-grid">
        <label class="field grow">
          <span>Empresa</span>
          <select v-model="filterCompanyId" class="input" @change="onFilterCompanyChange">
            <option value="">Todas</option>
            <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
          </select>
        </label>
        <label class="field grow">
          <span>Búsqueda (código, descripción, tipo, cliente)</span>
          <input
            v-model="filterSearch"
            type="search"
            class="input"
            placeholder="Ej. mantenimiento, FAC-…"
            autocomplete="off"
            @input="scheduleSearchReload"
          />
        </label>
      </div>
    </div>

    <p v-if="error" class="banner err" role="alert">{{ error }}</p>

    <template v-if="!isAdmin || adminTargetUserId()">
      <p v-if="loading && !payload" class="muted">Cargando…</p>

      <template v-else-if="payload">
        <div class="kpis">
          <article class="kpi">
            <h3>Servicios en el periodo</h3>
            <p class="num">{{ summary?.services_count ?? 0 }}</p>
          </article>
          <article class="kpi">
            <h3>Total generado</h3>
            <p class="num accent">{{ formatMoney(summary?.total_amount) }}</p>
          </article>
          <article class="kpi">
            <h3>Promedio por servicio</h3>
            <p class="num">{{ formatMoneyAvg(summary?.avg_per_service) }}</p>
          </article>
          <article class="kpi">
            <h3>Días con actividad</h3>
            <p class="num">{{ summary?.distinct_service_days ?? 0 }}</p>
          </article>
          <article class="kpi">
            <h3>Mayor valor (un servicio)</h3>
            <p class="num">{{ formatMoney(summary?.max_service_amount) }}</p>
          </article>
          <article class="kpi">
            <h3>Día con más servicios</h3>
            <p v-if="summary?.busiest_day" class="num small-kpi">
              {{ formatDate(summary.busiest_day.date) }}
              <span class="sub">({{ summary.busiest_day.services_count }})</span>
            </p>
            <p v-else class="num muted">—</p>
          </article>
        </div>

        <div v-if="employee" class="card who">
          <div class="who-main">
            <strong>{{ employee.nombre }}</strong>
            <span class="pill pill-rol">{{ employee.rol_label || employee.rol }}</span>
            <span class="pill" :data-st="employee.estado">{{ employee.estado_label || employee.estado }}</span>
          </div>
          <span class="muted">{{ employee.correo }}</span>
        </div>

        <div class="card table-card">
          <h2 class="h2">D. Servicios del periodo</h2>
          <p class="hint">Orden: más recientes primero. Enlace al detalle según su rol.</p>
          <div class="table-wrap">
            <table class="table">
              <thead>
                <tr>
                  <th>Fecha</th>
                  <th>Código</th>
                  <th>Empresa</th>
                  <th>Tipo</th>
                  <th>Descripción</th>
                  <th class="num">Valor</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                <tr v-for="s in services" :key="s.id">
                  <td>{{ formatDate(s.service_date) }}</td>
                  <td class="mono">{{ s.code }}</td>
                  <td>{{ s.company_name || '—' }}</td>
                  <td>{{ s.service_type || '—' }}</td>
                  <td class="desc" :title="s.description">{{ clip(s.description, 80) }}</td>
                  <td class="num">{{ formatMoney(s.amount) }}</td>
                  <td class="actions">
                    <RouterLink class="link" :to="`${detailBase}/${s.id}`">Ver</RouterLink>
                  </td>
                </tr>
              </tbody>
            </table>
            <p v-if="!services.length" class="empty muted">
              Sin servicios en este mes. Pruebe otro periodo o verifique el registro del equipo.
            </p>
          </div>
        </div>

        <p class="fine muted">E. Use «Mes anterior / siguiente» o los desplegables para moverse entre meses sin perder el empleado seleccionado.</p>
      </template>
    </template>
  </div>
</template>

<style scoped>
.page {
  max-width: 1100px;
  margin: 0 auto;
}

.head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  color: #f8fafc;
}

.lede {
  margin: 0;
  max-width: 40rem;
  font-size: 0.88rem;
  color: #94a3b8;
  line-height: 1.45;
}

.h2 {
  margin: 0 0 0.75rem;
  font-size: 0.95rem;
  color: #e2e8f0;
}

.card {
  padding: 1.1rem 1.25rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 1rem;
}

.block-a .field {
  display: block;
  max-width: 420px;
}

.block-a span,
.block-b span {
  display: block;
  font-size: 0.8rem;
  color: #94a3b8;
  margin-bottom: 0.35rem;
}

.input {
  width: 100%;
  max-width: 420px;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.35);
  color: #f8fafc;
  padding: 0.5rem 0.65rem;
  font: inherit;
}

.period-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem 1rem;
}

.nav-m {
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 10px;
  border: 1px solid rgba(56, 189, 248, 0.45);
  background: rgba(56, 189, 248, 0.12);
  color: #7dd3fc;
  font-size: 1.25rem;
  line-height: 1;
  cursor: pointer;
}

.nav-m:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.period-label {
  font-weight: 600;
  color: #f1f5f9;
  min-width: 9rem;
}

.selects {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-left: auto;
}

.selects label {
  display: flex;
  flex-direction: column;
}

.selects .input {
  min-width: 7rem;
}

.hint {
  margin: 0.5rem 0 0;
  font-size: 0.82rem;
  color: #64748b;
}

.banner {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  margin-bottom: 1rem;
}

.banner.err {
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
}

.kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.kpi {
  padding: 1rem;
  border-radius: 12px;
  border: 1px solid rgba(148, 163, 184, 0.18);
  background: rgba(30, 41, 59, 0.45);
}

.kpi h3 {
  margin: 0;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #94a3b8;
}

.kpi .num {
  margin: 0.5rem 0 0;
  font-size: 1.35rem;
  font-weight: 700;
  color: #f8fafc;
}

.kpi .num.accent {
  color: #38bdf8;
}

.filters-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1rem;
  align-items: end;
}

.block-c .field.grow .input {
  max-width: none;
}

.who {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.who-main {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.75rem;
}

.who-main strong {
  font-size: 1.05rem;
  color: #f8fafc;
}

.pill {
  display: inline-block;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  font-size: 0.72rem;
  font-weight: 600;
  border: 1px solid rgba(148, 163, 184, 0.35);
  color: #cbd5e1;
}

.pill-rol {
  border-color: rgba(129, 140, 248, 0.45);
  color: #c7d2fe;
}

.pill[data-st='activo'] {
  border-color: rgba(52, 211, 153, 0.45);
  color: #6ee7b7;
}

.pill[data-st='inactivo'] {
  border-color: rgba(248, 113, 113, 0.45);
  color: #fecaca;
}

.kpi .num.small-kpi {
  font-size: 1.05rem;
}

.kpi .sub {
  display: block;
  margin-top: 0.25rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #94a3b8;
}

.table-wrap {
  overflow-x: auto;
  margin-top: 0.5rem;
}

.table {
  width: 100%;
  min-width: 720px;
  border-collapse: collapse;
  font-size: 0.88rem;
}

.table th,
.table td {
  padding: 0.55rem 0.45rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
  text-align: left;
  vertical-align: top;
}

.table th {
  color: #94a3b8;
  font-weight: 600;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.mono {
  font-family: ui-monospace, monospace;
  font-size: 0.8rem;
}

.desc {
  max-width: 220px;
}

.num {
  text-align: right;
  white-space: nowrap;
}

.actions {
  white-space: nowrap;
}

.link {
  color: #7dd3fc;
  font-weight: 600;
  text-decoration: none;
}

.link:hover {
  text-decoration: underline;
}

.empty {
  padding: 1.5rem 0.5rem;
  text-align: center;
}

.muted {
  color: #94a3b8;
}

.fine {
  font-size: 0.78rem;
  margin-top: 0.5rem;
}

.btn {
  display: inline-flex;
  padding: 0.5rem 0.85rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: transparent;
  color: #e2e8f0;
}

.btn.secondary:hover:not(:disabled) {
  border-color: #38bdf8;
  color: #fff;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
