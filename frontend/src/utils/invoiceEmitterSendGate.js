const MISSING_LABELS = {
  nombre: 'nombre comercial o razón social',
  nit: 'NIT / identificación fiscal',
  direccion: 'dirección (al menos línea 1 o ciudad)',
  telefono: 'teléfono de contacto',
  correo: 'correo de contacto',
}

const CONFIG_ROUTE = { name: 'admin-config-system-organization' }

/**
 * Bloquea o advierte antes de enviar PDF por correo según datos de Empresa del sistema.
 *
 * @param {{ invoice?: object, uiDialog: import('@/stores/uiDialog').useUiDialogStore, router?: import('vue-router').Router }} ctx
 * @returns {Promise<boolean>} true si puede continuar con el envío
 */
export async function confirmInvoiceEmailSend({ invoice, uiDialog, router }) {
  const missing = Array.isArray(invoice?.emitter_missing) ? invoice.emitter_missing : []
  if (missing.length > 0) {
    const list = missing.map((k) => MISSING_LABELS[k] || k).join(', ')
    await uiDialog.alert({
      title: 'Datos del emisor incompletos',
      message:
        `La factura PDF usa la información de Configuración → Empresa del sistema (su empresa operadora, no el cliente). ` +
        `Faltan: ${list}. Complételos y vuelva a intentar el envío.`,
      confirmLabel: 'Entendido',
    })
    if (router) {
      await router.push(CONFIG_ROUTE)
    }
    return false
  }

  if (invoice?.emitter_logo_configured) {
    return true
  }

  const goConfig = await uiDialog.confirm({
    title: 'Sin logo en la factura',
    message:
      'No hay logo configurado en Empresa del sistema. El PDF se enviará con un espacio vacío en lugar del logo. ' +
      '¿Desea ir ahora a subir el logo?',
    confirmLabel: 'Subir logo',
    cancelLabel: 'Seguir aquí',
  })
  if (goConfig) {
    if (router) {
      await router.push({ ...CONFIG_ROUTE, query: { focus: 'logo' } })
    }
    return false
  }

  return uiDialog.confirm({
    title: 'Enviar sin logo',
    message: '¿Confirma enviar el PDF de la factura sin logo del emisor?',
    confirmLabel: 'Enviar sin logo',
    cancelLabel: 'Cancelar',
    danger: true,
  })
}

export function emitterMissingLabels(missing) {
  return (missing || []).map((k) => MISSING_LABELS[k] || k)
}
