/** Presentación de montos y fechas en consulta pública de facturas (OTP). */

export function publicInvoicePillClassFromStatusLabel(statusLabel) {
  const label = String(statusLabel || '').toLowerCase()
  if (label.includes('pagad')) return 'pill pill--ok'
  if (label.includes('parcial')) return 'pill pill--warn'
  return 'pill pill--neutral'
}

export function pillClassForListStatus(status) {
  if (status === 'pagada') return 'pill pill--ok'
  if (status === 'parcialmente_pagada') return 'pill pill--warn'
  return 'pill pill--neutral'
}

export function formatPublicMoney(value) {
  const n = Number(value)
  if (Number.isNaN(n)) return value
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(n)
}

export function formatPublicInvoiceDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso + (iso.length === 10 ? 'T12:00:00' : ''))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
  })
}
