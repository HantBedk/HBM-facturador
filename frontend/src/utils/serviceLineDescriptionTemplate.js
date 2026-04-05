import { SERVICE_TYPE_CATALOG } from '@/constants/serviceTypeCatalog.js'

/**
 * Misma lógica que el autollenado del registro de servicio (catálogo u orientativo).
 * @param {string} label
 * @param {string|null|undefined} catalogItemDescription
 */
export function templateLineDescription(label, catalogItemDescription) {
  const d = String(catalogItemDescription || '').trim()
  const labelSafe = String(label || '').trim()
  if (d.length >= 8) return d
  return `Servicio estándar: ${labelSafe}. Detalle del trabajo realizado según visita en sitio.`
}

/**
 * Indica si la descripción de la línea coincide exactamente con la plantilla del catálogo
 * (el técnico no la personalizó).
 *
 * @param {object} row línea del formulario empleado
 * @param {Array<{ id: number, name: string, description?: string }>} catalogItems ítems de API
 */
export function isLineDescriptionStillTemplate(row, catalogItems) {
  if (!row || row.isOtherLine) return false
  const current = String(row.line_description || '').trim()
  if (!current) return false

  let expected
  if (row.catalog_id != null && row.catalog_id !== '') {
    const item = (catalogItems || []).find((c) => Number(c.id) === Number(row.catalog_id))
    if (!item) return false
    expected = templateLineDescription(item.name || row.label, item.description)
  } else {
    const lab = String(row.label || row.custom_name || '').trim()
    const staticHit = SERVICE_TYPE_CATALOG.find((c) => c.label === lab)
    if (!staticHit) return false
    expected = templateLineDescription(lab, '')
  }

  return current === expected
}
