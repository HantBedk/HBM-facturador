/**
 * En desarrollo, `VITE_API_BASE_URL` vacío usa rutas relativas `/api/*` y el proxy de Vite
 * (evita CORS si entras por 127.0.0.1 y el .env solo tenía localhost, o al revés).
 */
function baseUrl() {
  const raw = import.meta.env.VITE_API_BASE_URL
  const trimmed = raw != null ? String(raw).trim() : ''
  if (trimmed !== '') return trimmed.replace(/\/$/, '')
  if (import.meta.env.DEV) return ''
  return ''
}

/** Base HTTP(S) del backend (sin `/api`). Para descargas binarias con `fetch`. */
export function apiBaseUrl() {
  return baseUrl()
}

const TOKEN_KEY = 'auth_token'

/** Token en sesión de pestaña o persistente (localStorage). */
export function getToken() {
  return sessionStorage.getItem(TOKEN_KEY) || localStorage.getItem(TOKEN_KEY)
}

/**
 * @param {string|null} token
 * @param {boolean} persistent true = localStorage (recordar sesión), false = sessionStorage
 */
export function setToken(token, persistent = true) {
  sessionStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(TOKEN_KEY)
  if (!token) return
  if (persistent) localStorage.setItem(TOKEN_KEY, token)
  else sessionStorage.setItem(TOKEN_KEY, token)
}

export function clearTokenStorage() {
  sessionStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(TOKEN_KEY)
}

/** API pública (sin token). JSON. */
export async function publicApi(path, options = {}) {
  const headers = {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    ...options.headers,
  }
  const res = await fetch(`${baseUrl()}/api${path}`, {
    ...options,
    headers,
  })

  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    const err = new Error(
      data.message ||
        (data.errors && Object.values(data.errors).flat()[0]) ||
        'Error en la solicitud'
    )
    err.status = res.status
    err.data = data
    if (data.code != null) err.code = data.code
    throw err
  }
  return data
}

/**
 * Descarga binaria (p. ej. PDF) sin token. Si falla, intenta leer JSON con mensaje.
 * @returns {Promise<{ blob: Blob, filename: string }>}
 */
export async function publicApiBlob(path, options = {}) {
  const headers = {
    Accept: 'application/pdf',
    ...options.headers,
  }
  if (!(options.body instanceof FormData) && options.body != null) {
    headers['Content-Type'] = 'application/json'
  }
  const res = await fetch(`${baseUrl()}/api${path}`, {
    ...options,
    headers,
  })
  if (!res.ok) {
    const ct = res.headers.get('content-type') || ''
    let message = 'No se pudo descargar el documento.'
    if (ct.includes('application/json')) {
      const data = await res.json().catch(() => ({}))
      message = data.message || message
    }
    const err = new Error(message)
    err.status = res.status
    throw err
  }
  const ab = await res.arrayBuffer()
  const mime = (res.headers.get('Content-Type') || 'application/pdf').split(';')[0].trim().toLowerCase()
  const blob = new Blob([ab], { type: mime || 'application/pdf' })
  const cd = res.headers.get('content-disposition') || ''
  let filename = 'factura.pdf'
  const m = /filename\*?=(?:UTF-8'')?["']?([^"';]+)/i.exec(cd)
  if (m?.[1]) filename = decodeURIComponent(m[1].replace(/["']/g, '').trim())
  return { blob, filename }
}

export async function api(path, options = {}) {
  const { skipAuth: skipAuthHeader, ...fetchOptions } = options
  const headers = {
    Accept: 'application/json',
    ...fetchOptions.headers,
  }
  if (!(fetchOptions.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
  }
  if (!skipAuthHeader) {
    const token = getToken()
    if (token) headers.Authorization = `Bearer ${token}`
  }

  let res
  try {
    res = await fetch(`${baseUrl()}/api${path}`, {
      ...fetchOptions,
      headers,
    })
  } catch {
    const err = new Error(
      'Sin conexión con el servidor. Comprueba que el backend esté activo y la URL en VITE_API_BASE_URL (p. ej. http://localhost:8080).'
    )
    err.status = 0
    err.data = {}
    throw err
  }

  const data = await res.json().catch(() => ({}))
  if (!res.ok) {
    // No borrar sesión en fallo de login: 401 aquí no indica token inválido.
    if (res.status === 401 && path !== '/auth/login') {
      clearTokenStorage()
      try {
        sessionStorage.removeItem('auth_user')
        localStorage.removeItem('auth_user')
      } catch {
        /* ignore */
      }
    }
    const fromErrors = data.errors && Object.values(data.errors).flat()[0]
    const msg =
      data.message ||
      fromErrors ||
      (res.status === 403
        ? data.code === 'permission_denied'
          ? 'No tiene permisos para esta acción.'
          : 'Acceso denegado.'
        : null) ||
      (res.status >= 500
        ? `Error del servidor (${res.status}). Comprueba que el backend esté activo.`
        : res.status === 404
          ? 'API no encontrada. Revisa VITE_API_BASE_URL o el proxy de Vite en desarrollo.'
          : 'Error en la solicitud')
    const err = new Error(msg)
    err.status = res.status
    err.data = data
    err.code = data.code
    if (import.meta.env.DEV && res.status >= 500) {
      console.warn('[api]', res.status, path, data)
    }
    throw err
  }
  return data
}
