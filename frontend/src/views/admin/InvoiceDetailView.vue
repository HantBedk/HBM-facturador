<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import {
  addInvoicePayment,
  deleteInvoicePayment,
  downloadInvoicePdfBlob,
  fetchAdminInvoice,
  patchInvoiceStatus,
  regenerateInvoicePublicAccess,
} from '@/services/invoicesApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'

const uiDialog = useUiDialogStore()

function formatDateShort(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { year: 'numeric', month: 'short', day: 'numeric' })
}

const route = useRoute()
const id = computed(() => route.params.id)

const invoice = ref(null)
const loading = ref(true)
const error = ref('')
const actionError = ref('')
/** Código de verificación para el cliente (solo se muestra al aprobar o al regenerar). */
const publicVerificationShown = ref('')
const publicVerificationNotice = ref('')
const regeneratingPublic = ref(false)

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

/** Pagos solo tras ENVIADA (ETAPA 5); abonos hasta cubrir total. */
const balanceNum = computed(() => {
  const b = invoice.value?.financial?.balance
  if (b === undefined || b === null) return null
  const n = Number(b)
  return Number.isNaN(n) ? null : n
})

const canRegisterPayment = computed(() => {
  const st = invoice.value?.status
  if (!st || !['enviada', 'parcialmente_pagada'].includes(st)) return false
  if (balanceNum.value !== null && balanceNum.value <= 0) return false
  return true
})

const canDeletePayment = computed(() => {
  const st = invoice.value?.status
  if (!st || !['enviada', 'parcialmente_pagada', 'pagada'].includes(st)) return false
  return (invoice.value?.payments || []).length > 0
})

const issuedAtLabel = computed(() => formatDateShort(invoice.value?.created_at))

const clientContactPreview = computed(() => {
  const names = [...new Set((invoice.value?.services || []).map((s) => s.client_name).filter(Boolean))]
  return names.length ? names.join(', ') : '—'
})

