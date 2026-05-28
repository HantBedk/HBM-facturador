import { api } from './api'

export function fetchCompanies() {
  return api('/companies').then((r) => r.data)
}

/** Catálogo activo (único listado para todas las empresas). */
export function fetchServiceCatalogActive() {
  return api('/service-catalog/active').then((r) => r.data ?? [])
}

/** Márgenes globales servicio / venta inventario / alquiler inventario (solo admin). */
export function fetchTechnicianCatalogDiscount() {
  return api('/admin/service-catalog/technician-pricing').then((r) => r.data)
}

/**
 * @param {{
 *   technician_service_discount_percent: number,
 *   technician_inventory_sale_discount_percent: number,
 *   technician_inventory_rental_discount_percent: number,
 *   technician_service_margin_floor_percent: number,
 *   technician_inventory_sale_margin_floor_percent: number,
 *   technician_inventory_rental_margin_floor_percent: number,
 * }} body
 */
export function updateTechnicianCatalogDiscount(body) {
  return api('/admin/service-catalog/technician-pricing', {
    method: 'PUT',
    body: JSON.stringify(body),
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
    if (payload.kind != null && payload.kind !== '') {
      fd.append('kind', String(payload.kind))
    }
    if (payload.inventory_lot_id != null && payload.inventory_lot_id !== '') {
      fd.append('inventory_lot_id', String(payload.inventory_lot_id))
    }
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
  delete body.service_date
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

/** Solo admin: marca o anula la fecha de pago registrada al técnico (`Y-m-d` o null). */
export function patchServiceTechnicianPaid(id, payload) {
  return api(`/services/${id}/technician-paid`, {
    method: 'PATCH',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

/** Solo admin: crea servicio mínimo para un técnico (pendiente de completar). */
export function assignServiceToTechnician(payload) {
  return api('/admin/services/assign-to-technician', {
    method: 'POST',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

/**
 * Técnico: sustituye líneas e importes y cierra la asignación (mismo cuerpo que alta con ítems).
 * @param {number|string} id
 * @param {Record<string, unknown>} payload
 * @param {File[]} [photoFiles]
 */
export function completeServiceAssignment(id, payload, photoFiles = []) {
  const files = Array.isArray(photoFiles) ? photoFiles.filter((f) => f instanceof File) : []
  const hasItems = Array.isArray(payload.items) && payload.items.length > 0
  if (files.length > 0) {
    const fd = new FormData()
    fd.append('client_name', String(payload.client_name ?? ''))
    fd.append('service_type', String(payload.service_type ?? ''))
    if (hasItems) fd.append('items', JSON.stringify(payload.items))
    for (const f of files.slice(0, 4)) {
      fd.append('photos[]', f)
    }
    return api(`/services/${id}/complete-assignment`, {
      method: 'POST',
      body: fd,
    }).then((r) => r.data)
  }
  return api(`/services/${id}/complete-assignment`, {
    method: 'POST',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

/** Técnico: rechaza asignación pendiente. */
export function rejectServiceAssignment(id) {
  return api(`/services/${id}/reject-assignment`, {
    method: 'POST',
  }).then((r) => r.data)
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
