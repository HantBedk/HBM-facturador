<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import AdminInvoiceDetailPanel from '@/components/admin/AdminInvoiceDetailPanel.vue'
import InvoiceEditorForm from '@/components/admin/InvoiceEditorForm.vue'
import { fetchAdminCompanies } from '@/services/companiesApi.js'
import {
  addInvoicePayment,
  downloadAdminExportCsv,
  fetchAdminInvoice,
  fetchAdminInvoices,
  patchInvoiceStatus,
} from '@/services/invoicesApi.js'
import { tableAriaSort, tableSortIndicator } from '@/utils/tableSort.js'

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

/** Por defecto solo facturas ligadas a empresas dadas de alta. */
const listTab = ref('registered')

const companiesForFilter = computed(() => {
  const list = companies.value || []
  if (listTab.value === 'registered') return list.filter((c) => !c.es_cliente_puntual)
  return list
})

const companyFilterLabel = computed(() => 'Empresa')

const invSortKey = ref('period')
const invSortDir = ref('desc')
const INV_SORT_FIRST = {
  code: 'asc',
  company_nombre: 'asc',
  period: 'desc',
  status: 'asc',
  balance: 'desc',
  total: 'desc',
  created_at: 'desc',
}

const STATUS_OPTIONS = [
  { value: '', label: 'Todos' },
  { value: 'borrador', label: 'Borrador' },
  { value: 'aprobada', label: 'Aprobada' },
  { value: 'enviada', label: 'Enviada' },
  { value: 'parcialmente_pagada', label: 'Parcialmente pagada' },
  { value: 'pagada', label: 'Pagada' },
]

