import { api } from './api.js'

export function fetchInventoryLocationSettings() {
  return api('/admin/settings/inventory-locations')
}

export function updateInventoryLocationSettings(payload) {
  return api('/admin/settings/inventory-locations', {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}
