import { api, apiBaseUrl, getToken } from './api'

/**
 * @param {Record<string, string|number>} [params]
 * @param {string} [params.company_kind] `registered` — solo facturas ligadas a empresa en directorio
 */
export function fetchAdminInvoices(params = {}) {
  const qs = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v))
  })
  const s = qs.toString()
  return api(`/admin/invoices${s ? `?${s}` : ''}`)
}

export function fetchAdminInvoice(id) {
  return api(`/admin/invoices/${id}`).then((r) => r.data)
}

/**
 * @param {{
 *   company_id: number|string,
 *   period_year: number,
 *   period_month: number,
 *   invoice_id?: number|string
 * }} q
 */
export function fetchAvailableServicesForInvoice(q) {
  const qs = new URLSearchParams({
    company_id: String(q.company_id),
    period_year: String(q.period_year),
    period_month: String(q.period_month),
  })
  if (q.invoice_id != null && q.invoice_id !== '') qs.set('invoice_id', String(q.invoice_id))
  return api(`/admin/invoices/available-services?${qs}`).then((r) => r.data)
}

/**
 * @param {{
 *   company_id: number|string,
 *   period_year: number,
 *   period_month: number,
 *   service_ids: number[]
 * }} payload
 */
export function createInvoice(payload) {
  return api('/admin/invoices', {
    method: 'POST',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

/**
 * @param {number|string} id
 * @param {{
 *   company_id: number|string,
 *   period_year: number,
 *   period_month: number,
 *   service_ids: number[]
 * }} payload
 */
export function updateInvoice(id, payload) {
  return api(`/admin/invoices/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

/** Solo borrador o aprobada sin pagos. */
export function deleteAdminInvoice(id) {
  return api(`/admin/invoices/${id}`, {
    method: 'DELETE',
  })
}

export function patchInvoiceStatus(id, status) {
  return api(`/admin/invoices/${id}/status`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  }).then((r) => ({
    ...r.data,
    public_verification_code: r.public_verification_code,
    public_verification_notice: r.public_verification_notice,
  }))
}

/** Nuevo código de verificación para consulta pública del cliente (invalida el anterior). */
export function regenerateInvoicePublicAccess(id) {
  return api(`/admin/invoices/${id}/public-access-token`, {
    method: 'POST',
  }).then((r) => ({
    ...r.data,
    public_verification_code: r.public_verification_code,
    public_verification_notice: r.public_verification_notice,
  }))
}

/**
 * @param {{ amount: number|string, payment_date: string, method: string, notes?: string }} payload
 */
export function addInvoicePayment(id, payload) {
  return api(`/admin/invoices/${id}/payments`, {
    method: 'POST',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

export function deleteInvoicePayment(invoiceId, paymentId) {
  return api(`/admin/invoices/${invoiceId}/payments/${paymentId}`, {
    method: 'DELETE',
  }).then((r) => r.data)
}

/**
 * @param {number|string} id
 * @param {{ preview?: boolean }} [opts] preview=true → borrador (marca de agua); oficial sin query.
 * @returns {Promise<Blob>}
 */
/** Envía el PDF oficial al correo de la empresa (ficha en directorio). Solo factura no borrador. */
export function sendInvoiceEmailToCompany(id) {
  return api(`/admin/invoices/${id}/send-email`, {
    method: 'POST',
    body: JSON.stringify({}),
  })
}

export async function downloadInvoicePdfBlob(id, opts = {}) {
  const preview = Boolean(opts.preview)
  const q = preview ? '?preview=1' : ''
  const res = await fetch(`${apiBaseUrl()}/api/admin/invoices/${id}/pdf${q}`, {
    headers: {
      Authorization: `Bearer ${getToken()}`,
      Accept: 'application/pdf',
    },
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || `Error ${res.status}`)
  }
  const ab = await res.arrayBuffer()
  const mime = (res.headers.get('Content-Type') || 'application/pdf').split(';')[0].trim().toLowerCase()
  return new Blob([ab], { type: mime || 'application/pdf' })
}

/**
 * Exportación CSV (Excel). `path` ej. `/admin/export/invoices`.
 * @param {string} path
 * @param {Record<string, string|number|boolean|undefined|null>} [params]
 */
export async function downloadAdminExportCsv(path, params = {}) {
  const qs = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v))
  })
  const s = qs.toString()
  const res = await fetch(`${apiBaseUrl()}/api${path}${s ? `?${s}` : ''}`, {
    headers: {
      Authorization: `Bearer ${getToken()}`,
      Accept: 'text/csv,*/*',
    },
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || `Error ${res.status}`)
  }
  const blob = await res.blob()
  let filename = 'export.csv'
  const cd = res.headers.get('Content-Disposition')
  if (cd) {
    const m = cd.match(/filename\*=UTF-8''([^;\n]+)|filename="([^"]+)"|filename=([^;\n]+)/i)
    const raw = m ? decodeURIComponent((m[1] || m[2] || m[3] || '').trim()) : ''
    if (raw) filename = raw.replace(/^["']|["']$/g, '')
  }
  return { blob, filename }
}
