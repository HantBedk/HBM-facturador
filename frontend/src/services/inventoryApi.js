import { api, apiBaseUrl, getToken } from '@/services/api.js'

function qs(params) {
  const q = new URLSearchParams()
  Object.entries(params || {}).forEach(([k, v]) => {
    if (v === undefined || v === null || v === '') return
    q.set(k, String(v))
  })
  const s = q.toString()
  return s ? `?${s}` : ''
}

export function fetchInventoryLots(params = {}) {
  return api(`/inventory/lots${qs(params)}`)
}

export function fetchInventoryLot(id, params = {}) {
  return api(`/inventory/lots/${id}${qs(params)}`)
}

/** Solo rol técnico. Respuesta: { data: { venta_enabled, alquiler_enabled } }. */
export function fetchEmpleadoCommercialInventorySettings() {
  return api('/inventory/empleado-commercial-settings')
}

export function createInventoryLot(body) {
  return api('/inventory/lots', { method: 'POST', body: JSON.stringify(body) })
}

/** @param {FormData} formData file, tenant_company_id, dry_run (0|1) */
export function importInventoryLotsCsv(formData) {
  return api('/inventory/lots/import', { method: 'POST', body: formData })
}

export function updateInventoryLot(id, body, params = {}) {
  return api(`/inventory/lots/${id}${qs(params)}`, { method: 'PATCH', body: JSON.stringify(body) })
}

export function deleteInventoryLot(id, params = {}) {
  return api(`/inventory/lots/${id}${qs(params)}`, { method: 'DELETE' })
}

export function reportInventoryLifecycle(id, body) {
  return api(`/inventory/lots/${id}/lifecycle/report`, { method: 'POST', body: JSON.stringify(body) })
}

export function fetchInventoryLifecycleRequests(params = {}) {
  return api(`/inventory/lifecycle/requests${qs(params)}`)
}

export function approveInventoryLifecycleRequest(requestId, body) {
  return api(`/inventory/lifecycle/requests/${requestId}/approve`, {
    method: 'POST',
    body: JSON.stringify(body),
  })
}

export function fetchInventoryAuditEvents(params = {}) {
  return api(`/inventory/audit-events${qs(params)}`)
}

export function fetchInventoryMovements(params = {}) {
  return api(`/inventory/movements${qs(params)}`)
}

export function fetchInventoryLotAttachments(lotId, params = {}) {
  return api(`/inventory/lots/${lotId}/attachments${qs(params)}`)
}

/** @param {Record<string, string|number|undefined>} [meta] inventory_audit_event_id opcional (evento de hoja de vida). */
export function uploadInventoryLotAttachment(lotId, file, params = {}, meta = {}) {
  const fd = new FormData()
  fd.append('file', file)
  if (meta.inventory_audit_event_id != null && String(meta.inventory_audit_event_id).trim() !== '') {
    fd.append('inventory_audit_event_id', String(meta.inventory_audit_event_id))
  }
  return api(`/inventory/lots/${lotId}/attachments${qs(params)}`, { method: 'POST', body: fd })
}

export function deleteInventoryLotAttachment(lotId, attachmentId, params = {}) {
  return api(`/inventory/lots/${lotId}/attachments/${attachmentId}${qs(params)}`, { method: 'DELETE' })
}

/** PDF hoja de vida (auth). */
export async function downloadInventoryLotLifecycleSheetPdf(lotId, params = {}) {
  const q = qs(params)
  const res = await fetch(`${apiBaseUrl()}/api/inventory/lots/${lotId}/lifecycle-sheet${q}`, {
    headers: {
      Authorization: `Bearer ${getToken()}`,
      Accept: 'application/pdf',
    },
  })
  if (!res.ok) {
    const data = await res.json().catch(() => ({}))
    throw new Error(data.message || `Error ${res.status}`)
  }
  const ab = await res.arrayBuffer()
  const mime = (res.headers.get('Content-Type') || 'application/pdf').split(';')[0].trim().toLowerCase()
  return new Blob([ab], { type: mime || 'application/pdf' })
}

export function fetchInventorySales(params = {}) {
  return api(`/inventory/sales${qs(params)}`)
}

export function createInventorySale(body, params = {}) {
  return api(`/inventory/sales${qs(params)}`, { method: 'POST', body: JSON.stringify(body) })
}

export function deleteInventorySale(id, params = {}) {
  return api(`/inventory/sales/${id}${qs(params)}`, { method: 'DELETE' })
}

export function fetchInventoryRentals(params = {}) {
  return api(`/inventory/rentals${qs(params)}`)
}

export function createInventoryRental(body, params = {}) {
  return api(`/inventory/rentals${qs(params)}`, { method: 'POST', body: JSON.stringify(body) })
}

export function deleteInventoryRental(id, params = {}) {
  return api(`/inventory/rentals/${id}${qs(params)}`, { method: 'DELETE' })
}

export function closeInventoryRental(id) {
  return api(`/inventory/rentals/${id}/close`, { method: 'POST' })
}

export function fetchInventoryLocationOptions() {
  return api('/inventory/location-options')
}

/** Respuesta: { locations, asset_types, physical_conditions } — listas de strings desde AppSetting. */
export async function fetchInventoryFormFieldOptions() {
  const res = await fetchInventoryLocationOptions()
  const d = res?.data
  const fallbackLocations = ['Bodega principal', 'Taller', 'Oficina administrativa']
  const fallbackTypes = ['Equipo', 'Herramienta', 'Accesorio', 'Consumible', 'Mobiliario', 'Otro']
  const fallbackPhys = ['Nuevo', 'Bueno', 'Regular', 'Requiere mantenimiento', 'Fuera de servicio']
  if (d && typeof d === 'object' && !Array.isArray(d)) {
    return {
      locations: Array.isArray(d.locations) && d.locations.length ? d.locations : fallbackLocations,
      asset_types: Array.isArray(d.asset_types) && d.asset_types.length ? d.asset_types : fallbackTypes,
      physical_conditions:
        Array.isArray(d.physical_conditions) && d.physical_conditions.length ? d.physical_conditions : fallbackPhys,
    }
  }
  if (Array.isArray(d) && d.length) {
    return { locations: d, asset_types: fallbackTypes, physical_conditions: fallbackPhys }
  }
  return { locations: fallbackLocations, asset_types: fallbackTypes, physical_conditions: fallbackPhys }
}

export function fetchInventoryHolderOptions() {
  return api('/inventory/holder-options')
}
