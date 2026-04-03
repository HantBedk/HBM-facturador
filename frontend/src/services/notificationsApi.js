import { api } from './api.js'

/**
 * @param {{ unread_only?: boolean, limit?: number, category?: 'empleados'|'servicios'|'facturas' }} [params]
 */
export function fetchAdminNotifications(params = {}) {
  const qs = new URLSearchParams()
  if (params.unread_only) qs.set('unread_only', '1')
  if (params.limit != null) qs.set('limit', String(params.limit))
  if (params.category) qs.set('category', params.category)
  const s = qs.toString()
  return api(`/admin/notifications${s ? `?${s}` : ''}`).then((r) => {
    const list = r.data
    return Array.isArray(list) ? list : []
  })
}

/**
 * @returns {Promise<{ count: number, byCategory: { empleados: number, servicios: number, facturas: number } }>}
 */
export function fetchUnreadNotificationCount() {
  return api('/admin/notifications/unread-count').then((r) => ({
    count: r.count ?? 0,
    byCategory: {
      empleados: r.by_category?.empleados ?? 0,
      servicios: r.by_category?.servicios ?? 0,
      facturas: r.by_category?.facturas ?? 0,
    },
  }))
}

export function markNotificationRead(id) {
  return api(`/admin/notifications/${id}/read`, { method: 'PATCH' }).then((r) => r.data)
}

export function markAllNotificationsRead() {
  return api('/admin/notifications/read-all', { method: 'POST' })
}
