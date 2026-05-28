import { api, apiBaseUrl, getToken } from './api.js'

export function triggerBackup() {
  return api('/admin/system/backup', { method: 'POST' })
}

export function listBackups() {
  return api('/admin/system/backups')
}

export function deleteBackup(filename) {
  return api(`/admin/system/backups/${encodeURIComponent(filename)}`, { method: 'DELETE' })
}

export function restoreBackup(filename) {
  return api(`/admin/system/backups/${encodeURIComponent(filename)}/restore`, { method: 'POST' })
}

export function restoreUpload(file) {
  const form = new FormData()
  form.append('file', file)
  return api('/admin/system/restore-upload', { method: 'POST', body: form })
}

export function restoreZip(file) {
  const form = new FormData()
  form.append('file', file)
  return api('/admin/system/restore-zip', { method: 'POST', body: form })
}

export function getDbStatus() {
  return api('/admin/system/db-status')
}

export function wipeDatabase(password) {
  return api('/admin/system/wipe', {
    method: 'POST',
    body: JSON.stringify({ password }),
  })
}

export async function downloadBackup(filename) {
  const res = await fetch(
    `${apiBaseUrl()}/api/admin/system/backups/${encodeURIComponent(filename)}`,
    { headers: { Authorization: `Bearer ${getToken()}` } }
  )
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || `Error ${res.status}`)
  }
  return { blob: await res.blob(), filename }
}

export async function downloadSqlDirect() {
  const res = await fetch(`${apiBaseUrl()}/api/admin/system/download-sql`, {
    headers: { Authorization: `Bearer ${getToken()}` },
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || `Error ${res.status}`)
  }
  const blob = await res.blob()
  let filename = 'respaldo-HBM.sql'
  const cd = res.headers.get('Content-Disposition')
  if (cd) {
    const m = cd.match(/filename\*=UTF-8''([^;\n]+)|filename="([^"]+)"|filename=([^;\n]+)/i)
    const raw = m ? decodeURIComponent((m[1] || m[2] || m[3] || '').trim()) : ''
    if (raw) filename = raw
  }
  return { blob, filename }
}

export async function downloadFullExport() {
  const res = await fetch(`${apiBaseUrl()}/api/admin/system/full-export`, {
    headers: { Authorization: `Bearer ${getToken()}` },
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || `Error ${res.status}`)
  }
  const blob = await res.blob()
  let filename = 'respaldo-HBM.zip'
  const cd = res.headers.get('Content-Disposition')
  if (cd) {
    const m = cd.match(/filename\*=UTF-8''([^;\n]+)|filename="([^"]+)"|filename=([^;\n]+)/i)
    const raw = m ? decodeURIComponent((m[1] || m[2] || m[3] || '').trim()) : ''
    if (raw) filename = raw
  }
  return { blob, filename }
}
