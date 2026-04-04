<script setup>
import { ref, computed } from 'vue'
import { useClientSortedRows } from '@/composables/useClientSortedRows.js'
import { RouterLink } from 'vue-router'
import { publicApi, publicApiBlob } from '@/services/api.js'
import { openPdfBlobInNewTab, triggerPdfDownload } from '@/utils/pdfBlob.js'

/** @type {import('vue').Ref<'consult' | 'list' | 'result'>} */
const viewState = ref('consult')
/** Texto único: código de factura (FAC-…) o NIT de empresa */
const query = ref('')
const payload = ref(null)
/** @type {import('vue').Ref<null | { company: object, invoices: object[] }>} */
const listResult = ref(null)
const loading = ref(false)
const loadingPdf = ref(false)
const errorMessage = ref('')
const fieldErrors = ref({})

const listInvoicesSource = computed(() => listResult.value?.invoices || [])
const {
  sortedRows: sortedListInvoices,
  toggleSort: toggleListInvSort,
  sortIndicator: listInvSortInd,
  ariaSort: listInvAriaSort,
} = useClientSortedRows(
  listInvoicesSource,
  {
    code: (inv) => inv.code || '',
    period_label: (inv) => inv.period_label || '',
    status: (inv) => inv.status || inv.status_label || '',
    total: (inv) => Number(inv.total) || 0,
    balance: (inv) => Number(inv.balance) || 0,
  },
  { initialKey: 'code', initialDir: 'asc' }
)

const publicServicesSource = computed(() => payload.value?.services || [])
const {
  sortedRows: sortedPublicServices,
  toggleSort: togglePubSvcSort,
  sortIndicator: pubSvcSortInd,
  ariaSort: pubSvcAriaSort,
} = useClientSortedRows(
  publicServicesSource,
  {
    service_date: (r) => r.service_date || '',
    code: (r) => r.code || '',
    technician_name: (r) => r.technician_name || '',
    description: (r) => r.description || '',
    amount: (r) => Number(r.amount) || 0,
  },
  { initialKey: 'service_date', initialDir: 'asc' }
)

const publicProductsSource = computed(() => payload.value?.products || [])
const {
  sortedRows: sortedPublicProducts,
  toggleSort: togglePubProdSort,
  sortIndicator: pubProdSortInd,
  ariaSort: pubProdAriaSort,
} = useClientSortedRows(
  publicProductsSource,
  {
    name: (pr) => pr.name || '',
    quantity: (pr) => Number(pr.quantity) || 0,
    price: (pr) => Number(pr.price) || 0,
  },
  { initialKey: 'name', initialDir: 'asc' }
)

const publicPaymentsSource = computed(() => payload.value?.payments || [])
const {
  sortedRows: sortedPublicPayments,
  toggleSort: togglePubPaySort,
  sortIndicator: pubPaySortInd,
  ariaSort: pubPayAriaSort,
} = useClientSortedRows(
  publicPaymentsSource,
  {
    payment_date: (p) => p.payment_date || '',
    amount: (p) => Number(p.amount) || 0,
    method: (p) => p.method || '',
  },
  { initialKey: 'payment_date', initialDir: 'asc' }
)

const taxEstimate = computed(() => {
  const sub = Number(payload.value?.financial?.subtotal)
  const tot = Number(payload.value?.financial?.total)
  if (Number.isNaN(sub) || Number.isNaN(tot)) return null
  const d = tot - sub
  return d > 0 ? d : null
})

function pillClass() {
  const label = (payload.value?.invoice?.status_label || '').toLowerCase()
  if (label.includes('pagad')) return 'pill pill--ok'
  if (label.includes('parcial')) return 'pill pill--warn'
  return 'pill pill--neutral'
}

function pillClassForStatus(status) {
  if (status === 'pagada') return 'pill pill--ok'
  if (status === 'parcialmente_pagada') return 'pill pill--warn'
  return 'pill pill--neutral'
}

function formatMoney(value) {
  const n = Number(value)
  if (Number.isNaN(n)) return value
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(n)
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso + (iso.length === 10 ? 'T12:00:00' : ''))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}

function validateForm() {
  fieldErrors.value = {}
  errorMessage.value = ''
  const q = query.value.trim()
  if (!q) {
    fieldErrors.value = { query: ['Indique el código de factura o el NIT de la empresa.'] }
    return false
  }
  return true
}

