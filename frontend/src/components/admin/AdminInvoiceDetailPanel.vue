<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import {
  addInvoicePayment,
  deleteInvoicePayment,
  downloadInvoicePdfBlob,
  fetchAdminInvoice,
  patchInvoiceStatus,
  sendInvoiceEmailToCompany,
} from '@/services/invoicesApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'
import { useClientSortedRows } from '@/composables/useClientSortedRows.js'
import { openPdfBlobInNewTab, triggerPdfDownload } from '@/utils/pdfBlob.js'
import { confirmInvoiceEmailSend } from '@/utils/invoiceEmitterSendGate.js'

const uiDialog = useUiDialogStore()
const router = useRouter()

const props = defineProps({
  open: { type: Boolean, default: false },
  /** ID de factura a cargar cuando `open` es true */
  invoiceId: { type: [Number, String], default: null },
  /**
   * Listado → «Revisar y aprobar»: muestra la factura completa, pie con Rechazar / Aprobar y oculta el bloque duplicado de aprobar en el cuerpo.
   */
  reviewMode: { type: Boolean, default: false },
  /** z-index cuando el drawer se abre sobre otro overlay (p. ej. panel del dashboard). */
  overlayZIndex: { type: Number, default: 90 },
})

const emit = defineEmits(['close', 'changed'])

function formatDateShort(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { year: 'numeric', month: 'short', day: 'numeric' })
}

const invoice = ref(null)
const loading = ref(false)
const error = ref('')
const actionError = ref('')
const approveSubmitting = ref(false)

const svcListSource = computed(() => invoice.value?.services || [])
const {
  sortedRows: sortedInvoiceServices,
  toggleSort: toggleInvSvcSort,
  sortIndicator: invSvcSortInd,
  ariaSort: invSvcAriaSort,
} = useClientSortedRows(
  svcListSource,
  {
    service_date: (s) => s.service_date || '',
    code: (s) => s.code || '',
    description: (s) => s.description || '',
    service_type: (s) => s.service_type || '',
    empleado: (s) => s.empleado?.nombre || '',
    amount: (s) => Number(s.amount) || 0,
  },
  { initialKey: 'service_date', initialDir: 'desc' }
)

const payListSource = computed(() => invoice.value?.payments || [])
const {
  sortedRows: sortedInvoicePayments,
  toggleSort: toggleInvPaySort,
  sortIndicator: invPaySortInd,
  ariaSort: invPayAriaSort,
} = useClientSortedRows(
  payListSource,
  {
    payment_date: (p) => p.payment_date || '',
    method: (p) => p.method || '',
    amount: (p) => Number(p.amount) || 0,
    notes: (p) => p.notes || '',
  },
  { initialKey: 'payment_date', initialDir: 'asc' }
)

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

async function reload() {
  const id = props.invoiceId
  if (id == null || id === '') return
  error.value = ''
  loading.value = true
  try {
    invoice.value = await fetchAdminInvoice(id)
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar la factura.'
    invoice.value = null
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.open, props.invoiceId],
  async ([op, id]) => {
    if (!op) {
      invoice.value = null
      error.value = ''
      actionError.value = ''
      return
    }
    if (id == null || id === '') return
    await reload()
  }
)

const idRef = computed(() => props.invoiceId)

const hasRegisteredCompany = computed(() => {
  const id = invoice.value?.company_id
  return id !== null && id !== undefined && id !== ''
})

const canEdit = computed(() => invoice.value?.status === 'borrador' && hasRegisteredCompany.value)
const canApprove = computed(() => invoice.value?.status === 'borrador' && hasRegisteredCompany.value)
const canSend = computed(() => invoice.value?.status === 'aprobada')

const sendEmailBusy = ref(false)

const companyEmailValid = computed(() => {
  const raw = invoice.value?.company?.correo
  const s = raw != null ? String(raw).trim() : ''
  if (!s) return false
  return /^[^\s@]+@[^\s@]+$/u.test(s)
})

/** No borrador y correo de empresa válido (el API ahora incluye `correo` en la relación company). */
const canEmailPdfToCompany = computed(() => {
  const inv = invoice.value
  if (!inv || inv.status === 'borrador') return false
  return companyEmailValid.value
})

/** Muestra el botón junto a PDF aunque falte correo (queda deshabilitado o avisa al pulsar). */
const showToolbarEnviarFactura = computed(() => {
  if (!invoice.value || invoice.value.status === 'borrador') return false
  return hasRegisteredCompany.value
})

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

