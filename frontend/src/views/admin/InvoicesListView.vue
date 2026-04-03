<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { fetchCompanies } from '@/services/servicesApi.js'
import { downloadAdminExportCsv, fetchAdminInvoices } from '@/services/invoicesApi.js'

const router = useRouter()

const companies = ref([])
const rows = ref([])
const meta = ref(null)
const links = ref(null)
const loading = ref(false)
const exportBusy = ref(false)
const error = ref('')

const filters = ref({
  company_id: '',
  status: '',
  period_year: '',
  period_month: '',
  q: '',
  page: 1,
})

const STATUS_OPTIONS = [
  { value: '', label: 'Todos' },
  { value: 'borrador', label: 'Borrador' },
  { value: 'aprobada', label: 'Aprobada' },
  { value: 'enviada', label: 'Enviada' },
  { value: 'parcialmente_pagada', label: 'Parcialmente pagada' },
  { value: 'pagada', label: 'Pagada' },
]

const yearOptions = computed(() => {
  const y = new Date().getFullYear()
  return Array.from({ length: 6 }, (_, i) => y - 2 + i)
})

async function load() {
  error.value = ''
  loading.value = true
  try {
    const params = { page: filters.value.page, per_page: 15 }
    if (filters.value.company_id) params.company_id = filters.value.company_id
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.period_year) params.period_year = filters.value.period_year
    if (filters.value.period_month) params.period_month = filters.value.period_month
    if (filters.value.q.trim()) params.q = filters.value.q.trim()

    const res = await fetchAdminInvoices(params)
    rows.value = res.data
    meta.value = res.meta
    links.value = res.links
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar las facturas.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  try {
    companies.value = await fetchCompanies()
  } catch {
    companies.value = []
  }
  await load()
})

watch(
  () => [
    filters.value.company_id,
    filters.value.status,
    filters.value.period_year,
    filters.value.period_month,
  ],
  () => {
    filters.value.page = 1
    load()
  }
)

function goPage(p) {
  filters.value.page = p
  load()
}

function applySearch() {
  filters.value.page = 1
  load()
}

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

function openRow(id, ev) {
  if (ev?.target?.closest?.('a, button')) return
  router.push(`/admin/facturas/${id}`)
}

const pageSummary = computed(() => {
  const m = meta.value
  if (!m || !m.total) return ''
  return `${m.from ?? 0}–${m.to ?? 0} de ${m.total} factura(s)`
})

