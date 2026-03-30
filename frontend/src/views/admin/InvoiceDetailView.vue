<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import {
  addInvoicePayment,
  deleteInvoicePayment,
  downloadInvoicePdfBlob,
  fetchAdminInvoice,
  patchInvoiceStatus,
} from '@/services/invoicesApi.js'

const route = useRoute()
const id = computed(() => route.params.id)

const invoice = ref(null)
const loading = ref(true)
const error = ref('')
const actionError = ref('')

const payForm = ref({
  amount: '',
  payment_date: new Date().toISOString().slice(0, 10),
  method: 'Transferencia',
  notes: '',
})

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

async function load() {
  error.value = ''
  loading.value = true
  try {
    invoice.value = await fetchAdminInvoice(id.value)
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar la factura.'
    invoice.value = null
  } finally {
    loading.value = false
  }
}

onMounted(load)

watch(id, () => {
  actionError.value = ''
  load()
})

const canEdit = computed(() => invoice.value?.status === 'borrador')
const canApprove = computed(() => invoice.value?.status === 'borrador')
const canSend = computed(() => invoice.value?.status === 'aprobada')
const canPay = computed(
  () => invoice.value && !['borrador'].includes(invoice.value.status)
)

async function onAprobar() {
  actionError.value = ''
  try {
    invoice.value = await patchInvoiceStatus(id.value, 'aprobada')
  } catch (e) {
    actionError.value = e.data?.message || e.message || 'No se pudo aprobar.'
  }
}

async function onEnviar() {
  actionError.value = ''
  try {
    invoice.value = await patchInvoiceStatus(id.value, 'enviada')
  } catch (e) {
    actionError.value = e.data?.message || e.message || 'No se pudo marcar como enviada.'
  }
}

async function onAddPayment() {
  actionError.value = ''
  const amt = Number(payForm.value.amount)
  if (Number.isNaN(amt) || amt < 0.01) {
    actionError.value = 'Indique un monto válido.'
    return
  }
  try {
    invoice.value = await addInvoicePayment(id.value, {
      amount: amt,
      payment_date: payForm.value.payment_date,
      method: payForm.value.method.trim() || 'Otro',
      notes: payForm.value.notes.trim() || undefined,
    })
    payForm.value = {
      amount: '',
      payment_date: new Date().toISOString().slice(0, 10),
      method: 'Transferencia',
      notes: '',
    }
  } catch (e) {
    actionError.value = e.data?.message || e.message || 'No se pudo registrar el pago.'
  }
}

async function onDeletePayment(pid) {
  if (!window.confirm('¿Eliminar este pago del registro?')) return
  actionError.value = ''
  try {
    invoice.value = await deleteInvoicePayment(id.value, pid)
  } catch (e) {
    actionError.value = e.data?.message || e.message || 'No se pudo eliminar.'
  }
}

async function onPdf() {
  actionError.value = ''
  try {
    const blob = await downloadInvoicePdfBlob(id.value)
    const a = document.createElement('a')
    a.href = URL.createObjectURL(blob)
    a.download = `factura-${invoice.value?.code || id.value}.pdf`
    a.click()
    URL.revokeObjectURL(a.href)
  } catch (e) {
    actionError.value = e.message || 'No se pudo descargar el PDF.'
  }
}
</script>