const drawerKicker = computed(() => {
  if (props.reviewMode && invoice.value?.status === 'borrador') {
    return 'Revisión antes de aprobar'
  }
  return 'Detalle de factura'
})

const showReviewFooter = computed(
  () => props.reviewMode && invoice.value?.status === 'borrador' && !loading.value && !error.value
)

const issuedAtLabel = computed(() => formatDateShort(invoice.value?.created_at))

const clientContactPreview = computed(() => {
  const names = [...new Set((invoice.value?.services || []).map((s) => s.client_name).filter(Boolean))]
  return names.length ? names.join(', ') : '—'
})

async function onAprobarYEnviar() {
  actionError.value = ''
  if (!invoice.value || invoice.value.status !== 'borrador' || !hasRegisteredCompany.value) return
  if (!companyEmailValid.value) {
    await uiDialog.alert({
      title: 'Correo requerido',
      message: 'Para aprobar y enviar, la empresa debe tener un correo electrónico válido en el directorio.',
    })
    return
  }
  const ok = await uiDialog.confirm({
    title: 'Aprobar y enviar factura',
    message:
      'Una vez aprobada y enviada al cliente, esta factura ya no podrá modificarse como borrador ni alterarse su contenido facturado desde el panel. Se enviará el PDF al correo registrado en la empresa. ¿Desea continuar?',
    confirmLabel: 'Aceptar',
    cancelLabel: 'Rechazar',
    danger: true,
  })
  if (!ok) return

  const canSend = await confirmInvoiceEmailSend({ invoice: invoice.value, uiDialog, router })
  if (!canSend) return

  const id = idRef.value
  approveSubmitting.value = true
  try {
    invoice.value = await patchInvoiceStatus(id, 'aprobada')
    try {
      const r = await sendInvoiceEmailToCompany(id)
      await uiDialog.alert({
        title: 'Factura enviada',
        message: r?.message || 'Correo con PDF enviado al cliente.',
      })
    } catch (emailErr) {
      const msg =
        emailErr.data?.errors?.company?.[0] ||
        emailErr.data?.errors?.emitter?.[0] ||
        emailErr.data?.message ||
        emailErr.message ||
        'Error desconocido.'
      actionError.value =
        'La factura quedó aprobada, pero el envío por correo falló: ' +
        msg +
        ' Revise Configuración → Correo del sistema y use «Enviar factura» en la barra superior.'
      emit('changed')
      return
    }
    invoice.value = await patchInvoiceStatus(id, 'enviada')
    emit('changed')
    if (props.reviewMode) {
      emit('close')
    }
  } catch (e) {
    actionError.value = e.data?.message || e.message || 'No se pudo completar la acción.'
  } finally {
    approveSubmitting.value = false
  }
}

