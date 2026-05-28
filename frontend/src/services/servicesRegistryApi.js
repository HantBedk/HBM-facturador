import { api, apiBaseUrl, getToken } from './api.js'

/**
 * CSV compatible con Excel (UTF-8 BOM, `;`). Mismas columnas que importación.
 * @param {Record<string, string|number|boolean|undefined|null>} [params]
 */
export async function downloadServicesRegistryCsv(params = {}) {
  const qs = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v))
  })
  const s = qs.toString()
  const res = await fetch(`${apiBaseUrl()}/api/admin/export/services${s ? `?${s}` : ''}`, {
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
  let filename = 'servicios.csv'
  const cd = res.headers.get('Content-Disposition')
  if (cd) {
    const m = cd.match(/filename\*=UTF-8''([^;\n]+)|filename="([^"]+)"|filename=([^;\n]+)/i)
    const raw = m ? decodeURIComponent((m[1] || m[2] || m[3] || '').trim()) : ''
    if (raw) filename = raw
  }
  return { blob, filename }
}

export async function downloadServicesRegistryTemplate() {
  const res = await fetch(`${apiBaseUrl()}/api/admin/export/services/template`, {
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
  let filename = 'plantilla-servicios.csv'
  const cd = res.headers.get('Content-Disposition')
  if (cd) {
    const m = cd.match(/filename\*=UTF-8''([^;\n]+)|filename="([^"]+)"|filename=([^;\n]+)/i)
    const raw = m ? decodeURIComponent((m[1] || m[2] || m[3] || '').trim()) : ''
    if (raw) filename = raw
  }
  return { blob, filename }
}

/**
 * Envía el archivo al backend y recibe { job_id } de forma inmediata (HTTP 202).
 * @param {File} file
 * @param {{ dryRun?: boolean }} [opts]
 */
export function importServicesRegistrySpreadsheet(file, opts = {}) {
  const fd = new FormData()
  fd.append('file', file)
  if (opts.dryRun) {
    fd.append('dry_run', '1')
  }
  return api('/admin/import/services', {
    method: 'POST',
    body: fd,
  })
}

/**
 * Consulta el estado de un job de importación.
 * @param {string} jobId UUID devuelto por importServicesRegistrySpreadsheet
 * @returns {Promise<{ status: 'pending'|'completed'|'failed', message?: string, imported?: number, updated?: number, skipped?: number, issues?: Array }>}
 */
export function fetchServicesImportResult(jobId) {
  return api(`/admin/import/services/${encodeURIComponent(jobId)}/result`)
}
