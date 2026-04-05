<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import AdminInvoiceDetailPanel from '@/components/admin/AdminInvoiceDetailPanel.vue'
import {
  addInvoicePayment,
  fetchAdminInvoice,
  fetchAdminInvoices,
  patchInvoiceStatus,
} from '@/services/invoicesApi.js'

const props = defineProps({
  open: { type: Boolean, default: false },
})

const emit = defineEmits(['close', 'changed'])

const rows = ref([])
const loading = ref(false)
const error = ref('')

const detailPanelOpen = ref(false)
const detailInvoiceId = ref(null)
const detailReviewMode = ref(false)
const detailPanelRef = ref(null)

const sendingInvoiceId = ref(null)
const invoiceListStatusBusy = computed(() => sendingInvoiceId.value != null)

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

function needsAttention(inv) {
  if (inv.status === 'borrador') return true
  if (inv.status === 'aprobada') return true
  if (inv.status === 'enviada' || inv.status === 'parcialmente_pagada') {
    const b = Number(inv.financial?.balance)
    return !Number.isNaN(b) && b > 0.009
  }
  return false
}

function statusPriority(s) {
  const m = { borrador: 0, aprobada: 1, enviada: 2, parcialmente_pagada: 2 }
  return m[s] ?? 9
}

const sortedRows = computed(() => {
  const list = [...rows.value]
  list.sort((a, b) => {
    const d = statusPriority(a.status) - statusPriority(b.status)
    if (d !== 0) return d
    return String(b.created_at || '').localeCompare(String(a.created_at || ''))
  })
  return list
})

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

function invoiceSaldoDisplay(inv) {
  if (!['enviada', 'parcialmente_pagada', 'pagada'].includes(inv.status)) {
    return '—'
  }
  const b = inv.financial?.balance
  if (b === undefined || b === null) return '—'
  return money(b)
}

function canPayFromList(inv) {
  return inv.status === 'enviada' || inv.status === 'parcialmente_pagada'
}

async function loadPending() {
  error.value = ''
  loading.value = true
  try {
    const res = await fetchAdminInvoices({
      per_page: 100,
      sort: 'created_at',
      sort_dir: 'desc',
    })
    const all = Array.isArray(res.data) ? res.data : []
    rows.value = all.filter(needsAttention)
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar las facturas.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

function onInvoiceChanged() {
  emit('changed')
  loadPending()
}

function openDetailPanel(inv) {
  detailInvoiceId.value = inv.id
  detailReviewMode.value = false
  detailPanelOpen.value = true
}

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

async function onSendFromList(inv) {
  if (inv.status !== 'aprobada' || invoiceListStatusBusy.value) return
  sendingInvoiceId.value = inv.id
  error.value = ''
  try {
    await patchInvoiceStatus(inv.id, 'enviada')
    if (detailPanelOpen.value && String(detailInvoiceId.value) === String(inv.id)) {
      await detailPanelRef.value?.reload?.()
    }
    onInvoiceChanged()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo marcar la factura como enviada.'
  } finally {
    sendingInvoiceId.value = null
  }
}

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
    onInvoiceChanged()
  } catch (e) {
    payModalError.value = e.data?.message || e.message || 'No se pudo registrar el pago.'
  } finally {
    paySubmitting.value = false
  }
}

function closePanel() {
  closeDetailPanel()
  closePayModal()
  emit('close')
}

function onGlobalEscape(ev) {
  if (ev.key !== 'Escape') return
  if (detailPanelOpen.value) {
    ev.preventDefault()
    closeDetailPanel()
    return
  }
  if (payModalOpen.value) {
    ev.preventDefault()
    closePayModal()
    return
  }
  if (props.open) {
    ev.preventDefault()
    closePanel()
  }
}

watch(
  () => props.open,
  (v) => {
    if (v) {
      loadPending()
      document.addEventListener('keydown', onGlobalEscape)
    } else {
      document.removeEventListener('keydown', onGlobalEscape)
      closeDetailPanel()
      closePayModal()
    }
  }
)

onUnmounted(() => {
  document.removeEventListener('keydown', onGlobalEscape)
})