async function onEnviar() {
  actionError.value = ''
  try {
    invoice.value = await patchInvoiceStatus(idRef.value, 'enviada')
    emit('changed')
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
    invoice.value = await addInvoicePayment(idRef.value, {
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
    emit('changed')
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
    invoice.value = await deleteInvoicePayment(idRef.value, pid)
    emit('changed')
  } catch (e) {
    actionError.value = e.data?.message || e.message || 'No se pudo eliminar.'
  }
}

async function onPdfPreviewView() {
  actionError.value = ''
  try {
    const blob = await downloadInvoicePdfBlob(idRef.value, { preview: true })
    openPdfBlobInNewTab(blob)
  } catch (e) {
    actionError.value = e.message || 'No se pudo abrir la vista previa en PDF.'
  }
}

async function onPdfPreviewDownload() {
  actionError.value = ''
  try {
    const blob = await downloadInvoicePdfBlob(idRef.value, { preview: true })
    triggerPdfDownload(blob, `factura-${invoice.value?.code || idRef.value}-vista-previa.pdf`)
  } catch (e) {
    actionError.value = e.message || 'No se pudo descargar la vista previa.'
  }
}

async function onPdfOfficialView() {
  actionError.value = ''
  try {
    const blob = await downloadInvoicePdfBlob(idRef.value, { preview: false })
    openPdfBlobInNewTab(blob)
  } catch (e) {
    actionError.value = e.message || 'No se pudo abrir el PDF.'
  }
}

async function onPdfOfficialDownload() {
  actionError.value = ''
  try {
    const blob = await downloadInvoicePdfBlob(idRef.value, { preview: false })
    triggerPdfDownload(blob, `factura-${invoice.value?.code || idRef.value}.pdf`)
  } catch (e) {
    actionError.value = e.message || 'No se pudo descargar el PDF.'
  }
}

async function onToolbarEnviarFacturaClick() {
  if (!companyEmailValid.value) {
    await uiDialog.alert({
      title: 'No se puede enviar',
      message:
        'La empresa no tiene un correo válido en el directorio. Edítela, guarde un correo y vuelva a abrir esta factura.',
    })
    return
  }
  await onSendInvoiceEmail()
}

async function onSendInvoiceEmail() {
  const inv = invoice.value
  const panelId = idRef.value
  if (!inv || panelId == null || panelId === '') return
  if (String(inv.id) !== String(panelId)) {
    actionError.value = 'El panel no coincide con la factura cargada. Cierre y vuelva a abrir.'
    return
  }
  if (!canEmailPdfToCompany.value) return
  const emitterOk = await confirmInvoiceEmailSend({ invoice: inv, uiDialog, router })
  if (!emitterOk) return
  const code = inv.code || String(panelId)
  const correo = String(inv.company?.correo || '').trim()
  const ok = await uiDialog.confirm({
    title: 'Enviar factura por correo',
    message: `Se enviará el PDF oficial de la factura ${code} (esta misma, abierta en el panel) a ${correo}. ¿Continuar?`,
    confirmLabel: 'Enviar',
  })
  if (!ok) return
  actionError.value = ''
  sendEmailBusy.value = true
  try {
    const r = await sendInvoiceEmailToCompany(panelId)
    await uiDialog.alert({
      title: 'Correo enviado',
      message: r.message || 'Factura enviada.',
    })
    emit('changed')
  } catch (e) {
    const msg =
      e.data?.errors?.company?.[0] ||
      e.data?.errors?.emitter?.[0] ||
      e.data?.message ||
      e.message ||
      'No se pudo enviar el correo.'
    actionError.value = msg
  } finally {
    sendEmailBusy.value = false
  }
}

defineExpose({ reload })
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="drawer-root" :style="{ zIndex: overlayZIndex }" aria-hidden="false">
      <div class="drawer-backdrop" @click.self="emit('close')" />
      <aside
        class="drawer-panel"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="invoice ? 'drawer-inv-title' : 'drawer-inv-loading'"
      >
        <header class="drawer-header">
          <div class="drawer-header-text">
            <p class="drawer-kicker">{{ drawerKicker }}</p>
            <h2 :id="invoice ? 'drawer-inv-title' : 'drawer-inv-loading'" class="drawer-title">
              {{ invoice?.code || (loading ? 'Cargando…' : '—') }}
            </h2>
            <p v-if="invoice" class="muted drawer-sub">{{ invoice.period_label }} · {{ invoice.company?.nombre }}</p>
          </div>
          <div class="drawer-header-actions">
            <RouterLink
              v-if="invoice"
              class="btn secondary btn-compact"
              :to="`/admin/facturas/${invoice.id}`"
              @click="emit('close')"
            >
              Página completa
            </RouterLink>
            <button type="button" class="drawer-close" aria-label="Cerrar panel" @click="emit('close')">
              <span aria-hidden="true">×</span>
            </button>
          </div>
        </header>

        <div class="drawer-toolbar" v-if="invoice">
          <template v-if="invoice.status === 'borrador'">
            <button type="button" class="btn secondary btn-compact" @click="onPdfPreviewView">Ver PDF</button>
            <button type="button" class="btn secondary btn-compact" @click="onPdfPreviewDownload">Descargar PDF</button>
          </template>
          <template v-else>
            <button type="button" class="btn secondary btn-compact" @click="onPdfOfficialView">Ver PDF</button>
            <button type="button" class="btn secondary btn-compact" @click="onPdfOfficialDownload">Descargar PDF</button>
            <button
              v-if="showToolbarEnviarFactura"
              type="button"
              class="btn secondary btn-compact"
              :disabled="sendEmailBusy || !companyEmailValid"
              :title="!companyEmailValid ? 'Agregue un correo válido en la ficha de la empresa' : ''"
              @click="onToolbarEnviarFacturaClick"
            >
              {{ sendEmailBusy ? 'Enviando…' : 'Enviar factura' }}
            </button>
          </template>
          <RouterLink v-if="canEdit" class="btn primary btn-compact" :to="`/admin/facturas/${invoice.id}/editar`" @click="emit('close')">
            Editar borrador
          </RouterLink>
        </div>

        <div class="drawer-body">
          <p v-if="loading" class="muted pad">Cargando…</p>
          <p v-else-if="error" class="banner err">{{ error }}</p>

          <template v-else-if="invoice">
            <p v-if="actionError" class="banner err">{{ actionError }}</p>

            <div class="card status-row" :data-phase="invoice.status">
              <span class="pill" :data-st="invoice.status">{{ invoice.status_label }}</span>
              <span v-if="invoice.sent_at" class="muted">Enviada: {{ new Date(invoice.sent_at).toLocaleString('es-CO') }}</span>
            </div>

            <div
              v-if="invoice.status === 'borrador' && !hasRegisteredCompany"
              class="card banner err"
            >
              <p class="muted small" style="margin: 0">
                Este borrador no está vinculado a una empresa del directorio. Ya no se puede editar ni aprobar; elimínelo y cree una
                factura nueva contra una empresa registrada.
              </p>
            </div>

            <div v-if="reviewMode && invoice.status === 'borrador'" class="card review-hint-card">
              <p class="muted small review-hint-text">
                Revise el detalle y la vista previa PDF. Use <strong>Editar borrador</strong> si debe corregir algo. Si no aprueba,
                pulse <strong>Rechazar</strong> para cerrar sin cambiar el estado.
              </p>
            </div>

            <div v-if="invoice.status !== 'borrador'" class="card">
              <h2>Consulta pública</h2>
              <p class="muted small">
                En <strong>/consulta-factura</strong> el cliente puede ingresar el <strong>código de esta factura</strong> para ver el
                detalle y descargar PDF, o el <strong>NIT de su empresa</strong> para listar todas las facturas disponibles. No requiere
                inicio de sesión ni código de verificación.
              </p>
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
                      <th scope="col" :aria-sort="invSvcAriaSort('service_date')">
                        <button type="button" class="th-sort" @click="toggleInvSvcSort('service_date')">
                          Fecha<span class="sort-ind" aria-hidden="true">{{ invSvcSortInd('service_date') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="invSvcAriaSort('code')">
                        <button type="button" class="th-sort" @click="toggleInvSvcSort('code')">
                          Código<span class="sort-ind" aria-hidden="true">{{ invSvcSortInd('code') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="invSvcAriaSort('description')">
                        <button type="button" class="th-sort" @click="toggleInvSvcSort('description')">
                          Descripción<span class="sort-ind" aria-hidden="true">{{ invSvcSortInd('description') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="invSvcAriaSort('empleado')">
                        <button type="button" class="th-sort" @click="toggleInvSvcSort('empleado')">
                          Técnico<span class="sort-ind" aria-hidden="true">{{ invSvcSortInd('empleado') }}</span>
                        </button>
                      </th>
                      <th class="num" scope="col" :aria-sort="invSvcAriaSort('amount')">
                        <button type="button" class="th-sort th-sort--end" @click="toggleInvSvcSort('amount')">
                          Valor<span class="sort-ind" aria-hidden="true">{{ invSvcSortInd('amount') }}</span>
                        </button>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="s in sortedInvoiceServices" :key="s.id">
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
                Revise datos y montos antes de aprobar. En borrador puede ver o descargar una vista previa con marca de agua.
              </p>
            </div>

            <div class="card actions-bar" v-if="(canApprove && !reviewMode) || canSend">
              <button
                v-if="canApprove && !reviewMode"
                type="button"
                class="btn primary btn-compact"
                :disabled="approveSubmitting || !companyEmailValid"
                :title="!companyEmailValid ? 'La empresa necesita un correo válido para aprobar y enviar' : ''"
                @click="onAprobarYEnviar"
              >
                {{ approveSubmitting ? 'Procesando…' : 'Aprobar y enviar' }}
              </button>
              <template v-if="canSend">
                <p class="muted small send-hint">
                  «Marcar como enviada» solo marca el estado para registrar cobros; <strong>no envía correo</strong>. Use
                  <strong>Enviar factura</strong> en la barra superior si el cliente aún no recibió el PDF.
                </p>
                <button type="button" class="btn primary btn-compact" @click="onEnviar">Marcar como enviada</button>
              </template>
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
                <p v-if="Number(invoice.iva_amount) > 0">IVA: {{ money(invoice.iva_amount) }}</p>
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
                      <th scope="col" :aria-sort="invSvcAriaSort('code')">
                        <button type="button" class="th-sort" @click="toggleInvSvcSort('code')">
                          Código<span class="sort-ind" aria-hidden="true">{{ invSvcSortInd('code') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="invSvcAriaSort('service_date')">
                        <button type="button" class="th-sort" @click="toggleInvSvcSort('service_date')">
                          Fecha<span class="sort-ind" aria-hidden="true">{{ invSvcSortInd('service_date') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="invSvcAriaSort('service_type')">
                        <button type="button" class="th-sort" @click="toggleInvSvcSort('service_type')">
                          Tipo<span class="sort-ind" aria-hidden="true">{{ invSvcSortInd('service_type') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="invSvcAriaSort('description')">
                        <button type="button" class="th-sort" @click="toggleInvSvcSort('description')">
                          Descripción<span class="sort-ind" aria-hidden="true">{{ invSvcSortInd('description') }}</span>
                        </button>
                      </th>
                      <th class="num" scope="col" :aria-sort="invSvcAriaSort('amount')">
                        <button type="button" class="th-sort th-sort--end" @click="toggleInvSvcSort('amount')">
                          Valor<span class="sort-ind" aria-hidden="true">{{ invSvcSortInd('amount') }}</span>
                        </button>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="s in sortedInvoiceServices" :key="s.id">
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
                <button type="button" class="btn primary btn-compact" @click="onAddPayment">Registrar pago</button>
              </div>
              <p v-else-if="invoice.status === 'aprobada'" class="muted hint-pay">
                Marque la factura como <strong>enviada</strong> para habilitar el registro de pagos.
              </p>
              <p v-else class="muted">Los pagos se registran cuando la factura está <strong>enviada</strong>.</p>

              <div class="table-wrap mt">
                <table class="table">
                  <thead>
                    <tr>
                      <th scope="col" :aria-sort="invPayAriaSort('payment_date')">
                        <button type="button" class="th-sort" @click="toggleInvPaySort('payment_date')">
                          Fecha<span class="sort-ind" aria-hidden="true">{{ invPaySortInd('payment_date') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="invPayAriaSort('method')">
                        <button type="button" class="th-sort" @click="toggleInvPaySort('method')">
                          Método<span class="sort-ind" aria-hidden="true">{{ invPaySortInd('method') }}</span>
                        </button>
                      </th>
                      <th class="num" scope="col" :aria-sort="invPayAriaSort('amount')">
                        <button type="button" class="th-sort th-sort--end" @click="toggleInvPaySort('amount')">
                          Monto<span class="sort-ind" aria-hidden="true">{{ invPaySortInd('amount') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="invPayAriaSort('notes')">
                        <button type="button" class="th-sort" @click="toggleInvPaySort('notes')">
                          Notas<span class="sort-ind" aria-hidden="true">{{ invPaySortInd('notes') }}</span>
                        </button>
                      </th>
                      <th v-if="canDeletePayment" />
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="p in sortedInvoicePayments" :key="p.id">
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
          </template>
        </div>

        <footer v-if="showReviewFooter" class="drawer-approve-footer">
          <button
            type="button"
            class="btn secondary btn-compact"
            :disabled="approveSubmitting"
            @click="emit('close')"
          >
            Rechazar
          </button>
          <button
            type="button"
            class="btn primary btn-compact"
            :disabled="approveSubmitting || !companyEmailValid"
            :title="!companyEmailValid ? 'Agregue correo a la empresa en el directorio' : ''"
            @click="onAprobarYEnviar"
          >
            {{ approveSubmitting ? 'Procesando…' : 'Aprobar y enviar' }}
          </button>
        </footer>
      </aside>
    </div>
  </Teleport>
</template>

<style scoped>
.drawer-root {
  position: fixed;
  inset: 0;
  pointer-events: none;
}

.drawer-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(2, 6, 23, 0.65);
  backdrop-filter: blur(4px);
  pointer-events: auto;
}

.drawer-panel {
  position: absolute;
  top: 0;
  right: 0;
  height: 100%;
  width: min(560px, 100vw);
  max-width: 100%;
  background: #0f172a;
  border-left: 1px solid rgba(148, 163, 184, 0.25);
  box-shadow: -12px 0 40px rgba(0, 0, 0, 0.45);
  display: flex;
  flex-direction: column;
  pointer-events: auto;
  animation: drawer-in 0.22s ease-out;
}

@keyframes drawer-in {
  from {
    transform: translateX(100%);
    opacity: 0.9;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

.drawer-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1rem 0.75rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.2);
  flex-shrink: 0;
}

.drawer-header-text {
  min-width: 0;
}

.drawer-kicker {
  margin: 0 0 0.2rem;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #94a3b8;
}

.drawer-title {
  margin: 0;
  font-size: 1.15rem;
  color: #f8fafc;
  word-break: break-word;
}

.drawer-sub {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
}

.drawer-header-actions {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  flex-shrink: 0;
}

.drawer-close {
  width: 2.25rem;
  height: 2.25rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(148, 163, 184, 0.35);
  border-radius: 10px;
  background: rgba(2, 6, 23, 0.4);
  color: #e2e8f0;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
}

.drawer-close:hover {
  border-color: #38bdf8;
  color: #fff;
}

.drawer-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.65rem 1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.15);
  flex-shrink: 0;
}

.drawer-body {
  flex: 1;
  overflow-y: auto;
  padding: 0.85rem 1rem 1.5rem;
  -webkit-overflow-scrolling: touch;
}

.drawer-approve-footer {
  flex-shrink: 0;
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  align-items: center;
  gap: 0.5rem;
  padding: 0.85rem 1rem;
  border-top: 1px solid rgba(148, 163, 184, 0.25);
  background: rgba(15, 23, 42, 0.95);
  box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.25);
}

.review-hint-card {
  border-left: 4px solid rgba(251, 191, 36, 0.55);
  background: rgba(251, 191, 36, 0.06);
}

.review-hint-text {
  margin: 0;
  line-height: 1.45;
}

h2 {
  margin: 0 0 0.75rem;
  font-size: 1rem;
  color: #e2e8f0;
}

.card {
  padding: 1rem 1.1rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 0.85rem;
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

.actions-bar .send-hint {
  flex: 1 1 100%;
  margin: 0 0 0.15rem;
  line-height: 1.45;
}

.grid-2 {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 0.85rem;
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
  font-size: 0.85rem;
}
.table th,
.table td {
  padding: 0.4rem 0.3rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
  text-align: left;
}
.table th {
  color: #94a3b8;
  font-size: 0.72rem;
  text-transform: uppercase;
}

.mono {
  font-family: ui-monospace, monospace;
  font-size: 0.78rem;
}

.desc {
  max-width: 200px;
  white-space: pre-wrap;
  word-break: break-word;
}

.num {
  text-align: right;
  white-space: nowrap;
}

.pay-form {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 0.65rem;
  align-items: end;
  margin-bottom: 0.85rem;
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
  margin-top: 0.65rem;
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
  margin-bottom: 0.85rem;
}

.muted {
  color: #94a3b8;
}

.pad {
  padding: 0.5rem 0;
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
  text-decoration: none;
  align-items: center;
  font-size: 0.88rem;
}

.btn-compact {
  padding: 0.4rem 0.7rem;
  font-size: 0.82rem;
}

.btn.primary {
  background: linear-gradient(90deg, #2563eb, #7c3aed);
  border: none;
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
  margin-bottom: 0.65rem;
  padding-bottom: 0.55rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.2);
}
.preview-issuer {
  margin: 0;
  font-size: 1rem;
  font-weight: 700;
  color: #f1f5f9;
}
.tiny {
  font-size: 0.78rem;
  margin: 0.12rem 0;
}
.preview-badge {
  flex-shrink: 0;
  font-size: 0.68rem;
  font-weight: 700;
  text-transform: uppercase;
  padding: 0.22rem 0.5rem;
  border-radius: 6px;
  background: rgba(251, 191, 36, 0.15);
  border: 1px solid rgba(251, 191, 36, 0.45);
  color: #fde68a;
}
.preview-title {
  margin: 0 0 0.3rem;
  font-size: 1.05rem;
  color: #f8fafc;
}
.preview-meta {
  margin: 0 0 0.85rem;
  font-size: 0.82rem;
}
.preview-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 0.85rem;
  margin-bottom: 0.85rem;
}
.preview-grid h3 {
  margin: 0 0 0.3rem;
  font-size: 0.8rem;
  color: #94a3b8;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.preview-table-wrap {
  margin-top: 0.4rem;
}
.preview-table {
  font-size: 0.78rem;
}
.preview-hint {
  margin: 0.65rem 0 0;
  padding: 0.55rem 0.65rem;
  border-radius: 10px;
  background: rgba(56, 189, 248, 0.08);
  border: 1px solid rgba(56, 189, 248, 0.22);
  color: #bae6fd;
  font-size: 0.82rem;
}

.small {
  font-size: 0.82rem;
  line-height: 1.45;
}
</style>
