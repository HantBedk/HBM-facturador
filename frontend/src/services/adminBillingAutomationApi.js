import { api, apiBaseUrl, getToken } from './api.js'

export function fetchBillingAutomationSettings() {
  return api('/admin/settings/billing-automation')
}

/**
 * @param {{
 *   current_password: string
 *   draft_generation_enabled: boolean
 *   draft_generation_day: number
 *   draft_generation_period: 'current' | 'previous'
 * }} payload
 */
export function updateBillingAutomationSettings(payload) {
  return api('/admin/settings/billing-automation', {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

/**
 * CSV compatible con Excel (UTF-8 BOM, `;`). `path` relativo bajo `/api`.
 * @param {string} path
 * @param {Record<string, string|number|boolean|undefined|null>} [params]
 */
export async function downloadBillingExportCsv(path, params = {}) {
  const qs = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v))
  })
  const s = qs.toString()
  const res = await fetch(`${apiBaseUrl()}/api${path}${s ? `?${s}` : ''}`, {
    headers: {
      Authorization: `Bearer ${getToken()}`,
      Accept: 'text/csv,*/*',
    },
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || `Error ${res.status}`)
  }
  const blob = await res.blob()
  let filename = 'export.csv'
  const cd = res.headers.get('Content-Disposition')
  if (cd) {
    const m = cd.match(/filename\*=UTF-8''([^;\n]+)|filename="([^"]+)"|filename=([^;\n]+)/i)
    const raw = m ? decodeURIComponent((m[1] || m[2] || m[3] || '').trim()) : ''
    if (raw) filename = raw
  }
  return { blob, filename }
}

/** @param {File} file */
export function importRecurringFixedChargesFromSpreadsheet(file) {
  const fd = new FormData()
  fd.append('file', file)
  return api('/admin/import/recurring-fixed-charges', {
    method: 'POST',
    body: fd,
  })
}
