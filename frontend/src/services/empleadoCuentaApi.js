import { api } from './api'

export function updateEmpleadoPassword(payload) {
  return api('/empleado/password', {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

export function fetchEmpleadoNotificacionesPreferencias() {
  return api('/empleado/notificaciones-preferencias')
}

export function saveEmpleadoNotificacionesPreferencias(types) {
  return api('/empleado/notificaciones-preferencias', {
    method: 'PUT',
    body: JSON.stringify({ types }),
  })
}
