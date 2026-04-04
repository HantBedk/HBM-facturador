import { api } from './api.js'

/**
 * @param {string} correo_solicitado
 */
export function solicitarCambioCorreo(correo_solicitado) {
  return api('/empleado/correo-solicitud', {
    method: 'POST',
    body: JSON.stringify({ correo_solicitado: correo_solicitado.trim() }),
  })
}

export function cancelarCorreoSolicitud() {
  return api('/empleado/correo-solicitud', { method: 'DELETE' })
}
