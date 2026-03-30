import { api, apiBaseUrl, getToken } from './api'

/**
 * @param {Record<string, string|number>} params
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

export function patchInvoiceStatus(id, status) {
  return api(`/admin/invoices/${id}/status`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  }).then((r) => r.data)
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
 * @returns {Promise<Blob>}
 */
export async function downloadInvoicePdfBlob(id) {
  const res = await fetch(`${apiBaseUrl()}/api/admin/invoices/${id}/pdf`, {
    headers: {
      Authorization: `Bearer ${getToken()}`,
      Accept: 'application/pdf',
    },
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || `Error ${res.status}`)
  }
  return res.blob()
}
