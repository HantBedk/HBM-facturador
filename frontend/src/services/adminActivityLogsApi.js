import { api } from './api.js'

/**
 * @param {{ scope?: 'all' | 'facturas', date?: string, calendar_month?: string, limit?: number }} [params]
 * @param {string} [params.date] Fecha YYYY-MM-DD (día en APP_TIMEZONE del servidor).
 * @param {string} [params.calendar_month] Mes YYYY-MM para pintar el calendario (días con actividad).
 */
export function fetchAdminActivityLogs(params = {}) {
  const qs = new URLSearchParams()
  if (params.scope) qs.set('scope', params.scope)
  if (params.date) qs.set('date', params.date)
  if (params.calendar_month) qs.set('calendar_month', params.calendar_month)
  if (params.limit != null) qs.set('limit', String(params.limit))
  const s = qs.toString()
  return api(`/admin/activity-logs${s ? `?${s}` : ''}`)
}
