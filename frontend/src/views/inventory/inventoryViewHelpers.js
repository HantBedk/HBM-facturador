/** Funciones puras y constantes del listado de inventario (InventoryView). */

export function readDescriptionField(description, key) {
  const text = String(description || '')
  const line = text
    .split('\n')
    .map((item) => item.trim())
    .find((item) => item.toLowerCase().startsWith(`${key.toLowerCase()}:`))
  if (!line) return ''
  return line.slice(line.indexOf(':') + 1).trim()
}

export function lotDisplayCode(row) {
  if (!row) return ''
  return (
    row.internal_code ||
    readDescriptionField(row.description, 'Codigo interno') ||
    row.serial_number ||
    (row.id != null ? `LOT-${row.id}` : '')
  )
}

export function parseDescriptionYesNoLine(description, keys) {
  const lines = String(description || '')
    .split('\n')
    .map((x) => x.trim())
    .filter(Boolean)
  for (const raw of lines) {
    const line = raw.toLowerCase()
    const hit = keys.some((k) => line.includes(String(k).toLowerCase()))
    if (!hit) continue
    const idx = raw.indexOf(':')
    const value = (idx >= 0 ? raw.slice(idx + 1) : raw).trim().toLowerCase()
    if (value.includes('no') || value === 'false' || value === '0') return false
    if (value.includes('si') || value.includes('sí') || value.includes('true') || value === '1') return true
    return true
  }
  return null
}

export function readAllowSaleFromRow(row) {
  if (typeof row.allow_sale === 'boolean') return row.allow_sale
  const desc = String(row.description || '')
  if (!/(disponible|etiqueta).*venta/i.test(desc)) return true
  const parsed = parseDescriptionYesNoLine(desc, ['Disponible para venta', 'Etiqueta para venta'])
  return parsed !== null ? parsed : true
}

export function readAllowRentalFromRow(row) {
  if (typeof row.allow_rental === 'boolean') return row.allow_rental
  const desc = String(row.description || '')
  if (!/(disponible|etiqueta).*alquiler/i.test(desc)) return false
  const parsed = parseDescriptionYesNoLine(desc, ['Disponible para alquiler', 'Etiqueta para alquiler'])
  return parsed === true
}

