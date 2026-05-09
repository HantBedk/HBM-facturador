/** Constantes y funciones puras del listado SAV / mantenimientos (ServicesListView). */

export const SORT_DEFAULT_DIR = {
  code: 'asc',
  service_date: 'desc',
  company_nombre: 'asc',
  client_name: 'asc',
  description: 'asc',
  user_nombre: 'asc',
  amount: 'desc',
  status: 'asc',
}

export function serviceDetailPath(id, isAdmin) {
  return isAdmin ? `/admin/servicios/${id}` : `/empleado/servicio/${id}`
}

export function serviceEditPath(id, isAdmin) {
  return isAdmin ? `/admin/servicios/${id}/editar` : `/empleado/servicio/${id}/editar`
}

export function adminInvoiceDetailPath(invoiceId) {
  return `/admin/facturas/${invoiceId}`
}

export function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return v
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

export function clip(s, n = 72) {
  if (!s) return '—'
  return s.length > n ? `${s.slice(0, n)}…` : s
}

/**
 * @param {object} s — fila servicio con `inventory_lot` opcional
 * @param {boolean} isAdmin
 */
export function rowInventoryDetailTo(s, isAdmin) {
  const il = s?.inventory_lot
  if (!il?.id) return null
  const lotId = String(il.id)
  const tenant = il.tenant_company_id
  if (tenant != null && tenant !== '') {
    if (isAdmin) {
      return {
        name: 'admin-empresa-inventario-activo-detalle',
        params: { companyId: String(tenant), lotId },
      }
    }
    return {
      name: 'empleado-inventario-activo-detalle',
      params: { lotId },
      query: { tenant_company_id: String(tenant) },
    }
  }
  if (isAdmin) return { name: 'admin-inventario-activo-detalle', params: { lotId } }
  return { name: 'empleado-inventario-activo-detalle', params: { lotId } }
}

export function parseServiceDatePeriod(iso) {
  if (!iso || typeof iso !== 'string') return null
  const m = /^(\d{4})-(\d{2})-\d{2}$/.exec(iso.trim())
  if (!m) return null
  return { year: Number(m[1]), month: Number(m[2]) }
}

/** @param {boolean} isAdmin */
export function canAdminQuickMaintenanceInvoiceDraft(s, isAdmin) {
  if (!isAdmin) return false
  if (String(s?.kind || '') !== 'mantenimiento') return false
  if (s.invoiced) return false
  if (s.status !== 'activo' && s.status !== 'corregido') return false
  if (!s.company_id) return false
  const amt = Number(s.amount)
  if (Number.isNaN(amt) || amt < 0.01) return false
  return parseServiceDatePeriod(s.service_date) != null
}

export function equipmentCellLabel(s) {
  const il = s?.inventory_lot
  if (!il) return '—'
  const name = String(il.name || '').trim() || 'Activo'
  const code = String(il.internal_code || '').trim()
  return code ? `${name} · ${code}` : name
}

export function detectSavTypeByCode(code) {
  let c0 = code
  if (typeof c0 === 'object' && c0 !== null) {
    const kind = String(c0.kind || '').toLowerCase()
    if (kind === 'mantenimiento') return 'mantenimiento'
    c0 = c0.code
  }
  const c = String(c0 || '').toUpperCase().trim()
  if (c.startsWith('VENT')) return 'venta'
  if (c.startsWith('ALQ')) return 'alquiler'
  return 'servicio'
}

export function formatServiceListDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso + (iso.length === 10 ? 'T12:00:00' : ''))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

/** Etiqueta de estado para la tabla (API usa slugs en minúsculas). */
export function estadoLabel(status) {
  const m = { activo: 'Activo', corregido: 'Corregido', eliminado: 'Eliminado' }
  return m[status] ?? status ?? '—'
}
