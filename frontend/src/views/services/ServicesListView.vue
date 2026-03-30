<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import { fetchCompanies, fetchEmpleados, fetchServices } from '@/services/servicesApi.js'

const auth = useAuthStore()
const router = useRouter()

const companies = ref([])
const empleados = ref([])
const rows = ref([])
const meta = ref(null)
const links = ref(null)
const loading = ref(false)
const error = ref('')

const filters = ref({
  company_id: '',
  user_id: '',
  service_date_from: '',
  service_date_to: '',
  q: '',
  incluir_eliminados: false,
  page: 1,
})

const isAdmin = computed(() => isAdminPanelRole(auth.user?.rol))

const listTitle = computed(() => (isAdmin.value ? 'Listado de servicios' : 'Mis servicios'))

const nuevoServicioTo = computed(() =>
  isAdmin.value ? '/admin/servicios/nuevo' : '/empleado/registro-servicio'
)

function detailPath(id) {
  return isAdmin.value ? `/admin/servicios/${id}` : `/empleado/servicio/${id}`
}

function editPath(id) {
  return `/admin/servicios/${id}/editar`
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
    if (isAdmin.value && filters.value.incluir_eliminados) params.incluir_eliminados = 1

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
    filters.value.incluir_eliminados,
  ],
  () => scheduleFilterLoad()
)

function applyFiltersNow() {
  clearTimeout(filterTimer)
  filters.value.page = 1
  load()
}

function goPage(p) {
  filters.value.page = p
  load()
}

function openRow(id, ev) {
  if (ev?.target?.closest?.('a, button')) return
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

const pageSummary = computed(() => {
  const m = meta.value
  if (!m || !m.total) return ''
  return `${m.from ?? 0}–${m.to ?? 0} de ${m.total} servicio(s)`
})
</script>

<template>
  <section class="page">
    <header class="head">
      <div>
        <h1>{{ listTitle }}</h1>
        <p class="lede">
          {{
            isAdmin
              ? 'Búsqueda, filtros y revisión de lo registrado antes de facturar.'
              : 'Solo tus servicios visibles. Toca una fila para ver el detalle.'
          }}
        </p>
      </div>
      <RouterLink class="btn primary" :to="nuevoServicioTo">+ Nuevo servicio</RouterLink>
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
      <label v-if="isAdmin" class="check">
        <input v-model="filters.incluir_eliminados" type="checkbox" />
        Incluir eliminados
      </label>
      <button type="button" class="btn secondary" @click="applyFiltersNow">Aplicar ahora</button>
    </div>
    <p class="filter-hint muted">
      La búsqueda de texto se aplica al escribir. Empresa, empleado y fechas se actualizan al cambiar (o usa «Aplicar ahora»).
    </p>

    <div class="card table-wrap">
      <div v-if="loading" class="muted pad">Cargando…</div>
      <template v-else>
        <p v-if="pageSummary" class="meta-line muted">{{ pageSummary }}</p>
        <table class="table">
          <thead>
            <tr>
              <th>Código</th>
              <th>Fecha</th>
              <th>Empresa</th>
              <th>Cliente</th>
              <th>Descripción</th>
              <th v-if="isAdmin">Técnico</th>
              <th class="num">Valor</th>
              <th>Estado</th>
              <th v-if="isAdmin" class="actions-col">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="s in rows"
              :key="s.id"
              class="row-data"
              tabindex="0"
              role="link"
              :title="'Ver detalle ' + s.code"
              @click="openRow(s.id, $event)"
              @keydown.enter.prevent="router.push(detailPath(s.id))"
            >
              <td>
                <span class="link">{{ s.code }}</span>
              </td>
              <td>{{ formatDate(s.service_date) }}</td>
              <td>{{ s.company?.nombre || '—' }}</td>
              <td>{{ s.client_name || '—' }}</td>
              <td class="desc">{{ clip(s.description) }}</td>
              <td v-if="isAdmin">{{ s.empleado?.nombre || '—' }}</td>
              <td class="num">{{ money(s.amount) }}</td>
              <td><span class="pill" :data-st="s.status">{{ s.status }}</span></td>
              <td v-if="isAdmin" class="actions-col" @click.stop>
                <RouterLink class="action-link" :to="detailPath(s.id)">Ver</RouterLink>
                <RouterLink
                  v-if="s.status !== 'eliminado' && !s.invoiced"
                  class="action-link action-link--alt"
                  :to="editPath(s.id)"
                >
                  Editar
                </RouterLink>
                <span v-else-if="s.invoiced" class="muted tiny" title="Facturado: no editable">Facturado</span>
                <span v-else class="muted tiny">—</span>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td :colspan="isAdmin ? 9 : 7" class="muted center empty-msg">
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
  </section>
</template>

<style scoped>
.page {
  max-width: 1200px;
  margin: 0 auto;
}

.head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.head h1 {
  margin: 0;
  font-size: 1.35rem;
}

.lede {
  margin: 0.35rem 0 0;
  font-size: 0.875rem;
  color: #94a3b8;
  max-width: 36rem;
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
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
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

.check {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.85rem;
  color: #cbd5e1;
}

.table-wrap {
  overflow-x: auto;
}

.meta-line {
  font-size: 0.8rem;
  margin: 0 0 0.65rem;
}

.table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}

.table th,
.table td {
  padding: 0.55rem 0.45rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.15);
  text-align: left;
  vertical-align: top;
}

.table th {
  color: #94a3b8;
  font-weight: 600;
}

.row-data {
  cursor: pointer;
  transition: background 0.12s ease;
}

.row-data:hover {
  background: rgba(56, 189, 248, 0.06);
}

.row-data:focus-visible {
  outline: 2px solid rgba(56, 189, 248, 0.5);
  outline-offset: -2px;
}

.desc {
  max-width: 220px;
}

.num {
  white-space: nowrap;
  text-align: right;
}

.link {
  color: #7dd3fc;
  font-weight: 600;
}

.actions-col {
  white-space: nowrap;
}

.action-link {
  display: inline-block;
  margin-right: 0.65rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: #7dd3fc;
  text-decoration: none;
}

.action-link:hover {
  text-decoration: underline;
}

.action-link--alt {
  color: #a5b4fc;
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

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
