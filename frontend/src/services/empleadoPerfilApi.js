import { api } from './api'

/** La función `api()` devuelve ya el cuerpo JSON (no hay envoltura `.data`). */
export function fetchEmpleadoPerfilForm() {
  return api('/empleado/perfil')
}

export function saveEmpleadoPerfil(payload) {
  return api('/empleado/perfil', {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}
