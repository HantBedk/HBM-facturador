import { api } from './api.js'

/**
 * @param {{ unread_only?: boolean, limit?: number }} [params]
 */
export function fetchAdminNotifications(params = {}) {
  const qs = new URLSearchParams()
  if (params.unread_only) qs.set('unread_only', '1')
  if (params.limit != null) qs.set('limit', String(params.limit))
  const s = qs.toString()
  return api(`/admin/notifications${s ? `?${s}` : ''}`).then((r) => r.data ?? [])
}

export function fetchUnreadNotificationCount() {
  return api('/admin/notifications/unread-count').then((r) => r.count ?? 0)
}

export function markNotificationRead(id) {
  return api(`/admin/notifications/${id}/read`, { method: 'PATCH' }).then((r) => r.data)
}

export function markAllNotificationsRead() {
  return api('/admin/notifications/read-all', { method: 'POST' })
}