async function onConsult() {
  if (!validateForm()) return
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await publicApi('/public/invoices/consult', {
      method: 'POST',
      body: JSON.stringify({ query: query.value.trim() }),
    })

    if (res.kind === 'invoice_list') {
      listResult.value = { company: res.company, invoices: res.invoices || [] }
      payload.value = null
      viewState.value = 'list'
    } else {
      listResult.value = null
      payload.value = res
      viewState.value = 'result'
    }
  } catch (e) {
    payload.value = null
    if (e.status === 404 || e.status === 403) {
      errorMessage.value = e.message || 'No se pudo completar la consulta.'
    } else if (e.status === 422 && e.data?.errors) {
      const first = Object.values(e.data.errors).flat()[0]
      errorMessage.value = first || e.data.message || 'Revisa los datos ingresados.'
    } else {
      errorMessage.value =
        'Ocurrió un error en el sistema. Intenta de nuevo en unos minutos.'
    }
  } finally {
    loading.value = false
  }
}

function nuevaConsulta() {
  viewState.value = 'consult'
  payload.value = null
  listResult.value = null
  errorMessage.value = ''
  fieldErrors.value = {}
}

async function verDetalleFactura(code) {
  const c = String(code || '').trim()
  if (!c) return
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await publicApi('/public/invoices/consult', {
      method: 'POST',
      body: JSON.stringify({ query: c }),
    })
    if (res.kind === 'invoice_list') {
      errorMessage.value = 'No se pudo cargar el detalle. Intente de nuevo.'
      return
    }
    listResult.value = null
    payload.value = res
    viewState.value = 'result'
  } catch (e) {
    if (e.status === 404 || e.status === 403) {
      errorMessage.value = e.message || 'No se pudo cargar la factura.'
    } else {
      errorMessage.value = e.message || 'Ocurrió un error. Intente más tarde.'
    }
  } finally {
    loading.value = false
  }
}

function imprimir() {
  window.print()
}

async function descargarPdf() {
  const invoiceCode = payload.value?.invoice?.code
  if (!invoiceCode) return
  loadingPdf.value = true
  errorMessage.value = ''
  try {
    const { blob, filename } = await publicApiBlob('/public/invoices/pdf', {
      method: 'POST',
      body: JSON.stringify({ code: invoiceCode }),
    })
    triggerPdfDownload(blob, filename)
  } catch (e) {
    errorMessage.value = e.message || 'No se pudo generar el PDF.'
  } finally {
    loadingPdf.value = false
  }
}

async function verPdfEnPestaña() {
  const invoiceCode = payload.value?.invoice?.code
  if (!invoiceCode) return
  loadingPdf.value = true
  errorMessage.value = ''
  try {
    const { blob } = await publicApiBlob('/public/invoices/pdf', {
      method: 'POST',
      body: JSON.stringify({ code: invoiceCode }),
    })
    openPdfBlobInNewTab(blob)
  } catch (e) {
    errorMessage.value = e.message || 'No se pudo abrir el PDF.'
  } finally {
    loadingPdf.value = false
  }
}
</script>

