import { api } from './api'

/**
 * @param {{ year: number, month: number, company_id?: string|number, q?: string }} period
 */
function historialQueryString(period) {
  const qs = new URLSearchParams({
    year: String(period.year),
    month: String(period.month),
  })
  if (period.company_id != null && period.company_id !== '') {
    qs.set('company_id', String(period.company_id))
  }
  if (period.q != null && String(period.q).trim() !== '') {
    qs.set('q', String(period.q).trim())
  }
  return qs.toString()
}

/**
 * @param {number|string} userId
 * @param {{ year: number, month: number, company_id?: string|number, q?: string }} period
 */
export function fetchEmpleadoHistorialAdmin(userId, period) {
  const s = historialQueryString(period)
  return api(`/admin/empleados/${userId}/historial?${s}`).then((r) => r.data)
}