const LIST_VIEW_OPTIONS = [
  { value: 'all', label: 'Todas las facturas' },
  { value: 'registered', label: 'Solo empresas registradas' },
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
    if (listTab.value === 'registered') params.company_kind = 'registered'
    if (filters.value.company_id) params.company_id = filters.value.company_id
    if (filters.value.status) params.status = filters.value.status
    if (filters.value.period_year) params.period_year = filters.value.period_year
    if (filters.value.period_month) params.period_month = filters.value.period_month
    if (filters.value.q.trim()) params.q = filters.value.q.trim()
    params.sort = invSortKey.value
    params.sort_dir = invSortDir.value

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

const detailPanelOpen = ref(false)
const detailInvoiceId = ref(null)
const detailReviewMode = ref(false)
const detailPanelRef = ref(null)

const createInvoicePanelOpen = ref(false)

function openCreateInvoicePanel() {
  createInvoicePanelOpen.value = true
}

function closeCreateInvoicePanel() {
  createInvoicePanelOpen.value = false
}

async function onInvoiceCreatedFromPanel() {
  closeCreateInvoicePanel()
  await load()
}

function openDetailPanel(inv) {
  detailInvoiceId.value = inv.id
  detailReviewMode.value = false
  detailPanelOpen.value = true
}

/** Panel de revisión completa antes de aprobar (borrador). */
function openReviewApprovePanel(inv) {
  if (inv.status !== 'borrador') return
  detailInvoiceId.value = inv.id
  detailReviewMode.value = true
  detailPanelOpen.value = true
}

function closeDetailPanel() {
  detailPanelOpen.value = false
  detailInvoiceId.value = null
  detailReviewMode.value = false
}

onMounted(async () => {
  document.addEventListener('keydown', onGlobalEscape)
  try {
    companies.value = await fetchAdminCompanies({ company_kind: 'registered' })
  } catch {
    companies.value = []
  }
  await load()
})

watch(listTab, () => {
  filters.value.page = 1
  const sel = filters.value.company_id
  if (sel && !companiesForFilter.value.some((c) => String(c.id) === String(sel))) {
    filters.value.company_id = ''
  }
  load()
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
  document.removeEventListener('keydown', onGlobalEscape)
  clearTimeout(qDebounceTimer)
})

function goPage(p) {
  filters.value.page = p
  load()
}

function invSortInd(k) {
  return tableSortIndicator(invSortKey.value, invSortDir.value, k)
}

function invAriaSort(k) {
  return tableAriaSort(invSortKey.value, invSortDir.value, k)
}

function toggleInvSort(key) {
  if (invSortKey.value === key) {
    invSortDir.value = invSortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    invSortKey.value = key
    invSortDir.value = INV_SORT_FIRST[key] || 'asc'
  }
  filters.value.page = 1
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

const sendingInvoiceId = ref(null)

const invoiceListStatusBusy = computed(() => sendingInvoiceId.value != null)

/** Marcar como enviada (misma acción que en el panel de detalle). */
async function onSendFromList(inv) {
  if (inv.status !== 'aprobada' || invoiceListStatusBusy.value) return
  sendingInvoiceId.value = inv.id
  error.value = ''
  try {
    await patchInvoiceStatus(inv.id, 'enviada')
    if (detailPanelOpen.value && String(detailInvoiceId.value) === String(inv.id)) {
      await detailPanelRef.value?.reload?.()
    }
    await load()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo marcar la factura como enviada.'
  } finally {
    sendingInvoiceId.value = null
  }
}

/** Saldo pendiente de cobro; en borrador/aprobada no aplica en el listado. */
function invoiceSaldoDisplay(inv) {
  if (!['enviada', 'parcialmente_pagada', 'pagada'].includes(inv.status)) {
    return '—'
  }
  const b = inv.financial?.balance
  if (b === undefined || b === null) {
    return '—'
  }
  return money(b)
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
    if (detailPanelOpen.value && String(detailInvoiceId.value) === String(id)) {
      await detailPanelRef.value?.reload?.()
    }
    await load()
  } catch (e) {
    payModalError.value = e.data?.message || e.message || 'No se pudo registrar el pago.'
  } finally {
    paySubmitting.value = false
  }
}

function onGlobalEscape(ev) {
  if (ev.key !== 'Escape') return
  if (createInvoicePanelOpen.value) {
    ev.preventDefault()
    closeCreateInvoicePanel()
    return
  }
  if (detailPanelOpen.value) {
    ev.preventDefault()
    closeDetailPanel()
    return
  }
  if (payModalOpen.value) {
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
    if (listTab.value === 'registered') params.company_kind = 'registered'
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
  <section class="page page--fluid">
    <header class="head">
      <div>
        <h1>Facturas</h1>
        <p class="lede">
          Listado y gestión. Clic en el <strong>código</strong> abre el detalle. En borrador, <strong>Revisar y aprobar</strong> abre la
          revisión completa (editar, PDF, aprobar o rechazar). <strong>Enviar factura</strong> (aprobada) y <strong>Pagar</strong> (enviada /
          parcial).
        </p>
      </div>
      <div class="head-btns">
        <button type="button" class="btn secondary" :disabled="exportBusy" @click="exportInvoicesCsv">
          {{ exportBusy ? 'Exportando…' : 'Exportar CSV (Excel)' }}
        </button>
        <button type="button" class="btn primary" @click="openCreateInvoicePanel">+ Nueva factura</button>
      </div>
    </header>

    <div class="filters card">
      <div class="filters-top">
        <label class="filters-top-search">
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
        <label class="filters-top-view">
          <span>Vista del listado</span>
          <select v-model="listTab" class="input">
            <option v-for="o in LIST_VIEW_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </label>
      </div>
      <label>
        <span>{{ companyFilterLabel }}</span>
        <select v-model="filters.company_id" class="input">
          <option value="">Todas</option>
          <option v-for="c in companiesForFilter" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
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
              <th scope="col" :aria-sort="invAriaSort('code')">
                <button type="button" class="th-sort" @click="toggleInvSort('code')">
                  Código<span class="sort-ind" aria-hidden="true">{{ invSortInd('code') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="invAriaSort('company_nombre')">
                <button type="button" class="th-sort" @click="toggleInvSort('company_nombre')">
                  Empresa<span class="sort-ind" aria-hidden="true">{{ invSortInd('company_nombre') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="invAriaSort('period')">
                <button type="button" class="th-sort" @click="toggleInvSort('period')">
                  Periodo<span class="sort-ind" aria-hidden="true">{{ invSortInd('period') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="invAriaSort('status')">
                <button type="button" class="th-sort" @click="toggleInvSort('status')">
                  Estado<span class="sort-ind" aria-hidden="true">{{ invSortInd('status') }}</span>
                </button>
              </th>
              <th class="num" scope="col" :aria-sort="invAriaSort('balance')">
                <button type="button" class="th-sort th-sort--end" @click="toggleInvSort('balance')">
                  Saldo<span class="sort-ind" aria-hidden="true">{{ invSortInd('balance') }}</span>
                </button>
              </th>
              <th class="num" scope="col" :aria-sort="invAriaSort('total')">
                <button type="button" class="th-sort th-sort--end" @click="toggleInvSort('total')">
                  Total<span class="sort-ind" aria-hidden="true">{{ invSortInd('total') }}</span>
                </button>
              </th>
              <th class="actions-col">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="inv in rows" :key="inv.id" class="row-data">
              <td class="mono">
                <button type="button" class="code-link" @click="openDetailPanel(inv)">{{ inv.code }}</button>
              </td>
              <td>
                <span class="company-name">{{ inv.company?.nombre || inv.bill_to?.nombre || '—' }}</span>
                <span
                  v-if="!inv.company_id"
                  class="pill pill--counter"
                  title="Factura sin empresa en directorio (datos en factura)"
                  >Sin alta</span
                >
              </td>
              <td>{{ inv.period_label }}</td>
              <td>
                <span class="pill" :data-st="inv.status">{{ inv.status_label }}</span>
              </td>
              <td class="num">{{ invoiceSaldoDisplay(inv) }}</td>
              <td class="num">{{ money(inv.total) }}</td>
              <td class="actions-col" @click.stop>
                <div class="actions-row">
                  <button
                    v-if="inv.status === 'borrador'"
                    type="button"
                    class="link-btn link-btn--review"
                    :disabled="invoiceListStatusBusy"
                    @click="openReviewApprovePanel(inv)"
                  >
                    Revisar y aprobar
                  </button>
                  <button
                    v-if="inv.status === 'aprobada'"
                    type="button"
                    class="link-btn link-btn--send"
                    :disabled="invoiceListStatusBusy"
                    title="Marcar como enviada"
                    @click="onSendFromList(inv)"
                  >
                    {{ sendingInvoiceId === inv.id ? 'Enviando…' : 'Enviar factura' }}
                  </button>
                  <button
                    v-if="canPayFromList(inv)"
                    type="button"
                    class="link-btn"
                    :disabled="invoiceListStatusBusy"
                    @click="openPayModal(inv)"
                  >
                    Pagar
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="7" class="empty muted">No hay facturas con estos filtros.</td>
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

    <AdminInvoiceDetailPanel
      ref="detailPanelRef"
      :open="detailPanelOpen"
      :invoice-id="detailInvoiceId"
      :review-mode="detailReviewMode"
      @close="closeDetailPanel"
      @changed="load"
    />

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

    <Teleport to="body">
      <div
        v-if="createInvoicePanelOpen"
        class="fixed inset-0 z-[95] flex"
        role="presentation"
      >
        <button
          type="button"
          class="absolute inset-0 bg-black/55 backdrop-blur-[2px]"
          aria-label="Cerrar panel de nueva factura"
          @click="closeCreateInvoicePanel"
        />
        <aside
          class="relative ml-auto flex h-full w-full max-w-3xl flex-col border-l border-slate-700/90 bg-[#0f1419] shadow-2xl"
          role="dialog"
          aria-modal="true"
          aria-labelledby="inv-create-panel-title"
          @click.stop
        >
          <div class="flex shrink-0 items-center justify-between gap-3 border-b border-slate-700/80 px-4 py-3 sm:px-5">
            <h2 id="inv-create-panel-title" class="text-lg font-bold text-slate-100">Nueva factura</h2>
            <button
              type="button"
              class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-800 hover:text-white"
              aria-label="Cerrar"
              @click="closeCreateInvoicePanel"
            >
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
          <div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
            <InvoiceEditorForm
              embedded
              :invoice-id="null"
              @cancel="closeCreateInvoicePanel"
              @saved="onInvoiceCreatedFromPanel"
            />
          </div>
        </aside>
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

.filters-top {
  grid-column: 1 / -1;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  align-items: stretch;
}

.filters-top-search,
.filters-top-view {
  width: 100%;
}

.filters-top-view {
  max-width: 22rem;
}

.filters span {
  display: block;
  font-size: 0.78rem;
  color: #94a3b8;
  margin-bottom: 0.25rem;
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

.company-name {
  margin-right: 0.25rem;
}

.pill--counter {
  display: inline-block;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  padding: 0.15rem 0.45rem;
  border-radius: 6px;
  background: rgba(251, 191, 36, 0.12);
  color: #fcd34d;
  border: 1px solid rgba(251, 191, 36, 0.35);
  vertical-align: middle;
}

.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}

.banner.ok {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(34, 197, 94, 0.12);
  border: 1px solid rgba(34, 197, 94, 0.45);
  color: #bbf7d0;
  margin-bottom: 1rem;
}

.table-wrap {
  width: 100%;
  min-width: 0;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  padding-inline-end: 2px;
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

.link-btn:hover:not(:disabled) {
  text-decoration: underline;
}

.link-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.link-btn--send {
  color: #7dd3fc;
}

.link-btn--review {
  color: #fde68a;
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
  font-size: 0.85rem;
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
  z-index: 100;
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
