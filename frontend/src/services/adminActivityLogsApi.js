import { api } from './api.js'

/**
 * @param {{ scope?: 'all' | 'facturas', limit?: number }} [params]
 */
export function fetchAdminActivityLogs(params = {}) {
  const qs = new URLSearchParams()
  if (params.scope) qs.set('scope', params.scope)
  if (params.limit != null) qs.set('limit', String(params.limit))
  const s = qs.toString()
  return api(`/admin/activity-logs${s ? `?${s}` : ''}`)
}