async function onAprobar() {
  actionError.value = ''
  publicVerificationShown.value = ''
  publicVerificationNotice.value = ''
  try {
    const res = await patchInvoiceStatus(id.value, 'aprobada')
    invoice.value = res
    if (res.public_verification_code) {
      publicVerificationShown.value = res.public_verification_code
      publicVerificationNotice.value = res.public_verification_notice || ''
    }
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

async function onRegeneratePublicCode() {
  const ok = await uiDialog.confirm({
    title: 'Regenerar código público',
    message:
      'Se generará un nuevo código de verificación. El anterior dejará de ser válido para la consulta pública. ¿Continuar?',
    danger: true,
    confirmLabel: 'Regenerar',
  })
  if (!ok) return
  actionError.value = ''
  publicVerificationShown.value = ''
  publicVerificationNotice.value = ''
  regeneratingPublic.value = true
  try {
    const res = await regenerateInvoicePublicAccess(id.value)
    invoice.value = res
    if (res.public_verification_code) {
      publicVerificationShown.value = res.public_verification_code
      publicVerificationNotice.value = res.public_verification_notice || ''
    }
  } catch (e) {
    actionError.value = e.data?.message || e.message || 'No se pudo regenerar el código.'
  } finally {
    regeneratingPublic.value = false
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
  const ok = await uiDialog.confirm({
    title: 'Eliminar pago',
    message: '¿Eliminar este pago del registro?',
    danger: true,
    confirmLabel: 'Eliminar',
  })
  if (!ok) return
  actionError.value = ''
  try {
    invoice.value = await deleteInvoicePayment(id.value, pid)
  } catch (e) {
    actionError.value = e.data?.message || e.message || 'No se pudo eliminar.'
  }
}

function triggerPdfDownload(blob, suggestedName) {
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = suggestedName
  a.click()
  URL.revokeObjectURL(a.href)
}

async function onPdfPreview() {
  actionError.value = ''
  try {
    const blob = await downloadInvoicePdfBlob(id.value, { preview: true })
    triggerPdfDownload(blob, `factura-${invoice.value?.code || id.value}-vista-previa.pdf`)
  } catch (e) {
    actionError.value = e.message || 'No se pudo generar la vista previa.'
  }
}

async function onPdfOfficial() {
  actionError.value = ''
  try {
    const blob = await downloadInvoicePdfBlob(id.value, { preview: false })
    triggerPdfDownload(blob, `factura-${invoice.value?.code || id.value}.pdf`)
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
        <button v-if="invoice.status === 'borrador'" type="button" class="btn secondary" @click="onPdfPreview">
          Vista previa PDF
        </button>
        <button v-if="invoice.status !== 'borrador'" type="button" class="btn secondary" @click="onPdfOfficial">
          Descargar PDF
        </button>
        <RouterLink v-if="canEdit" class="btn primary" :to="`/admin/facturas/${invoice.id}/editar`">Editar borrador</RouterLink>
      </div>
    </header>

    <p v-if="loading" class="muted">Cargando…</p>
    <p v-else-if="error" class="banner err">{{ error }}</p>

    <div v-else-if="invoice">
      <p v-if="actionError" class="banner err">{{ actionError }}</p>
      <div class="card status-row" :data-phase="invoice.status">
        <span class="pill" :data-st="invoice.status">{{ invoice.status_label }}</span>
        <span v-if="invoice.sent_at" class="muted">Enviada: {{ new Date(invoice.sent_at).toLocaleString('es-CO') }}</span>
      </div>

      <div
        v-if="publicVerificationShown"
        class="card public-code-card"
        role="status"
      >
        <h2 class="public-code-title">Código para consulta pública del cliente</h2>
        <p class="muted small">
          Comparta con el cliente junto al código de factura (página de consulta sin login). Guárdelo en lugar seguro; no se
          volverá a mostrar salvo que genere uno nuevo.
        </p>
        <p v-if="publicVerificationNotice" class="muted small">{{ publicVerificationNotice }}</p>
        <code class="public-code">{{ publicVerificationShown }}</code>
      </div>

      <div
        v-if="invoice.status !== 'borrador' && invoice.public_access_configured"
        class="card"
      >
        <h2>Consulta pública</h2>
        <p class="muted">
          El cliente usa <strong>/consulta-factura</strong> con el código de factura y su código de verificación. Si perdió el
          código o debe invalidarlo, genere uno nuevo.
        </p>
        <button
          type="button"
          class="btn secondary"
          :disabled="regeneratingPublic"
          @click="onRegeneratePublicCode"
        >
          {{ regeneratingPublic ? 'Generando…' : 'Generar nuevo código de verificación' }}
        </button>
      </div>

      <div class="card preview-sheet">
        <div class="preview-head">
          <div>
            <p class="preview-issuer">{{ invoice.billing_issuer?.nombre || '—' }}</p>
            <p v-if="invoice.billing_issuer?.nit" class="muted tiny">NIT {{ invoice.billing_issuer.nit }}</p>
            <p v-if="invoice.billing_issuer?.direccion" class="muted tiny">{{ invoice.billing_issuer.direccion }}</p>
          </div>
          <span v-if="invoice.status === 'borrador'" class="preview-badge">Vista previa</span>
        </div>
        <h2 class="preview-title">Factura {{ invoice.code }}</h2>
        <p class="preview-meta muted">
          Emisión: {{ issuedAtLabel }} · Periodo: {{ invoice.period_label }}
          <span v-if="invoice.sent_at"> · Enviada: {{ formatDateShort(invoice.sent_at) }}</span>
        </p>
        <div class="preview-grid">
          <div>
            <h3>Cliente</h3>
            <p class="strong">{{ invoice.company?.nombre }}</p>
            <p class="muted">NIT: {{ invoice.company?.nit || '—' }}</p>
            <p v-if="invoice.company?.telefono" class="muted">Tel: {{ invoice.company.telefono }}</p>
            <p v-if="invoice.company?.correo" class="muted">{{ invoice.company.correo }}</p>
            <p class="muted">Contacto / obra: {{ clientContactPreview }}</p>
          </div>
          <div>
            <h3>Resumen</h3>
            <p class="strong">Total: {{ money(invoice.total) }}</p>
            <p v-if="invoice.financial" class="muted">
              Pagado: {{ money(invoice.financial.total_paid) }} · Saldo: {{ money(invoice.financial.balance) }}
            </p>
          </div>
        </div>
        <div class="table-wrap preview-table-wrap">
          <table class="table preview-table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Código</th>
                <th>Descripción</th>
                <th>Técnico</th>
                <th class="num">Valor</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in invoice.services || []" :key="s.id">
                <td>{{ s.service_date }}</td>
                <td class="mono">{{ s.code }}</td>
                <td class="desc">{{ s.description }}</td>
                <td>{{ s.empleado?.nombre || '—' }}</td>
                <td class="num">{{ money(s.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-if="invoice.status === 'borrador'" class="preview-hint">
          Revise datos y montos antes de aprobar. El PDF oficial se habilita al aprobar la factura; en borrador solo puede
          descargar una vista previa con marca de agua.
        </p>
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
        <div v-if="canRegisterPayment" class="pay-form">
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
        <p v-else-if="invoice.status === 'aprobada'" class="muted hint-pay">
          Marque la factura como <strong>enviada</strong> para habilitar el registro de pagos y cobros.
        </p>
        <p v-else class="muted">Los pagos se registran cuando la factura está <strong>enviada</strong> (flujo: borrador → aprobada → enviada → abonos).</p>

        <div class="table-wrap mt">
          <table class="table">
            <thead>
              <tr>
                <th>Fecha</th>
                <th>Método</th>
                <th class="num">Monto</th>
                <th>Notas</th>
                <th v-if="canDeletePayment" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in invoice.payments || []" :key="p.id">
                <td>{{ p.payment_date }}</td>
                <td>{{ p.method }}</td>
                <td class="num">{{ money(p.amount) }}</td>
                <td>{{ p.notes || '—' }}</td>
                <td v-if="canDeletePayment">
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
  border-left: 4px solid rgba(148, 163, 184, 0.35);
}

.status-row[data-phase='borrador'] {
  border-left-color: rgba(251, 191, 36, 0.55);
  background: rgba(251, 191, 36, 0.06);
}

.status-row[data-phase='aprobada'] {
  border-left-color: rgba(129, 140, 248, 0.55);
  background: rgba(129, 140, 248, 0.08);
}

.status-row[data-phase='enviada'] {
  border-left-color: rgba(56, 189, 248, 0.55);
  background: rgba(56, 189, 248, 0.08);
}

.status-row[data-phase='parcialmente_pagada'] {
  border-left-color: rgba(251, 191, 36, 0.65);
  background: rgba(251, 191, 36, 0.07);
}

.status-row[data-phase='pagada'] {
  border-left-color: rgba(74, 222, 128, 0.55);
  background: rgba(74, 222, 128, 0.08);
}

.hint-pay {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(56, 189, 248, 0.08);
  border: 1px solid rgba(56, 189, 248, 0.25);
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

.preview-sheet {
  background: linear-gradient(180deg, rgba(248, 250, 252, 0.08) 0%, rgba(15, 23, 42, 0.55) 100%);
  border-color: rgba(148, 163, 184, 0.35);
}

.preview-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 0.75rem;
  padding-bottom: 0.65rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.2);
}

.preview-issuer {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: #f1f5f9;
}

.tiny {
  font-size: 0.78rem;
  margin: 0.15rem 0;
}

.preview-badge {
  flex-shrink: 0;
  font-size: 0.72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  padding: 0.25rem 0.55rem;
  border-radius: 6px;
  background: rgba(251, 191, 36, 0.15);
  border: 1px solid rgba(251, 191, 36, 0.45);
  color: #fde68a;
}

.preview-title {
  margin: 0 0 0.35rem;
  font-size: 1.15rem;
  color: #f8fafc;
}

.preview-meta {
  margin: 0 0 1rem;
  font-size: 0.85rem;
}

.preview-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
  margin-bottom: 1rem;
}

.preview-grid h3 {
  margin: 0 0 0.35rem;
  font-size: 0.85rem;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.preview-table-wrap {
  margin-top: 0.5rem;
}

.preview-table {
  font-size: 0.82rem;
}

.preview-hint {
  margin: 0.85rem 0 0;
  padding: 0.65rem 0.75rem;
  border-radius: 10px;
  background: rgba(56, 189, 248, 0.08);
  border: 1px solid rgba(56, 189, 248, 0.22);
  color: #bae6fd;
  font-size: 0.85rem;
}

.public-code-card {
  border-left: 4px solid rgba(56, 189, 248, 0.65);
  background: rgba(56, 189, 248, 0.06);
}

.public-code-title {
  margin: 0 0 0.5rem;
  font-size: 1rem;
  color: #e2e8f0;
}

.public-code {
  display: block;
  margin-top: 0.75rem;
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(2, 6, 23, 0.5);
  border: 1px solid rgba(148, 163, 184, 0.25);
  color: #bae6fd;
  font-size: 0.85rem;
  word-break: break-all;
  user-select: all;
}

.small {
  font-size: 0.85rem;
  line-height: 1.45;
}
</style>