<template>
  <section class="page">
    <header class="head">
      <div>
        <RouterLink class="back" to="/admin/facturas">← Volver al listado</RouterLink>
        <h1 v-if="invoice">Factura {{ invoice.code }}</h1>
        <h1 v-else-if="!loading">Factura</h1>
        <p v-if="invoice" class="lede">{{ invoice.period_label }} · {{ invoice.company?.nombre }}</p>
      </div>
      <div v-if="invoice" class="head-actions">
        <button type="button" class="btn secondary" @click="onPdf">Descargar PDF</button>
        <RouterLink v-if="canEdit" class="btn primary" :to="`/admin/facturas/${invoice.id}/editar`">Editar borrador</RouterLink>
      </div>
    </header>

    <p v-if="loading" class="muted">Cargando…</p>
    <p v-else-if="error" class="banner err">{{ error }}</p>

    <div v-else-if="invoice">
      <p v-if="actionError" class="banner err">{{ actionError }}</p>
      <div class="card status-row">
        <span class="pill" :data-st="invoice.status">{{ invoice.status_label }}</span>
        <span v-if="invoice.sent_at" class="muted">Enviada: {{ new Date(invoice.sent_at).toLocaleString('es-CO') }}</span>
      </div>

      <div class="card actions-bar" v-if="canApprove || canSend">
        <button v-if="canApprove" type="button" class="btn primary" @click="onAprobar">Aprobar factura</button>
        <button v-if="canSend" type="button" class="btn primary" @click="onEnviar">Marcar como enviada</button>
      </div>

      <div class="grid-2">
        <div class="card">
          <h2>Empresa</h2>
          <p class="strong">{{ invoice.company?.nombre || '—' }}</p>
          <p class="muted">NIT: {{ invoice.company?.nit || '—' }}</p>
        </div>
        <div class="card">
          <h2>Totales</h2>
          <p>Subtotal: {{ money(invoice.subtotal) }}</p>
          <p class="strong">Total: {{ money(invoice.total) }}</p>
          <template v-if="invoice.financial">
            <p>Pagado: {{ money(invoice.financial.total_paid) }}</p>
            <p>Saldo: {{ money(invoice.financial.balance) }}</p>
          </template>
        </div>
      </div>

      <div class="card">
        <h2>Servicios incluidos</h2>
        <div class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th>Código</th>
                <th>Fecha</th>
                <th>Tipo</th>
                <th>Descripción</th>
                <th class="num">Valor</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in invoice.services || []" :key="s.id">
                <td class="mono">{{ s.code }}</td>
                <td>{{ s.service_date }}</td>
                <td>{{ s.service_type }}</td>
                <td class="desc">{{ s.description }}</td>
                <td class="num">{{ money(s.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <h2>Pagos</h2>
        <div v-if="canPay" class="pay-form">
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
          <button type="button" class="btn primary" @click="onAddPayment">Registrar pago</button>
        </div>
        <p v-else class="muted">Los pagos se registran cuando la factura ya no está en borrador.</p>

        <div class="table-wrap mt">
          <table class="table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Método</th>
                <th class="num">Monto</th>
                <th>Notas</th>
                <th v-if="canPay" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in invoice.payments || []" :key="p.id">
                <td>{{ p.payment_date }}</td>
                <td>{{ p.method }}</td>
                <td class="num">{{ money(p.amount) }}</td>
                <td>{{ p.notes || '—' }}</td>
                <td v-if="canPay">
                  <button type="button" class="link danger" @click="onDeletePayment(p.id)">Quitar</button>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="!(invoice.payments || []).length" class="muted">Sin pagos registrados.</p>
        </div>
      </div>
    </div>
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

.head-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.back {
  display: inline-block;
  color: #94a3b8;
  text-decoration: none;
  font-size: 0.9rem;
  margin-bottom: 0.35rem;
}

h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  color: #f8fafc;
}

h2 {
  margin: 0 0 0.75rem;
  font-size: 1rem;
  color: #e2e8f0;
}

.lede {
  margin: 0;
  color: #94a3b8;
  font-size: 0.9rem;
}

.card {
  padding: 1.1rem 1.25rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 1rem;
}

.status-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 1rem;
}

.actions-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.grid-2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 1rem;
}

.strong {
  font-weight: 600;
  color: #f1f5f9;
}

.pill {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.8rem;
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

.table-wrap {
  overflow-x: auto;
}

.table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
}

.table th,
.table td {
  padding: 0.45rem 0.35rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
  text-align: left;
}

.table th {
  color: #94a3b8;
  font-size: 0.75rem;
  text-transform: uppercase;
}

.mono {
  font-family: ui-monospace, monospace;
  font-size: 0.8rem;
}

.desc {
  max-width: 280px;
  white-space: pre-wrap;
  word-break: break-word;
}

.num {
  text-align: right;
  white-space: nowrap;
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

.pay-form span {
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
  padding: 0.45rem 0.55rem;
  font: inherit;
}

.mt {
  margin-top: 0.75rem;
}

.link {
  background: none;
  border: none;
  color: #7dd3fc;
  cursor: pointer;
  font-size: 0.85rem;
  text-decoration: underline;
}

.link.danger {
  color: #fca5a5;
}

.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}

.muted {
  color: #94a3b8;
}

.btn {
  display: inline-flex;
  padding: 0.5rem 0.9rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: transparent;
  color: #e2e8f0;
  text-decoration: none;
  align-items: center;
}

.btn.primary {
  background: linear-gradient(90deg, #2563eb, #7c3aed);
  border: none;
  color: #fff;
}

.btn.secondary:hover {
  border-color: #38bdf8;
  color: #fff;
}
</style>
