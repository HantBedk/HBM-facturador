import { api } from './api'

/** Servicios con ref. técnico pendiente de abono registrado. */
export function fetchTechnicianPendingServices(userId) {
  return api(`/admin/empleados/${userId}/technician-pending-services`)
}

/**
 * @param {number|string} userId
 * @param {{ service_ids: number[], technician_paid_at: string }} payload
 */
export function postTechnicianBatchPay(userId, payload) {
  return api(`/admin/empleados/${userId}/technician-pay`, {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}
