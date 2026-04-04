import { api } from './api.js'

export function fetchEmpleadoNotificacionSettings() {
  return api('/admin/settings/empleado-notificaciones')
}

/**
 * @param {Record<string, boolean>} types
 */
export function updateEmpleadoNotificacionSettings(types) {
  return api('/admin/settings/empleado-notificaciones', {
    method: 'PUT',
    body: JSON.stringify({ types }),
  })
}
