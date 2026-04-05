import { api } from './api.js'

export function fetchBillingAutomationSettings() {
  return api('/admin/settings/billing-automation')
}

/**
 * @param {{
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