async function exportInvoicesCsv() {
  error.value = ''
  exportBusy.value = true
  try {
    const params = {}
    if (filters.value.company_id) params.company_id = filters.value.company_id
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.period_year) params.period_year = filters.value.period_year
    if (filters.value.period_month) params.period_month = filters.value.period_month
    if (filters.value.q.trim()) params.q = filters.value.q.trim()
    const { blob, filename } = await downloadAdminExportCsv('/admin/export/invoices', params)
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
  <section class="page">
    <header class="head">
      <div>
        <h1>Facturas</h1>
        <p class="lede">Listado y gestión. Clic en una fila o en Ver para el detalle; los borradores también se editan con Editar.</p>
      </div>
      <div class="head-btns">
        <button type="button" class="btn secondary" :disabled="exportBusy" @click="exportInvoicesCsv">
          {{ exportBusy ? 'Exportando…' : 'Exportar CSV (Excel)' }}
        </button>
        <RouterLink class="btn primary" to="/admin/facturas/nueva">+ Nueva factura</RouterLink>
      </div>
    </header>

    <div class="filters card">
      <label class="grow">
        <span>Búsqueda por código</span>
        <input v-model="filters.q" type="search" class="input" placeholder="FAC-2026-…" @keydown.enter.prevent="applySearch" />
      </label>
      <label>
        <span>Empresa</span>
        <select v-model="filters.company_id" class="input">
          <option value="">Todas</option>
          <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
        </select>
      </label>
      <label>
        <span>Estado</span>
        <select v-model="filters.status" class="input">
          <option v-for="o in STATUS_OPTIONS" :key="o.value || 'all'" :value="o.value">{{ o.label }}</option>
        </select>
      </label>
      <label>
        <span>Año</span>
        <select v-model="filters.period_year" class="input">
          <option value="">Cualquiera</option>
          <option v-for="y in yearOptions" :key="y" :value="String(y)">{{ y }}</option>
        </select>
      </label>
      <label>
        <span>Mes</span>
        <select v-model="filters.period_month" class="input">
          <option value="">Cualquiera</option>
          <option value="1">Enero</option>
          <option value="2">Febrero</option>
          <option value="3">Marzo</option>
          <option value="4">Abril</option>
          <option value="5">Mayo</option>
          <option value="6">Junio</option>
          <option value="7">Julio</option>
          <option value="8">Agosto</option>
          <option value="9">Septiembre</option>
          <option value="10">Octubre</option>
          <option value="11">Noviembre</option>
          <option value="12">Diciembre</option>
        </select>
      </label>
      <button type="button" class="btn secondary" @click="applySearch">Aplicar</button>
    </div>

    <p v-if="error" class="banner err">{{ error }}</p>

    <div class="card table-wrap">
      <div v-if="loading" class="muted pad">Cargando…</div>
      <template v-else>
        <p v-if="pageSummary" class="meta-line muted">{{ pageSummary }}</p>
        <table class="table">
          <thead>
            <tr>
              <th>Código</th>
              <th>Empresa</th>
              <th>Periodo</th>
              <th>Estado</th>
              <th class="num">Total</th>
              <th class="actions-col">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="inv in rows" :key="inv.id" class="row-data clickable" @click="openRow(inv.id, $event)">
              <td class="mono">{{ inv.code }}</td>
              <td>{{ inv.company?.nombre || '—' }}</td>
              <td>{{ inv.period_label }}</td>
              <td>
                <span class="pill" :data-st="inv.status">{{ inv.status_label }}</span>
              </td>
              <td class="num">{{ money(inv.total) }}</td>
              <td class="actions-col" @click.stop>
                <RouterLink class="link" :to="`/admin/facturas/${inv.id}`">Ver</RouterLink>
                <RouterLink v-if="inv.status === 'borrador'" class="link" :to="`/admin/facturas/${inv.id}/editar`">
                  Editar
                </RouterLink>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="6" class="empty muted">No hay facturas con estos filtros.</td>
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
  margin-bottom: 1rem;
}

.head-btns {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}

h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  color: #f8fafc;
}

.lede {
  margin: 0;
  max-width: 36rem;
  font-size: 0.88rem;
  color: #94a3b8;
}

.filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 0.75rem;
  align-items: end;
}

.filters span {
  display: block;
  font-size: 0.78rem;
  color: #94a3b8;
  margin-bottom: 0.25rem;
}

.grow {
  grid-column: span 2;
  min-width: 180px;
}

@media (max-width: 720px) {
  .grow {
    grid-column: span 1;
  }
}

.input {
  width: 100%;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.35);
  color: #f8fafc;
  padding: 0.45rem 0.6rem;
  font: inherit;
}

.card {
  padding: 1rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 1rem;
}

.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
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
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
  text-align: left;
}

.table th {
  color: #94a3b8;
  font-weight: 600;
  font-size: 0.78rem;
  text-transform: uppercase;
}

.row-data.clickable {
  cursor: pointer;
}

.row-data.clickable:hover {
  background: rgba(56, 189, 248, 0.06);
}

.mono {
  font-family: ui-monospace, monospace;
  font-size: 0.85rem;
}

.num {
  text-align: right;
  white-space: nowrap;
}

.pill {
  display: inline-block;
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}

.pill[data-st='borrador'] {
  color: #fde68a;
  border: 1px solid rgba(251, 191, 36, 0.4);
}
.pill[data-st='aprobada'] {
  color: #a5b4fc;
  border: 1px solid rgba(129, 140, 248, 0.4);
}
.pill[data-st='enviada'] {
  color: #7dd3fc;
  border: 1px solid rgba(56, 189, 248, 0.4);
}
.pill[data-st='parcialmente_pagada'] {
  color: #fcd34d;
  border: 1px solid rgba(251, 191, 36, 0.45);
}
.pill[data-st='pagada'] {
  color: #86efac;
  border: 1px solid rgba(74, 222, 128, 0.4);
}

.actions-col {
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

.tiny {
  font-size: 0.75rem;
}

.empty {
  padding: 1.5rem;
  text-align: center;
}

.muted {
  color: #94a3b8;
}

.pad {
  padding: 1rem;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin-top: 1rem;
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
