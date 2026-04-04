<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { fetchCompanies } from '@/services/servicesApi.js'
import {
  addInvoicePayment,
  downloadAdminExportCsv,
  fetchAdminInvoice,
  fetchAdminInvoices,
} from '@/services/invoicesApi.js'

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
  document.addEventListener('keydown', onPayModalEscape)
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

let qDebounceTimer = null
const Q_DEBOUNCE_MS = 350

watch(
  () => filters.value.q,
  () => {
    clearTimeout(qDebounceTimer)
    qDebounceTimer = setTimeout(() => {
      filters.value.page = 1
      load()
    }, Q_DEBOUNCE_MS)
  }
)

onUnmounted(() => {
  document.removeEventListener('keydown', onPayModalEscape)
  clearTimeout(qDebounceTimer)
})

function goPage(p) {
  filters.value.page = p
  load()
}

/** Enter en el campo de búsqueda: ejecutar de inmediato sin esperar el debounce. */
function flushSearchFromInput() {
  clearTimeout(qDebounceTimer)
  filters.value.page = 1
  load()
}

function canPayFromList(inv) {
  return inv.status === 'enviada' || inv.status === 'parcialmente_pagada'
}

const payModalOpen = ref(false)
const payModalLoading = ref(false)
const paySubmitting = ref(false)
const payModalError = ref('')
const payTargetId = ref(null)
const payInvoice = ref(null)
const payForm = ref({
  amount: '',
  payment_date: new Date().toISOString().slice(0, 10),
  method: 'Transferencia',
  notes: '',
})

const payBalanceNum = computed(() => {
  const b = payInvoice.value?.financial?.balance
  if (b === undefined || b === null) return null
  const n = Number(b)
  return Number.isNaN(n) ? null : n
})

const payCanRegister = computed(() => {
  const st = payInvoice.value?.status
  if (!st || !['enviada', 'parcialmente_pagada'].includes(st)) return false
  if (payBalanceNum.value !== null && payBalanceNum.value <= 0) return false
  return true
})

function resetPayForm() {
  payForm.value = {
    amount: '',
    payment_date: new Date().toISOString().slice(0, 10),
    method: 'Transferencia',
    notes: '',
  }
}

async function openPayModal(inv) {
  payModalError.value = ''
  payTargetId.value = inv.id
  payInvoice.value = null
  resetPayForm()
  payModalOpen.value = true
  payModalLoading.value = true
  try {
    payInvoice.value = await fetchAdminInvoice(inv.id)
  } catch (e) {
    payModalError.value = e.data?.message || e.message || 'No se pudo cargar la factura.'
  } finally {
    payModalLoading.value = false
  }
}

function closePayModal() {
  payModalOpen.value = false
  payTargetId.value = null
  payInvoice.value = null
  payModalError.value = ''
  payModalLoading.value = false
  paySubmitting.value = false
}

function fillPayFullBalance() {
  const b = payBalanceNum.value
  if (b === null || b <= 0) return
  payForm.value.amount = String(b)
}

async function submitPayModal() {
  payModalError.value = ''
  const id = payTargetId.value
  if (id == null) return
  const amt = Number(payForm.value.amount)
  if (Number.isNaN(amt) || amt < 0.01) {
    payModalError.value = 'Indique un monto válido.'
    return
  }
  paySubmitting.value = true
  try {
    await addInvoicePayment(id, {
      amount: amt,
      payment_date: payForm.value.payment_date,
      method: payForm.value.method.trim() || 'Otro',
      notes: payForm.value.notes.trim() || undefined,
    })
    closePayModal()
    await load()
  } catch (e) {
    payModalError.value = e.data?.message || e.message || 'No se pudo registrar el pago.'
  } finally {
    paySubmitting.value = false
  }
}

