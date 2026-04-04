import { api } from './api'

export function fetchCompanies() {
  return api('/companies').then((r) => r.data)
}

/** Catálogo activo para una empresa (ítems globales + de esa empresa). */
export function fetchServiceCatalogActive(companyId) {
  const id = Number(companyId)
  if (!id) return Promise.resolve([])
  return api(`/service-catalog/active?company_id=${encodeURIComponent(id)}`).then((r) => r.data ?? [])
}

/** @param {Record<string, string|number>} [params] */
export function fetchAdminServiceCatalog(params = {}) {
  const qs = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v))
  })
  const s = qs.toString()
  return api(`/admin/service-catalog${s ? `?${s}` : ''}`)
}

export function createServiceCatalogItem(payload) {
  return api('/admin/service-catalog', {
    method: 'POST',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

export function updateServiceCatalogItem(id, payload) {
  return api(`/admin/service-catalog/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

export function patchServiceCatalogEstado(id, status) {
  return api(`/admin/service-catalog/${id}/estado`, {
    method: 'PATCH',
    body: JSON.stringify({ status }),
  }).then((r) => r.data)
}

export function deleteServiceCatalogItem(id) {
  return api(`/admin/service-catalog/${id}`, { method: 'DELETE' }).then((r) => r.data)
}

/** @param {number[]} ids */
export function bulkDestroyServiceCatalogItems(ids) {
  return api('/admin/service-catalog/bulk-destroy', {
    method: 'POST',
    body: JSON.stringify({ ids }),
  }).then((r) => r.data)
}

/** Descuento % sobre precio de lista del catálogo que ve el técnico (solo admin). */
export function fetchTechnicianCatalogDiscount() {
  return api('/admin/service-catalog/technician-pricing').then((r) => r.data)
}

export function updateTechnicianCatalogDiscount(technician_catalog_discount_percent) {
  return api('/admin/service-catalog/technician-pricing', {
    method: 'PUT',
    body: JSON.stringify({ technician_catalog_discount_percent }),
  }).then((r) => r.data)
}

/** @param {Record<string, string|number|boolean>} [params] */
export function fetchServiceCatalogSuggestions(params = {}) {
  const qs = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v))
  })
  const s = qs.toString()
  return api(`/admin/service-catalog-suggestions${s ? `?${s}` : ''}`)
}

export function approveServiceCatalogSuggestion(id, payload = {}) {
  return api(`/admin/service-catalog-suggestions/${id}/approve`, {
    method: 'POST',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

export function rejectServiceCatalogSuggestion(id) {
  return api(`/admin/service-catalog-suggestions/${id}/reject`, {
    method: 'POST',
    body: JSON.stringify({}),
  }).then((r) => r.data)
}

export function fetchEmpleados() {
  return api('/empleados').then((r) => r.data)
}

export function fetchServices(params = {}) {
  const qs = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v))
  })
  const q = qs.toString()
  return api(`/services${q ? `?${q}` : ''}`)
}

export function fetchService(id) {
  return api(`/services/${id}`).then((r) => r.data)
}

/**
 * @param {Record<string, unknown>} payload
 * @param {File[]} [photoFiles] máximo 4 imágenes
 */
export function createService(payload, photoFiles = []) {
  const files = Array.isArray(photoFiles) ? photoFiles.filter((f) => f instanceof File) : []
  const hasItems = Array.isArray(payload.items) && payload.items.length > 0
  if (files.length > 0) {
    const fd = new FormData()
    fd.append('company_id', String(payload.company_id))
    fd.append('client_name', String(payload.client_name ?? ''))
    fd.append('service_type', String(payload.service_type ?? ''))
    fd.append('description', String(payload.description ?? ''))
    fd.append('amount', String(payload.amount))
    fd.append('service_date', String(payload.service_date ?? ''))
    if (hasItems) {
      fd.append('items', JSON.stringify(payload.items))
    } else if (payload.catalog_id != null && payload.catalog_id !== '') {
      fd.append('catalog_id', String(payload.catalog_id))
    }
    for (const f of files.slice(0, 4)) {
      fd.append('photos[]', f)
    }
    return api('/services', {
      method: 'POST',
      body: fd,
    }).then((r) => r.data)
  }
  const body = { ...payload }
  if (body.catalog_id === '' || body.catalog_id == null) delete body.catalog_id
  if (!hasItems) delete body.items
  return api('/services', {
    method: 'POST',
    body: JSON.stringify(body),
  }).then((r) => r.data)
}

export function updateService(id, payload) {
  return api(`/services/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

export function archiveService(id) {
  return api(`/services/${id}/archive`, { method: 'PATCH' }).then((r) => r.data)
}

const DRAFT_KEY = 'hbm_service_draft'
const CLIENTS_KEY = 'hbm_recent_client_names'

export function loadServiceDraft() {
  try {
    const raw = localStorage.getItem(DRAFT_KEY)
    return raw ? JSON.parse(raw) : null
  } catch {
    return null
  }
}

export function saveServiceDraft(data) {
  localStorage.setItem(DRAFT_KEY, JSON.stringify(data))
}

export function clearServiceDraft() {
  localStorage.removeItem(DRAFT_KEY)
}

export function pushRecentClientName(name) {
  const n = (name || '').trim()
  if (!n) return
  try {
    const raw = localStorage.getItem(CLIENTS_KEY)
    const list = raw ? JSON.parse(raw) : []
    const next = [n, ...list.filter((x) => x.toLowerCase() !== n.toLowerCase())].slice(0, 12)
    localStorage.setItem(CLIENTS_KEY, JSON.stringify(next))
  } catch {
    /* ignore */
  }
}

export function getRecentClientNames() {
  try {
    const raw = localStorage.getItem(CLIENTS_KEY)
    return raw ? JSON.parse(raw) : []
  } catch {
    return []
  }
}