<template>
  <div class="page">
    <div class="bg no-print" aria-hidden="true" />

    <div class="stack">
      <section class="search-card" aria-labelledby="titulo-consulta">
        <h1 id="titulo-consulta" class="search-title">Consultar Factura</h1>
        <p class="search-sub">
          Escriba el <strong>código de la factura</strong> (ej. FAC-…) para ver el detalle, abrir o descargar el PDF, o el
          <strong>NIT de su empresa</strong> (con o sin puntos o guiones) para listar todas las facturas disponibles. Sin
          inicio de sesión.
        </p>
        <p class="search-hint muted">
          Solo aparecen facturas ya <strong>aprobadas o enviadas</strong> (no borradores). Si acaba de generarse la
          factura, el administrador debe aprobarla en el panel antes de que sea visible aquí.
        </p>
        <div class="search-row">
          <label class="search-field search-field--grow">
            <span class="search-label">Código de factura o NIT</span>
            <input
              v-model="query"
              type="text"
              autocomplete="off"
              placeholder="FAC-260318-SYF o 900.111.222-9"
              :class="{ 'input-invalid': fieldErrors.query }"
              @keyup.enter="onConsult"
            />
            <span v-if="fieldErrors.query" class="field-err">{{ fieldErrors.query[0] }}</span>
          </label>
          <button type="button" class="btn-search" :disabled="loading" @click="onConsult">
            {{ loading ? 'Consultando…' : 'Consultar' }}
          </button>
        </div>
        <p v-if="errorMessage && viewState === 'consult'" class="alert" role="alert">{{ errorMessage }}</p>
      </section>

      <section v-if="viewState === 'list' && listResult" class="detail-card list-card">
        <h2 class="list-title">Facturas de la empresa</h2>
        <p class="list-meta muted">
          <strong>{{ listResult.company?.nombre || '—' }}</strong>
          <span v-if="listResult.company?.nit"> · NIT {{ listResult.company.nit }}</span>
        </p>
        <p v-if="errorMessage" class="alert" role="alert">{{ errorMessage }}</p>
        <p v-if="!sortedListInvoices.length" class="muted list-empty">No hay facturas publicadas para este NIT.</p>
        <div v-else class="table-wrap list-table-wrap">
          <table class="data-table">
            <thead>
              <tr>
                <th scope="col" :aria-sort="listInvAriaSort('code')">
                  <button type="button" class="th-sort" @click="toggleListInvSort('code')">
                    Código<span class="sort-ind" aria-hidden="true">{{ listInvSortInd('code') }}</span>
                  </button>
                </th>
                <th scope="col" :aria-sort="listInvAriaSort('period_label')">
                  <button type="button" class="th-sort" @click="toggleListInvSort('period_label')">
                    Periodo<span class="sort-ind" aria-hidden="true">{{ listInvSortInd('period_label') }}</span>
                  </button>
                </th>
                <th scope="col" :aria-sort="listInvAriaSort('status')">
                  <button type="button" class="th-sort" @click="toggleListInvSort('status')">
                    Estado<span class="sort-ind" aria-hidden="true">{{ listInvSortInd('status') }}</span>
                  </button>
                </th>
                <th class="num" scope="col" :aria-sort="listInvAriaSort('total')">
                  <button type="button" class="th-sort th-sort--end" @click="toggleListInvSort('total')">
                    Total<span class="sort-ind" aria-hidden="true">{{ listInvSortInd('total') }}</span>
                  </button>
                </th>
                <th class="num" scope="col" :aria-sort="listInvAriaSort('balance')">
                  <button type="button" class="th-sort th-sort--end" @click="toggleListInvSort('balance')">
                    Saldo<span class="sort-ind" aria-hidden="true">{{ listInvSortInd('balance') }}</span>
                  </button>
                </th>
                <th class="list-actions-col" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="inv in sortedListInvoices" :key="inv.id">
                <td class="mono">{{ inv.code }}</td>
                <td>{{ inv.period_label }}</td>
                <td>
                  <span class="pill" :class="pillClassForStatus(inv.status)">{{ inv.status_label }}</span>
                </td>
                <td class="num">{{ formatMoney(inv.total) }}</td>
                <td class="num">{{ formatMoney(inv.balance) }}</td>
                <td class="list-actions-col">
                  <button type="button" class="link-btn" :disabled="loading" @click="verDetalleFactura(inv.code)">
                    Ver detalle
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="actions no-print">
          <button type="button" class="btn ghost" @click="nuevaConsulta">Nueva consulta</button>
        </div>
      </section>

      <section v-if="viewState === 'result'" id="invoice-print" class="detail-card detail-card--invoice">
        <div class="brand-bar">
          <span class="brand-icon" aria-hidden="true">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M3 3v18h18M7 16l4-4 4 4 6-6" />
            </svg>
          </span>
          <span class="brand-text">HBM</span>
        </div>

        <div class="invoice-head">
          <div class="invoice-head-main">
            <h2 class="invoice-title">
              Detalle de Factura <span class="invoice-code">#{{ payload?.invoice?.number }}</span>
            </h2>
            <p class="invoice-period">
              Periodo: <strong>{{ payload?.invoice?.period_label || '—' }}</strong>
            </p>
            <div class="pill-wrap">
              <span class="pill" :class="pillClass()">
                <svg class="pill-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path d="M20 6L9 17l-5-5" />
                </svg>
                Estado: {{ (payload?.invoice?.status_label || '—').toUpperCase() }}
              </span>
            </div>
          </div>
          <div class="invoice-head-actions no-print">
            <button type="button" class="btn-outline" @click="imprimir">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z" />
              </svg>
              Imprimir
            </button>
            <button type="button" class="btn-white" :disabled="loadingPdf" @click="verPdfEnPestaña">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                <circle cx="12" cy="12" r="3" />
              </svg>
              {{ loadingPdf ? 'Generando…' : 'Ver PDF' }}
            </button>
            <button type="button" class="btn-white" :disabled="loadingPdf" @click="descargarPdf">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" aria-hidden="true">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3" />
              </svg>
              {{ loadingPdf ? 'Generando…' : 'Descargar PDF' }}
            </button>
          </div>
        </div>

        <div class="panels">
          <div class="panel">
            <h3 class="panel-kicker">Datos del cliente</h3>
            <dl class="panel-dl">
              <div>
                <dt>Empresa</dt>
                <dd>{{ payload?.company?.nombre || '—' }}</dd>
              </div>
              <div>
                <dt>NIT</dt>
                <dd>{{ payload?.company?.nit || '—' }}</dd>
              </div>
              <div>
                <dt>Dirección / teléfono</dt>
                <dd class="muted-dd">—</dd>
              </div>
            </dl>
          </div>
          <div class="panel panel--summary">
            <h3 class="panel-kicker">Resumen general</h3>
            <p class="summary-label">Total general</p>
            <p class="summary-amount">{{ formatMoney(payload?.financial?.total) }}</p>
          </div>
        </div>

        <div class="table-block">
          <div class="verify-strip">
            <svg class="shield" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
              <path
                d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"
              />
            </svg>
            Factura verificada por HBM
          </div>
          <div class="table-wrap">
            <table class="data-table data-table--services">
              <thead>
                <tr>
                  <th scope="col" :aria-sort="pubSvcAriaSort('service_date')">
                    <button type="button" class="th-sort" @click="togglePubSvcSort('service_date')">
                      Fecha<span class="sort-ind" aria-hidden="true">{{ pubSvcSortInd('service_date') }}</span>
                    </button>
                  </th>
                  <th scope="col" :aria-sort="pubSvcAriaSort('code')">
                    <button type="button" class="th-sort" @click="togglePubSvcSort('code')">
                      Código de servicio<span class="sort-ind" aria-hidden="true">{{ pubSvcSortInd('code') }}</span>
                    </button>
                  </th>
                  <th scope="col" :aria-sort="pubSvcAriaSort('technician_name')">
                    <button type="button" class="th-sort" @click="togglePubSvcSort('technician_name')">
                      Técnico (empleado)<span class="sort-ind" aria-hidden="true">{{ pubSvcSortInd('technician_name') }}</span>
                    </button>
                  </th>
                  <th scope="col" :aria-sort="pubSvcAriaSort('description')">
                    <button type="button" class="th-sort" @click="togglePubSvcSort('description')">
                      Descripción completa del servicio<span class="sort-ind" aria-hidden="true">{{ pubSvcSortInd('description') }}</span>
                    </button>
                  </th>
                  <th class="center">Tiempo invertido (horas)</th>
                  <th class="num" scope="col" :aria-sort="pubSvcAriaSort('amount')">
                    <button type="button" class="th-sort th-sort--end" @click="togglePubSvcSort('amount')">
                      Valor<span class="sort-ind" aria-hidden="true">{{ pubSvcSortInd('amount') }}</span>
                    </button>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(row, i) in sortedPublicServices" :key="i">
                  <td>{{ formatDate(row.service_date) }}</td>
                  <td class="mono">{{ row.code }}</td>
                  <td>{{ row.technician_name || '—' }}</td>
                  <td class="desc">{{ row.description }}</td>
                  <td class="center muted-td">—</td>
                  <td class="num strong-td">{{ formatMoney(row.amount) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div class="totals-row">
            <div class="verify-strip verify-strip--corner">
              <svg class="shield" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path
                  d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"
                />
              </svg>
              Factura verificada por HBM
            </div>
            <dl class="totals-box">
              <div>
                <dt>Subtotal</dt>
                <dd>{{ formatMoney(payload?.financial?.subtotal) }}</dd>
              </div>
              <div v-if="taxEstimate != null">
                <dt>Impuestos</dt>
                <dd>{{ formatMoney(taxEstimate) }}</dd>
              </div>
              <div class="totals-box-total">
                <dt>Total general</dt>
                <dd>{{ formatMoney(payload?.financial?.total) }}</dd>
              </div>
            </dl>
          </div>
        </div>

        <section v-if="(payload?.products || []).length" class="block-extra">
          <h3 class="panel-kicker">Productos</h3>
          <div class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th scope="col" :aria-sort="pubProdAriaSort('name')">
                    <button type="button" class="th-sort" @click="togglePubProdSort('name')">
                      Nombre<span class="sort-ind" aria-hidden="true">{{ pubProdSortInd('name') }}</span>
                    </button>
                  </th>
                  <th class="num" scope="col" :aria-sort="pubProdAriaSort('quantity')">
                    <button type="button" class="th-sort th-sort--end" @click="togglePubProdSort('quantity')">
                      Cantidad<span class="sort-ind" aria-hidden="true">{{ pubProdSortInd('quantity') }}</span>
                    </button>
                  </th>
                  <th class="num" scope="col" :aria-sort="pubProdAriaSort('price')">
                    <button type="button" class="th-sort th-sort--end" @click="togglePubProdSort('price')">
                      Precio<span class="sort-ind" aria-hidden="true">{{ pubProdSortInd('price') }}</span>
                    </button>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(pr, i) in sortedPublicProducts" :key="i">
                  <td>{{ pr.name }}</td>
                  <td class="num">{{ pr.quantity }}</td>
                  <td class="num">{{ formatMoney(pr.price) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="block-extra">
          <h3 class="panel-kicker">Historial de pagos</h3>
          <div v-if="!sortedPublicPayments.length" class="muted">Sin pagos registrados.</div>
          <div v-else class="table-wrap">
            <table class="data-table">
              <thead>
                <tr>
                  <th scope="col" :aria-sort="pubPayAriaSort('payment_date')">
                    <button type="button" class="th-sort" @click="togglePubPaySort('payment_date')">
                      Fecha<span class="sort-ind" aria-hidden="true">{{ pubPaySortInd('payment_date') }}</span>
                    </button>
                  </th>
                  <th class="num" scope="col" :aria-sort="pubPayAriaSort('amount')">
                    <button type="button" class="th-sort th-sort--end" @click="togglePubPaySort('amount')">
                      Monto<span class="sort-ind" aria-hidden="true">{{ pubPaySortInd('amount') }}</span>
                    </button>
                  </th>
                  <th scope="col" :aria-sort="pubPayAriaSort('method')">
                    <button type="button" class="th-sort" @click="togglePubPaySort('method')">
                      Método<span class="sort-ind" aria-hidden="true">{{ pubPaySortInd('method') }}</span>
                    </button>
                  </th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="(p, i) in sortedPublicPayments" :key="i">
                  <td>{{ formatDate(p.payment_date) }}</td>
                  <td class="num">{{ formatMoney(p.amount) }}</td>
                  <td>{{ p.method }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="notes-box">
          <h3 class="panel-kicker">Notas y aclaraciones</h3>
          <p class="notes-empty">—</p>
        </section>

        <footer class="invoice-foot">
          <span>HBM · Contacto: según datos de tu contrato o factura.</span>
        </footer>

        <p v-if="errorMessage" class="alert no-print alert--mt" role="alert">{{ errorMessage }}</p>

        <div class="actions no-print">
          <button type="button" class="btn ghost" @click="nuevaConsulta">Nueva consulta</button>
        </div>
      </section>

      <p class="foot no-print">
        <RouterLink to="/login" class="link">← Volver al acceso de personal interno</RouterLink>
      </p>
    </div>
  </div>
</template>

<style scoped>
.page {
  min-height: 100vh;
  position: relative;
  color: #e8f1ff;
  padding: 2rem 1rem 3rem;
}

.bg {
  position: fixed;
  inset: 0;
  background: linear-gradient(165deg, #0a0e18 0%, #0f172a 40%, #0c1222 100%);
  z-index: 0;
}

.stack {
  position: relative;
  z-index: 1;
  margin: 0 auto;
  max-width: 1100px;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.search-card {
  border-radius: 12px;
  padding: 1.25rem 1.35rem 1.35rem;
  background: rgba(19, 26, 43, 0.92);
  border: 1px solid rgba(59, 130, 246, 0.28);
  box-shadow:
    0 0 0 1px rgba(59, 130, 246, 0.08),
    0 12px 40px rgba(0, 0, 0, 0.35);
}

.search-title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 700;
  color: #fff;
  letter-spacing: -0.02em;
}

.search-sub {
  margin: 0.45rem 0 0;
  font-size: 0.8125rem;
  line-height: 1.5;
  color: #94a3b8;
}

.search-sub strong {
  color: #cbd5e1;
  font-weight: 600;
}

.search-hint {
  margin: 0.65rem 0 0;
  font-size: 0.75rem;
  line-height: 1.45;
  max-width: 40rem;
}

.search-row {
  margin-top: 1.1rem;
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  align-items: stretch;
}

@media (min-width: 900px) {
  .search-row {
    flex-direction: row;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: 1rem;
  }
  .search-field {
    flex: 1 1 200px;
    min-width: 0;
  }
  .search-field--grow {
    flex: 2 1 280px;
  }
  .btn-search {
    flex: 0 0 auto;
    align-self: flex-end;
  }
}

.list-card {
  padding: 1.35rem 1.25rem 1.5rem;
}

.list-title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 700;
  color: #fff;
}

.list-meta {
  margin: 0.5rem 0 1rem;
  font-size: 0.9rem;
}

.list-meta strong {
  color: #e2e8f0;
}

.list-empty {
  margin: 0 0 1rem;
}

.list-table-wrap {
  margin-bottom: 0.5rem;
}

.list-actions-col {
  white-space: nowrap;
  width: 1%;
}

.link-btn {
  background: none;
  border: none;
  padding: 0;
  font: inherit;
  font-size: 0.8rem;
  font-weight: 600;
  color: #7dd3fc;
  cursor: pointer;
  text-decoration: underline;
  text-decoration-color: rgba(125, 211, 252, 0.45);
}

.link-btn:hover:not(:disabled) {
  color: #bae6fd;
}

.link-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.search-label {
  display: block;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #fff;
  margin-bottom: 0.35rem;
}

.search-field input {
  width: 100%;
  border-radius: 8px;
  border: 1px solid rgba(100, 116, 139, 0.55);
  background: rgba(8, 12, 24, 0.9);
  color: #f1f5f9;
  padding: 0.55rem 0.75rem;
  font: inherit;
  font-size: 0.9rem;
}

.search-field input:focus {
  outline: none;
  border-color: rgba(59, 130, 246, 0.65);
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
}

.search-field input.input-invalid {
  border-color: rgba(248, 113, 113, 0.65);
}

.field-err {
  display: block;
  font-size: 0.75rem;
  color: #fca5a5;
  margin-top: 0.25rem;
}

.btn-search {
  border: none;
  border-radius: 8px;
  padding: 0.6rem 1.35rem;
  font: inherit;
  font-weight: 600;
  font-size: 0.9rem;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
  box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
  white-space: nowrap;
}

.btn-search:hover:not(:disabled) {
  filter: brightness(1.06);
}

.btn-search:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.alert {
  margin: 1rem 0 0;
  padding: 0.65rem 0.75rem;
  border-radius: 8px;
  background: rgba(127, 29, 29, 0.35);
  border: 1px solid rgba(248, 113, 113, 0.35);
  color: #fecaca;
  font-size: 0.875rem;
}

.alert--mt {
  margin-top: 1rem;
}

.detail-card {
  border-radius: 12px;
  padding: 1.5rem 1.35rem 1.75rem;
  background: rgba(17, 24, 39, 0.94);
  border: 1px solid rgba(59, 130, 246, 0.35);
  box-shadow:
    0 0 0 1px rgba(59, 130, 246, 0.12),
    0 0 48px rgba(37, 99, 235, 0.12),
    0 20px 50px rgba(0, 0, 0, 0.4);
}

.brand-bar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.45rem;
  margin-bottom: 1.25rem;
  font-weight: 700;
  font-size: 0.95rem;
  color: #e2e8f0;
}

.brand-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: 8px;
  background: rgba(59, 130, 246, 0.2);
  border: 1px solid rgba(59, 130, 246, 0.4);
  color: #93c5fd;
}

.invoice-head {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-bottom: 1.25rem;
  border-bottom: 1px solid rgba(71, 85, 105, 0.45);
}

@media (min-width: 768px) {
  .invoice-head {
    flex-direction: row;
    align-items: flex-start;
    justify-content: space-between;
  }
}

.invoice-title {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 700;
  color: #fff;
}

.invoice-code {
  color: #7dd3fc;
  font-weight: 700;
}

.invoice-period {
  margin: 0.35rem 0 0;
  font-size: 0.875rem;
  color: #94a3b8;
}

.invoice-period strong {
  color: #e2e8f0;
  font-weight: 600;
}

.pill-wrap {
  margin-top: 0.65rem;
}

.pill {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  border: 1px solid transparent;
}

.pill-check {
  width: 14px;
  height: 14px;
  flex-shrink: 0;
}

.pill--ok {
  background: rgba(16, 185, 129, 0.15);
  border-color: rgba(52, 211, 153, 0.45);
  color: #6ee7b7;
}

.pill--warn {
  background: rgba(245, 158, 11, 0.12);
  border-color: rgba(251, 191, 36, 0.4);
  color: #fcd34d;
}

.pill--neutral {
  background: rgba(56, 189, 248, 0.12);
  border-color: rgba(56, 189, 248, 0.35);
  color: #7dd3fc;
}

.invoice-head-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.btn-outline {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.45rem 0.85rem;
  border-radius: 8px;
  border: 1px solid rgba(100, 116, 139, 0.55);
  background: rgba(30, 41, 59, 0.5);
  color: #e2e8f0;
  font: inherit;
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
}

.btn-outline:hover {
  border-color: rgba(148, 163, 184, 0.65);
  background: rgba(51, 65, 85, 0.55);
}

.btn-white {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.45rem 0.85rem;
  border-radius: 8px;
  border: none;
  background: #fff;
  color: #0f172a;
  font: inherit;
  font-size: 0.8rem;
  font-weight: 600;
  cursor: pointer;
}

.btn-white:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.btn-white:hover:not(:disabled) {
  background: #f1f5f9;
}

.panels {
  display: grid;
  gap: 1rem;
  margin-top: 1.25rem;
}

@media (min-width: 768px) {
  .panels {
    grid-template-columns: 1fr 1fr;
  }
}

.panel {
  border-radius: 10px;
  border: 1px solid rgba(71, 85, 105, 0.45);
  background: rgba(10, 15, 28, 0.55);
  padding: 1rem 1.1rem;
}

.panel--summary {
  display: flex;
  flex-direction: column;
  justify-content: center;
}

.panel-kicker {
  margin: 0;
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.1em;
  color: #94a3b8;
}

.panel-dl {
  margin: 0.75rem 0 0;
}

.panel-dl div {
  margin-bottom: 0.65rem;
}

.panel-dl dt {
  font-size: 0.7rem;
  color: #64748b;
  margin: 0 0 0.15rem;
}

.panel-dl dd {
  margin: 0;
  font-size: 0.9rem;
  font-weight: 600;
  color: #f1f5f9;
}

.muted-dd {
  color: #64748b !important;
  font-weight: 400 !important;
}

.summary-label {
  margin: 0.75rem 0 0;
  font-size: 0.75rem;
  color: #94a3b8;
}

.summary-amount {
  margin: 0.25rem 0 0;
  font-size: 1.65rem;
  font-weight: 800;
  color: #fff;
  letter-spacing: -0.02em;
}

.table-block {
  margin-top: 1.35rem;
}

.verify-strip {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  font-size: 0.65rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #60a5fa;
  margin-bottom: 0.65rem;
}

.verify-strip--corner {
  margin-bottom: 0;
}

.shield {
  width: 14px;
  height: 14px;
  color: #3b82f6;
  flex-shrink: 0;
}

.totals-row {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 0.75rem;
  margin-top: 0.75rem;
}

@media (min-width: 640px) {
  .totals-row {
    flex-direction: row;
    justify-content: space-between;
    align-items: flex-end;
  }
}

.totals-box {
  margin: 0;
  min-width: 200px;
  padding: 0.65rem 0.85rem;
  border-radius: 8px;
  border: 1px solid rgba(71, 85, 105, 0.45);
  background: rgba(8, 12, 24, 0.65);
}

.totals-box div {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.25rem 0;
  font-size: 0.85rem;
}

.totals-box dt {
  margin: 0;
  color: #94a3b8;
}

.totals-box dd {
  margin: 0;
  font-weight: 600;
  color: #e2e8f0;
}

.totals-box-total {
  margin-top: 0.35rem;
  padding-top: 0.45rem !important;
  border-top: 1px solid rgba(71, 85, 105, 0.5);
  font-weight: 700;
}

.totals-box-total dt,
.totals-box-total dd {
  color: #fff !important;
  font-size: 0.95rem;
}

.table-wrap {
  overflow-x: auto;
  border-radius: 10px;
  border: 1px solid rgba(71, 85, 105, 0.45);
}

.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8rem;
}

.data-table th,
.data-table td {
  padding: 0.55rem 0.6rem;
  text-align: left;
  border-bottom: 1px solid rgba(71, 85, 105, 0.35);
}

.data-table th {
  background: rgba(30, 41, 59, 0.75);
  color: #cbd5e1;
  font-weight: 600;
  font-size: 0.62rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  white-space: nowrap;
}

.data-table--services .desc {
  max-width: 280px;
  color: #cbd5e1;
  line-height: 1.35;
}

.data-table .mono {
  font-family: ui-monospace, monospace;
  font-size: 0.75rem;
}

.data-table .center {
  text-align: center;
}

.data-table .num {
  text-align: right;
  white-space: nowrap;
}

.muted-td {
  color: #64748b !important;
}

.strong-td {
  font-weight: 600;
  color: #f8fafc !important;
}

.block-extra {
  margin-top: 1.35rem;
}

.notes-box {
  margin-top: 1.35rem;
  border-radius: 10px;
  border: 1px dashed rgba(71, 85, 105, 0.5);
  background: rgba(8, 12, 24, 0.4);
  padding: 1rem 1.1rem;
  min-height: 72px;
}

.notes-empty {
  margin: 0.5rem 0 0;
  color: #475569;
  font-size: 0.9rem;
}

.invoice-foot {
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px solid rgba(71, 85, 105, 0.35);
  font-size: 0.75rem;
  color: #64748b;
}

.btn {
  padding: 0.55rem 1rem;
  border-radius: 8px;
  border: none;
  font-weight: 600;
  font: inherit;
  cursor: pointer;
}

.btn.ghost {
  background: rgba(51, 65, 85, 0.55);
  color: #e2e8f0;
  border: 1px solid rgba(148, 163, 184, 0.25);
}

.muted {
  color: #94a3b8;
  font-size: 0.88rem;
  margin: 0 0 0.75rem;
  line-height: 1.45;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  margin-top: 1rem;
}

.foot {
  margin: 0;
  text-align: center;
  padding-top: 0.5rem;
}

.link {
  color: #7dd3fc;
  text-decoration: none;
  font-size: 0.9rem;
  font-weight: 600;
}

.link:hover {
  text-decoration: underline;
}
</style>

<style>
@media print {
  .no-print {
    display: none !important;
  }
  body {
    background: #fff !important;
  }
  .page {
    padding: 0 !important;
    color: #111 !important;
  }
  .bg {
    display: none !important;
  }
  .search-card {
    break-inside: avoid;
    border: 1px solid #ccc !important;
    box-shadow: none !important;
    background: #fff !important;
    color: #111 !important;
  }
  .search-title,
  .search-sub,
  .search-label {
    color: #111 !important;
  }
  .detail-card {
    break-inside: avoid;
    border: 1px solid #ccc !important;
    box-shadow: none !important;
    background: #fff !important;
    color: #111 !important;
  }
  .invoice-title,
  .summary-amount,
  .panel-dl dd {
    color: #111 !important;
  }
  .invoice-code {
    color: #0369a1 !important;
  }
  .data-table th {
    background: #eee !important;
    color: #111 !important;
  }
  .data-table th,
  .data-table td {
    border-color: #ddd !important;
    color: #111 !important;
  }
}
</style>
