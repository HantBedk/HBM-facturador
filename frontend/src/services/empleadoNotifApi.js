import { api } from './api.js'

/**
 * @param {{ unread_only?: boolean, limit?: number }} [params]
 */
export function fetchEmpleadoNotifications(params = {}) {
  const qs = new URLSearchParams()
  if (params.unread_only) qs.set('unread_only', '1')
  if (params.limit != null) qs.set('limit', String(params.limit))
  const s = qs.toString()
  return api(`/empleado/notifications${s ? `?${s}` : ''}`).then((r) => {
    const list = r.data
    return Array.isArray(list) ? list : []
  })
}

export function fetchEmpleadoUnreadCount() {
  return api('/empleado/notifications/unread-count').then((r) => r.count ?? 0)
}

export function markEmpleadoNotificationRead(id) {
  return api(`/empleado/notifications/${id}/read`, { method: 'PATCH' }).then((r) => r.data)
}

export function markAllEmpleadoNotificationsRead() {
  return api('/empleado/notifications/read-all', { method: 'POST' })
}

/** Solicita un ticket de un solo uso para abrir el stream SSE de notificaciones. */
export function requestEmpleadoNotifStreamTicket() {
  return api('/empleado/notifications/stream-ticket', { method: 'POST' })
}

/**
 * Paginado — para la página completa de historial de avisos.
 * Devuelve { data: [], meta: { current_page, last_page, total, per_page } }
 * @param {{ page?: number, per_page?: number, unread_only?: boolean }} [params]
 */
export function fetchEmpleadoNotificationsPage(params = {}) {
  const qs = new URLSearchParams()
  qs.set('page', String(params.page ?? 1))
  if (params.per_page != null) qs.set('per_page', String(params.per_page))
  if (params.unread_only) qs.set('unread_only', '1')
  return api(`/empleado/notifications?${qs.toString()}`)
}
