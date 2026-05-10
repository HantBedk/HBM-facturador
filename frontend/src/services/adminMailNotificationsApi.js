import { api } from '@/services/api.js'

/** @typedef {'welcome' | 'invoice_supplement' | 'maintenance_supplement'} MailTemplatePdfKind */

/** Sin desbloqueo: indica si hay SMTP panel o .env listo para enviar. */
export function fetchMailOutboundStatus() {
  return api('/admin/settings/mail-notifications/outbound-status')
}

export function fetchMailNotificationsUnlockStatus() {
  return api('/admin/settings/mail-notifications/unlock-status')
}

export function unlockMailNotificationsSettings(payload) {
  return api('/admin/settings/mail-notifications/unlock', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

export function fetchMailNotificationsSettings() {
  return api('/admin/settings/mail-notifications')
}

export function updateMailNotificationsSettings(payload) {
  return api('/admin/settings/mail-notifications', {
    method: 'PUT',
    body: JSON.stringify(payload),
  })
}

/** @param {{ to: string }} payload */
export function sendMailNotificationsTest(payload) {
  return api('/admin/settings/mail-notifications/test-send', {
    method: 'POST',
    body: JSON.stringify(payload),
  })
}

/**
 * @param {FormData} formData `kind`, `file`
 */
export function uploadMailTemplatePdf(formData) {
  return api('/admin/settings/mail-notifications/template-pdf', {
    method: 'POST',
    body: formData,
  })
}

/**
 * @param {{ kind: MailTemplatePdfKind }} payload
 */
export function deleteMailTemplatePdf(payload) {
  return api('/admin/settings/mail-notifications/template-pdf', {
    method: 'DELETE',
    body: JSON.stringify(payload),
  })
}
