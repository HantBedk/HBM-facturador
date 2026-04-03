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