function onPayModalEscape(ev) {
  if (ev.key === 'Escape' && payModalOpen.value) {
    ev.preventDefault()
    closePayModal()
  }
}

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
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
        <p class="lede">Listado y gestión. Clic en el <strong>código</strong> de la factura para ver el detalle; los borradores se editan con Editar.</p>
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
        <input
          v-model="filters.q"
          type="search"
          class="input"
          placeholder="FAC-260318-…"
          autocomplete="off"
          @keydown.enter.prevent="flushSearchFromInput"
        />
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
            <tr v-for="inv in rows" :key="inv.id" class="row-data">
              <td class="mono">
                <RouterLink class="link" :to="`/admin/facturas/${inv.id}`">{{ inv.code }}</RouterLink>
              </td>
              <td>{{ inv.company?.nombre || '—' }}</td>
              <td>{{ inv.period_label }}</td>
              <td>
                <span class="pill" :data-st="inv.status">{{ inv.status_label }}</span>
              </td>
              <td class="num">{{ money(inv.total) }}</td>
              <td class="actions-col" @click.stop>
                <div class="actions-row">
                  <RouterLink v-if="inv.status === 'borrador'" class="link" :to="`/admin/facturas/${inv.id}/editar`">
                    Editar
                  </RouterLink>
                  <button
                    v-if="canPayFromList(inv)"
                    type="button"
                    class="link-btn"
                    @click="openPayModal(inv)"
                  >
                    Pagar
                  </button>
                </div>
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

    <Teleport to="body">
      <div v-if="payModalOpen" class="modal-backdrop" @click.self="closePayModal">
        <div class="modal card pay-modal" role="dialog" aria-modal="true" aria-labelledby="pay-modal-title">
          <h2 id="pay-modal-title" class="modal-title">Registrar pago</h2>
          <p v-if="payInvoice && !payModalLoading" class="pay-modal-meta muted">
            <span class="mono">{{ payInvoice.code }}</span>
            <span v-if="payInvoice.company?.nombre"> · {{ payInvoice.company.nombre }}</span>
          </p>
          <p v-if="payModalLoading" class="muted">Cargando factura…</p>
          <p v-else-if="!payInvoice && payModalError" class="banner err">{{ payModalError }}</p>
          <template v-else-if="payInvoice">
            <p v-if="payModalError" class="banner err">{{ payModalError }}</p>
            <div v-if="payCanRegister" class="pay-form">
              <p class="balance-line">
                Saldo pendiente: <strong>{{ money(payBalanceNum ?? 0) }}</strong>
              </p>
              <button type="button" class="btn secondary fill-balance-btn" @click="fillPayFullBalance">
                Usar saldo completo
              </button>
              <label>
                <span>Monto</span>
                <input v-model="payForm.amount" type="number" min="0.01" step="0.01" class="input" />
              </label>
              <label>
                <span>Fecha</span>
                <input v-model="payForm.payment_date" type="date" class="input" />
              </label>
              <label>
                <span>Método</span>
                <input v-model="payForm.method" type="text" class="input" />
              </label>
              <label class="wide">
                <span>Notas</span>
                <input v-model="payForm.notes" type="text" class="input" />
              </label>
            </div>
            <p v-else-if="payInvoice.status === 'aprobada'" class="muted pay-hint">
              Marque la factura como <strong>enviada</strong> para registrar pagos.
              <RouterLink class="link" :to="`/admin/facturas/${payInvoice.id}`">Abrir detalle</RouterLink>
            </p>
            <p v-else class="muted pay-hint">
              Solo se registran abonos con factura <strong>enviada</strong> o <strong>parcialmente pagada</strong>.
              <RouterLink class="link" :to="`/admin/facturas/${payInvoice.id}`">Abrir detalle</RouterLink>
            </p>
          </template>
          <div class="modal-actions">
            <button type="button" class="btn secondary" @click="closePayModal">Cerrar</button>
            <button
              v-if="payInvoice && payCanRegister"
              type="button"
              class="btn primary"
              :disabled="paySubmitting"
              @click="submitPayModal"
            >
              {{ paySubmitting ? 'Registrando…' : 'Registrar pago' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
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

.actions-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.65rem;
}

.link-btn {
  margin: 0;
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  font-weight: 600;
  color: #86efac;
  cursor: pointer;
  text-decoration: none;
}

.link-btn:hover {
  text-decoration: underline;
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

.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(2, 6, 23, 0.72);
  backdrop-filter: blur(6px);
}

.pay-modal.modal {
  width: 100%;
  max-width: 480px;
  max-height: 90vh;
  overflow-y: auto;
  margin: 0;
}

.modal-title {
  margin: 0 0 0.35rem;
  font-size: 1.15rem;
  color: #f8fafc;
}

.pay-modal-meta {
  margin: 0 0 1rem;
  font-size: 0.85rem;
}

.pay-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 0.75rem;
  align-items: end;
  margin-bottom: 1rem;
}

.pay-form .wide {
  grid-column: 1 / -1;
}

.pay-form .balance-line {
  grid-column: 1 / -1;
  margin: 0;
  font-size: 0.9rem;
  color: #e2e8f0;
}

.pay-form .fill-balance-btn {
  grid-column: 1 / -1;
  justify-self: start;
  margin-bottom: 0.25rem;
}

.pay-form label span {
  display: block;
  font-size: 0.78rem;
  color: #94a3b8;
  margin-bottom: 0.25rem;
}

.pay-hint {
  margin: 0 0 1rem;
  line-height: 1.5;
}

.pay-hint .link {
  display: inline;
  margin-left: 0.25rem;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 0.65rem;
  margin-top: 0.5rem;
  padding-top: 0.75rem;
  border-top: 1px solid rgba(148, 163, 184, 0.2);
}
</style>
