/** Formato COP sin decimales; valores no numéricos → «—». Uso habitual en paneles admin. */

export function moneyCOPIntegerOrDash(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}
