import { api } from './api'

/**
 * @param {{ year: number, month: number }} period
 */
export function fetchMyHistorial(period) {
  const qs = new URLSearchParams({
    year: String(period.year),
    month: String(period.month),
  })
  return api(`/empleado/historial?${qs}`).then((r) => r.data)
}

/**
 * @param {number|string} userId
 * @param {{ year: number, month: number }} period
 */
export function fetchEmpleadoHistorialAdmin(userId, period) {
  const qs = new URLSearchParams({
    year: String(period.year),
    month: String(period.month),
  })
  return api(`/admin/empleados/${userId}/historial?${qs}`).then((r) => r.data)
}
