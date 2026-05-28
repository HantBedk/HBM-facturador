/** Cálculos y formato de fecha para detalle de servicio (ServiceDetailView). */

import { rowInventoryDetailTo } from './servicesListHelpers.js'

export function formatDateLongEsCo(iso) {
  if (!iso) return '—'
  const d = new Date(iso + (iso.length <= 10 ? 'T12:00:00' : ''))
  if (Number.isNaN(d.getTime())) return iso
  return new Intl.DateTimeFormat('es-CO', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(d)
}

export function numMoneyBase(v) {
  const n = Number(v)
  return Number.isNaN(n) ? 0 : n
}

export function lineBilledAmount(it) {
  return numMoneyBase(it.amount)
}

export function lineTechnicianAmount(it) {
  if (it.technician_line_amount != null && String(it.technician_line_amount).trim() !== '') {
    return numMoneyBase(it.technician_line_amount)
  }
  return lineBilledAmount(it)
}

export function lineCompanyMargin(it) {
  return lineBilledAmount(it) - lineTechnicianAmount(it)
}

/** Ruta a detalle de lote desde fila de servicio (misma lógica que listado SAV). */
export function serviceInventoryAssetDetailTo(serviceRow, isAdmin) {
  if (!serviceRow) return null
  return rowInventoryDetailTo(serviceRow, isAdmin)
}
