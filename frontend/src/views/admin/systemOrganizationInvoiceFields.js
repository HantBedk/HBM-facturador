import { emitterMissingLabels } from '@/utils/invoiceEmitterSendGate.js'

/** Campos del emisor que aparecen en el PDF (empresa operadora). */
export const INVOICE_EMITTER_FIELD_HINTS = [
  { key: 'nombre', label: 'Nombre comercial o razón social', check: (d) => !!(d.trade_name?.trim() || d.legal_name?.trim()) },
  { key: 'nit', label: 'NIT / identificación fiscal', check: (d) => !!d.nit?.trim() },
  {
    key: 'direccion',
    label: 'Dirección',
    check: (d) =>
      !!(
        d.address_line1?.trim() ||
        d.address_line2?.trim() ||
        d.city?.trim() ||
        d.department?.trim()
      ),
  },
  {
    key: 'telefono',
    label: 'Teléfono',
    check: (d) => !!(d.phone?.trim() || d.phone_secondary?.trim()),
  },
  { key: 'correo', label: 'Correo de contacto', check: (d) => !!d.email?.trim() },
]

export function invoiceEmitterChecklistFromForm(form) {
  return INVOICE_EMITTER_FIELD_HINTS.map((f) => ({
    key: f.key,
    label: f.label,
    ok: f.check(form),
  }))
}

export { emitterMissingLabels }