export function moneyCo(value) {
  const n = Number(value)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

export function formatDateTime(value) {
  if (!value) return '—'
  const dt = new Date(value)
  if (Number.isNaN(dt.getTime())) return String(value)
  return dt.toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' })
}

export function sumInventoryLineTotals(lines) {
  if (!Array.isArray(lines)) return 0
  return lines.reduce((acc, ln) => acc + Number(ln.line_total || 0), 0)
}

export function formatRentalLinesSummary(r) {
  const lines = r.lines || []
  if (!lines.length) return '—'
  return lines.map((ln) => `${ln.lot?.name || 'Equipo'} × ${ln.quantity}`).join('; ')
}

export function formatSaleLinesSummary(s) {
  const lines = s.lines || []
  if (!lines.length) return '—'
  return lines
    .map((ln) => `${ln.lot?.name || 'Equipo'} × ${ln.quantity} — ${moneyCo(ln.line_total)}`)
    .join('; ')
}

export function sortableValue(row, key) {
  if (key === 'code') return lotDisplayCode(row)
  if (key === 'brand') return row.brand || readDescriptionField(row.description, 'Marca') || ''
  if (key === 'owner') return row.owner?.nombre || ''
  if (key === 'name') return row.name || ''
  if (key === 'entry_date') return row.created_at || ''
  if (key === 'location') return row.site_label || readDescriptionField(row.description, 'Ubicacion') || ''
  if (key === 'warranty') return row.warranty_until || ''
  if (key === 'detail') return row.description || ''
  if (key === 'stock') return Number(row.quantity_available || 0)
  if (key === 'price') return Number(row.unit_price || 0)
  if (key === 'status') return row.is_active ? 1 : 0
  return ''
}

/** @param {{ key: string, dir: string }} sortSegment */
export function sortRows(rows, sortSegment, getValue) {
  const { key, dir } = sortSegment
  const d = dir === 'asc' ? 1 : -1
  const copy = [...(rows || [])]
  copy.sort((a, b) => {
    const va = getValue(a, key)
    const vb = getValue(b, key)
    if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * d
    return String(va).localeCompare(String(vb), 'es', { sensitivity: 'base', numeric: true }) * d
  })
  return copy
}

export function rentalSortValue(r, key) {
  if (key === 'id') return Number(r.id) || 0
  if (key === 'started_at') return r.started_at || ''
  if (key === 'closed_at') return r.closed_at || ''
  if (key === 'status') return r.status || ''
  if (key === 'customer_name') return r.customer_name || ''
  if (key === 'customer_phone') return r.customer_phone || ''
  if (key === 'equipos') return formatRentalLinesSummary(r)
  if (key === 'total') return sumInventoryLineTotals(r.lines)
  return ''
}

export function saleSortValue(s, key) {
  if (key === 'id') return Number(s.id) || 0
  if (key === 'created_at') return s.created_at || ''
  if (key === 'total') return Number(s.total_amount) || 0
  if (key === 'vendedor') return s.sold_by?.nombre || ''
  if (key === 'detalle') return formatSaleLinesSummary(s)
  return ''
}

export function repairSortValue(row, key) {
  if (key === 'name') return row.name || ''
  if (key === 'code') return lotDisplayCode(row)
  if (key === 'location') return row.site_label || readDescriptionField(row.description, 'Ubicacion') || ''
  if (key === 'condition') return row.physical_condition || readDescriptionField(row.description, 'Estado fisico') || ''
  if (key === 'detail') return row.description || ''
  if (key === 'status') return row.is_active ? 1 : 0
  return ''
}

export function textMatchesQuery(text, q) {
  const needle = String(q || '').trim().toLowerCase()
  if (!needle) return true
  return String(text || '').toLowerCase().includes(needle)
}

export function escapeHtml(raw) {
  return String(raw ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
}

export function humanizeAuditAction(action) {
  const key = String(action || '').toLowerCase()
  const map = {
    create: 'Registro del activo',
    import_csv_row: 'Registro del activo (importación CSV)',
    update: 'Actualización de datos',
    delete: 'Eliminación del activo',
    lifecycle_to_reparacion: 'Cambio a reparación',
    lifecycle_to_activo: 'Reactivación',
    lifecycle_baja_requested: 'Solicitud de baja',
    lifecycle_baja_approval_registered: 'Aprobación parcial de baja',
    lifecycle_baja_approved: 'Baja aprobada',
    lifecycle_baja_rejected: 'Baja rechazada',
  }
  return map[key] || key.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

export function summarizeAuditEvent(ev) {
  const out = []
  const before = ev?.before || {}
  const after = ev?.after || {}
  if (before?.lifecycle_status && after?.lifecycle_status && before.lifecycle_status !== after.lifecycle_status) {
    out.push(`Estado: ${before.lifecycle_status} -> ${after.lifecycle_status}`)
  }
  if (after?.reason) out.push(`Motivo: ${after.reason}`)
  if (after?.resolution_note) out.push(`Resolución: ${after.resolution_note}`)
  if (after?.repair_reason) out.push(`Detalle reparación: ${after.repair_reason}`)
  if (after?.repair_resolution) out.push(`Cierre reparación: ${after.repair_resolution}`)
  if (after?.decommission_reason) out.push(`Razón de baja: ${after.decommission_reason}`)
  if (after?.physical_condition) {
    if (before?.physical_condition && before.physical_condition !== after.physical_condition) {
      out.push(`Estado físico: ${before.physical_condition} -> ${after.physical_condition}`)
    } else {
      out.push(`Estado físico: ${after.physical_condition}`)
    }
  }
  if (after?.repair_damage_kind === 'hardware' || after?.repair_damage_kind === 'software') {
    out.push(after.repair_damage_kind === 'hardware' ? 'Alcance: hardware' : 'Alcance: software')
  }
  return out.join(' | ')
}

export function lotCode(row) {
  const v = lotDisplayCode(row)
  return v || '—'
}

export function generateAssetCode() {
  const now = new Date()
  const y = String(now.getUTCFullYear()).slice(-2)
  const m = String(now.getUTCMonth() + 1).padStart(2, '0')
  const d = String(now.getUTCDate()).padStart(2, '0')
  const rand = Math.random().toString(36).slice(2, 6).toUpperCase()
  return `${y}${m}${d}-${rand}`
}

export const USED_PHYSICAL_CONDITIONS = ['Excelente', 'Muy bueno', 'Bueno', 'Regular', 'Requiere mantenimiento']

/** Subcategorías por tipo (mismas etiquetas que `assetTypeList` por defecto). */
export const assetSubcategoryByType = {
  Equipo: ['Portátil', 'Desktop', 'Impresora', 'Router', 'Switch', 'Servidor', 'Otro'],
  Herramienta: ['Manual', 'Eléctrica', 'Medición', 'Seguridad', 'Otro'],
  Accesorio: ['Cableado', 'Adaptador', 'Periférico', 'Montaje', 'Otro'],
  Consumible: ['Tinta', 'Papel', 'Limpieza', 'Batería', 'Otro'],
  Mobiliario: ['Escritorio', 'Silla', 'Estantería', 'Archivador', 'Otro'],
  Otro: ['General'],
}
