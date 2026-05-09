import { api } from '@/services/api.js'

export function fetchMailNotificationsSettings() {
  return api('/admin/settings/mail-notifications')
}

export function updateMailNotificationsSettings(payload) {
  return api('/admin/settings/mail-notifications', {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}
