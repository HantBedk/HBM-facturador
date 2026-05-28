import { api } from './api.js'

export function fetchInventoryHolderSettings() {
  return api('/admin/settings/inventory-holders')
}

export function updateInventoryHolderSettings(payload) {
  return api('/admin/settings/inventory-holders', {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}