function pillClass(status) {
  const s = String(status || '')
  if (s === 'borrador') return 'border-amber-400/40 text-amber-200'
  if (s === 'aprobada') return 'border-indigo-400/40 text-indigo-200'
  if (s === 'enviada') return 'border-sky-400/40 text-sky-200'
  if (s === 'parcialmente_pagada') return 'border-amber-300/40 text-amber-100'
  if (s === 'pagada') return 'border-emerald-400/40 text-emerald-200'
  return 'border-slate-500/40 text-slate-300'
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6"
      role="presentation"
    >
      <button
        type="button"
        class="absolute inset-0 bg-black/60 backdrop-blur-sm"
        aria-label="Cerrar panel"
        @click="closePanel"
      />
      <div
        class="relative z-10 flex max-h-[min(85vh,720px)] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-700/80 bg-[#151a24] shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="dash-pending-inv-title"
      >
        <header class="flex shrink-0 items-start justify-between gap-3 border-b border-slate-700/80 px-5 py-4">
          <div>
            <h2 id="dash-pending-inv-title" class="text-lg font-bold text-white">
              Facturas pendientes
            </h2>
            <p class="mt-1 text-xs text-slate-400">
              Borradores, aprobadas por enviar y enviadas con saldo por cobrar (hasta 100 más recientes).
            </p>
          </div>
          <button
            type="button"
            class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-800 hover:text-white"
            aria-label="Cerrar"
            @click="closePanel"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
          <p v-if="error" class="mb-3 rounded-lg border border-red-500/35 bg-red-500/10 px-3 py-2 text-sm text-red-200">
            {{ error }}
          </p>
          <p v-if="loading" class="py-8 text-center text-sm text-slate-400">Cargando…</p>
          <ul v-else-if="sortedRows.length" class="flex flex-col gap-3">
            <li
              v-for="inv in sortedRows"
              :key="inv.id"
              class="rounded-xl border border-slate-700/60 bg-[#1e2532] p-4 shadow-md shadow-black/20"
            >
              <div class="mb-3 flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                  <button
                    type="button"
                    class="font-mono text-sm font-semibold text-sky-300 underline decoration-sky-500/40 hover:text-sky-200"
                    @click="openDetailPanel(inv)"
                  >
                    {{ inv.code }}
                  </button>
                  <p class="mt-0.5 truncate text-sm text-slate-200">
                    {{ inv.company?.nombre || '—' }}
                  </p>
                  <p class="text-[0.7rem] text-slate-500">{{ inv.period_label }}</p>
                </div>
                <span
                  class="inline-flex shrink-0 rounded-full border px-2 py-0.5 text-[0.7rem] font-semibold"
                  :class="pillClass(inv.status)"
                >
                  {{ inv.status_label }}
                </span>
              </div>
              <div class="mb-3 flex flex-wrap gap-4 text-xs text-slate-400">
                <span>Total: <strong class="text-slate-200">{{ money(inv.total) }}</strong></span>
                <span>Saldo: <strong class="text-slate-200">{{ invoiceSaldoDisplay(inv) }}</strong></span>
              </div>
              <div class="flex flex-wrap gap-2">
                <button
                  v-if="inv.status === 'borrador'"
                  type="button"
                  class="rounded-lg bg-amber-500/15 px-3 py-1.5 text-xs font-semibold text-amber-100 ring-1 ring-amber-500/35 hover:bg-amber-500/25 disabled:opacity-50"
                  :disabled="invoiceListStatusBusy"
                  @click="openReviewApprovePanel(inv)"
                >
                  Revisar y aprobar
                </button>
                <button
                  v-if="inv.status === 'aprobada'"
                  type="button"
                  class="rounded-lg bg-sky-500/15 px-3 py-1.5 text-xs font-semibold text-sky-100 ring-1 ring-sky-500/35 hover:bg-sky-500/25 disabled:opacity-50"
                  :disabled="invoiceListStatusBusy"
                  @click="onSendFromList(inv)"
                >
                  {{ sendingInvoiceId === inv.id ? 'Enviando…' : 'Enviar factura' }}
                </button>
                <button
                  v-if="canPayFromList(inv)"
                  type="button"
                  class="rounded-lg bg-emerald-500/15 px-3 py-1.5 text-xs font-semibold text-emerald-100 ring-1 ring-emerald-500/35 hover:bg-emerald-500/25 disabled:opacity-50"
                  :disabled="invoiceListStatusBusy"
                  @click="openPayModal(inv)"
                >
                  Pagar
                </button>
              </div>
            </li>
          </ul>
          <p v-else class="py-10 text-center text-sm text-slate-500">
            No hay facturas que requieran acción inmediata.
          </p>
        </div>

        <footer class="shrink-0 border-t border-slate-700/80 px-4 py-3">
          <RouterLink
            to="/admin/facturas"
            class="block w-full rounded-xl border border-slate-600/50 bg-slate-800/40 py-2.5 text-center text-sm font-semibold text-sky-300 hover:bg-slate-800/70"
            @click="closePanel"
          >
            Ir a facturas (listado completo)
          </RouterLink>
        </footer>
      </div>
    </div>

    <AdminInvoiceDetailPanel
      ref="detailPanelRef"
      :open="detailPanelOpen"
      :invoice-id="detailInvoiceId"
      :review-mode="detailReviewMode"
      :overlay-z-index="210"
      @close="closeDetailPanel"
      @changed="onInvoiceChanged"
    />

    <div v-if="payModalOpen" class="fixed inset-0 z-[230] flex items-center justify-center bg-slate-950/75 p-4 backdrop-blur-sm" @click.self="closePayModal">
      <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl border border-slate-600/50 bg-[#1e2532] p-5 shadow-xl" role="dialog" aria-modal="true" aria-labelledby="dash-pay-title">
        <h2 id="dash-pay-title" class="text-base font-bold text-white">Registrar pago</h2>
        <p v-if="payInvoice && !payModalLoading" class="mt-1 font-mono text-xs text-slate-400">
          {{ payInvoice.code }}
          <span v-if="payInvoice.company?.nombre"> · {{ payInvoice.company.nombre }}</span>
        </p>
        <p v-if="payModalLoading" class="mt-3 text-sm text-slate-400">Cargando factura…</p>
        <p v-else-if="!payInvoice && payModalError" class="mt-3 rounded-lg border border-red-500/35 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ payModalError }}</p>
        <template v-else-if="payInvoice">
          <p v-if="payModalError" class="mt-3 rounded-lg border border-red-500/35 bg-red-500/10 px-3 py-2 text-sm text-red-200">{{ payModalError }}</p>
          <div v-if="payCanRegister" class="mt-4 space-y-3">
            <p class="text-sm text-slate-200">
              Saldo pendiente: <strong>{{ money(payBalanceNum ?? 0) }}</strong>
            </p>
            <button type="button" class="text-xs font-semibold text-sky-400 hover:underline" @click="fillPayFullBalance">
              Usar saldo completo
            </button>
            <label class="block">
              <span class="mb-1 block text-xs text-slate-400">Monto</span>
              <input v-model="payForm.amount" type="number" min="0.01" step="0.01" class="w-full rounded-lg border border-slate-600 bg-[#141a22] px-3 py-2 text-sm text-white" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs text-slate-400">Fecha</span>
              <input v-model="payForm.payment_date" type="date" class="w-full rounded-lg border border-slate-600 bg-[#141a22] px-3 py-2 text-sm text-white" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs text-slate-400">Método</span>
              <input v-model="payForm.method" type="text" class="w-full rounded-lg border border-slate-600 bg-[#141a22] px-3 py-2 text-sm text-white" />
            </label>
            <label class="block">
              <span class="mb-1 block text-xs text-slate-400">Notas</span>
              <input v-model="payForm.notes" type="text" class="w-full rounded-lg border border-slate-600 bg-[#141a22] px-3 py-2 text-sm text-white" />
            </label>
          </div>
          <p v-else-if="payInvoice.status === 'aprobada'" class="mt-3 text-sm text-slate-400">
            Marque la factura como <strong class="text-slate-200">enviada</strong> para registrar pagos.
          </p>
          <p v-else class="mt-3 text-sm text-slate-400">
            Solo se registran abonos con factura enviada o parcialmente pagada.
          </p>
        </template>
        <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-slate-700/80 pt-4">
          <button type="button" class="rounded-lg border border-slate-600 px-4 py-2 text-sm font-semibold text-slate-200 hover:bg-slate-800" @click="closePayModal">
            Cerrar
          </button>
          <button
            v-if="payInvoice && payCanRegister"
            type="button"
            class="rounded-lg bg-gradient-to-r from-blue-600 to-violet-600 px-4 py-2 text-sm font-semibold text-white disabled:opacity-50"
            :disabled="paySubmitting"
            @click="submitPayModal"
          >
            {{ paySubmitting ? 'Registrando…' : 'Registrar pago' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
