import { api } from '@/services/api.js'

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

/** Solo rol técnico. Respuesta: { data: { venta_enabled, alquiler_enabled } }. */
export function fetchEmpleadoCommercialInventorySettings() {
  return api('/inventory/empleado-commercial-settings')
}

export function createInventoryLot(body) {
  return api('/inventory/lots', { method: 'POST', body: JSON.stringify(body) })
}

export function updateInventoryLot(id, body, params = {}) {
  return api(`/inventory/lots/${id}${qs(params)}`, { method: 'PATCH', body: JSON.stringify(body) })
}

export function deleteInventoryLot(id, params = {}) {
  return api(`/inventory/lots/${id}${qs(params)}`, { method: 'DELETE' })
}

export function fetchInventorySales(params = {}) {
  return api(`/inventory/sales${qs(params)}`)
}

export function createInventorySale(body) {
  return api('/inventory/sales', { method: 'POST', body: JSON.stringify(body) })
}

export function fetchInventoryRentals(params = {}) {
  return api(`/inventory/rentals${qs(params)}`)
}

export function createInventoryRental(body) {
  return api('/inventory/rentals', { method: 'POST', body: JSON.stringify(body) })
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
