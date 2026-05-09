/** Lógica pura del listado admin de facturas (InvoicesListView). */

import { moneyCOPIntegerOrDash } from '@/utils/moneyFormatCo.js'

export function canPayInvoiceFromList(inv) {
  return inv.status === 'enviada' || inv.status === 'parcialmente_pagada'
}

/** Saldo pendiente de cobro; en borrador/aprobada no aplica en el listado. */
export function invoiceSaldoDisplay(inv) {
  if (!['enviada', 'parcialmente_pagada', 'pagada'].includes(inv.status)) {
    return '—'
  }
  const b = inv.financial?.balance
  if (b === undefined || b === null) {
    return '—'
  }
  return moneyCOPIntegerOrDash(b)
}
