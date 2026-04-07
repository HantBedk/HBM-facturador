import { api } from './api'

/**
 * @param {{ q?: string, estado?: string }} [params]
 */
export function fetchAdminCompanies(params = {}) {
  const qs = new URLSearchParams()
  if (params.q?.trim()) qs.set('q', params.q.trim())
  if (params.estado) qs.set('estado', params.estado)
  const s = qs.toString()
  return api(`/admin/companies${s ? `?${s}` : ''}`).then((r) => r.data)
}

/**
 * @param {{
 *   nombre: string,
 *   factura_sigla: string,
 *   nit?: string | null,
 *   telefono?: string | null,
 *   correo?: string | null,
 *   estado?: string
 * }} payload
 */
export function createCompany(payload) {
  return api('/admin/companies', {
    method: 'POST',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

/**
 * @param {number|string} id
 * @param {{
 *   nombre: string,
 *   factura_sigla: string,
 *   nit?: string | null,
 *   telefono?: string | null,
 *   correo?: string | null,
 *   estado: string
 * }} payload
 */
export function updateCompany(id, payload) {
  return api(`/admin/companies/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

/**
 * @param {number|string} id
 * @param {'activo' | 'inactivo'} estado
 */
export function patchCompanyEstado(id, estado) {
  return api(`/admin/companies/${id}/estado`, {
    method: 'PATCH',
    body: JSON.stringify({ estado }),
  }).then((r) => r.data)
}

/** @param {number|string} id */
export function deleteCompany(id) {
  return api(`/admin/companies/${id}`, { method: 'DELETE' })
}

/**
 * KPIs de facturación/cobros/servicios para un mes (por defecto mes anterior en servidor).
 * @param {number|string} companyId
 * @param {{ year?: number, month?: number }} [params]
 */
export function fetchCompanyMonthlyDashboard(companyId, params = {}) {
  const qs = new URLSearchParams()
  if (params.year != null) qs.set('year', String(params.year))
  if (params.month != null) qs.set('month', String(params.month))
  const s = qs.toString()
  return api(`/admin/companies/${companyId}/monthly-dashboard${s ? `?${s}` : ''}`).then((r) => r.data)
}

/** Plantillas de cargos fijos mensuales (automatización de facturación). */
export function fetchCompanyRecurringServices(companyId) {
  return api(`/admin/companies/${companyId}/recurring-services`).then((r) => r.data ?? [])
}

/**
 * @param {number|string} companyId
 * @param {{
 *   catalog_id: number,
 *   amount: number|string,
 *   description?: string | null,
 *   service_type?: string | null,
 *   is_active?: boolean,
 *   sort_order?: number
 * }} payload
 */
export function createCompanyRecurringService(companyId, payload) {
  return api(`/admin/companies/${companyId}/recurring-services`, {
    method: 'POST',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

/**
 * @param {number|string} companyId
 * @param {number|string} recurringId
 * @param {Record<string, unknown>} payload
 */
export function updateCompanyRecurringService(companyId, recurringId, payload) {
  return api(`/admin/companies/${companyId}/recurring-services/${recurringId}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

/** @param {number|string} companyId @param {number|string} recurringId */
export function deleteCompanyRecurringService(companyId, recurringId) {
  return api(`/admin/companies/${companyId}/recurring-services/${recurringId}`, { method: 'DELETE' })
}
