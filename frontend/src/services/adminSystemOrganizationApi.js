import { api, apiBaseUrl, getToken } from '@/services/api.js'

export function fetchSystemOrganization() {
  return api('/admin/settings/system-organization').then((r) => r.data)
}

/**
 * @param {Record<string, string | null | undefined>} payload
 */
export function updateSystemOrganization(payload) {
  return api('/admin/settings/system-organization', {
    method: 'PUT',
    body: JSON.stringify(payload),
  }).then((r) => ({ message: r.message, data: r.data }))
}

/**
 * @param {FormData} formData campo `file`
 */
export function uploadSystemOrganizationLogo(formData) {
  return api('/admin/settings/system-organization/logo', {
    method: 'POST',
    body: formData,
  }).then((r) => ({ message: r.message, data: r.data }))
}

export function deleteSystemOrganizationLogo() {
  return api('/admin/settings/system-organization/logo', {
    method: 'DELETE',
  }).then((r) => ({ message: r.message, data: r.data }))
}

/** URL para <img> no envía Bearer; use blob con esta función. */
export async function fetchSystemOrganizationLogoBlob() {
  const base = apiBaseUrl()
  const token = getToken()
  const headers = { Accept: 'image/*' }
  if (token) headers.Authorization = `Bearer ${token}`
  const res = await fetch(`${base}/api/admin/settings/system-organization/logo`, { headers })
  if (!res.ok) return null
  return res.blob()
}
