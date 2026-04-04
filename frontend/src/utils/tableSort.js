/**
 * Utilidades para cabeceras de tabla ordenables (cliente o servidor).
 */

export function tableSortIndicator(activeKey, dir, key) {
  if (activeKey !== key) return ''
  return dir === 'asc' ? '▲' : '▼'
}

export function tableAriaSort(activeKey, dir, key) {
  if (activeKey !== key) return 'none'
  return dir === 'asc' ? 'ascending' : 'descending'
}

/**
 * @template T
 * @param {T[]} rows
 * @param {string} key
 * @param {'asc'|'desc'} dir
 * @param {Record<string, (row: T) => string|number|null|undefined>} accessors
 * @returns {T[]}
 */
export function sortRowsCopy(rows, key, dir, accessors) {
  const acc = accessors[key]
  if (!acc || !Array.isArray(rows)) return [...(rows || [])]
  const mul = dir === 'desc' ? -1 : 1
  return [...rows].sort((a, b) => {
    const va = acc(a)
    const vb = acc(b)
    if (va == null && vb == null) return 0
    if (va == null) return 1 * mul
    if (vb == null) return -1 * mul
    if (typeof va === 'number' && typeof vb === 'number') {
      if (va < vb) return -1 * mul
      if (va > vb) return 1 * mul
      return 0
    }
    const na = Number(va)
    const nb = Number(vb)
    if (
      !Number.isNaN(na) &&
      !Number.isNaN(nb) &&
      String(va).trim() !== '' &&
      String(vb).trim() !== '' &&
      /^-?\d/.test(String(va).trim()) &&
      /^-?\d/.test(String(vb).trim())
    ) {
      if (na < nb) return -1 * mul
      if (na > nb) return 1 * mul
    }
    return String(va).localeCompare(String(vb), 'es', { sensitivity: 'base', numeric: true }) * mul
  })
}
