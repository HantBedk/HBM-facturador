import { publicApi } from './api.js'

/**
 * Solicitud «olvidé contraseña» (sin sesión). Respuesta siempre genérica por seguridad.
 * @param {{ correo: string, numero_documento: string }} payload
 */
export function requestForgotPassword(payload) {
  return publicApi('/auth/forgot-password', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}
