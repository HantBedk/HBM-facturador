const DOCUMENT_TYPES = new Set(['CC', 'CE', 'TI', 'PASAPORTE', 'PPT', 'PT', 'PEP', 'OTRO'])

const ACCOUNT_TYPES = new Set(['ahorros', 'corriente', 'llave_breb'])

/**
 * Perfil de técnico incompleto si falta algún dato obligatorio para contacto o pago.
 * No depende solo de `perfil_completado_at` (puede quedar desfasado si se borran datos en BD).
 */
export function isEmpleadoPerfilIncomplete(user) {
  if (!user || user.rol !== 'empleado') return false
  const ok = (x) => x != null && String(x).trim() !== ''
  const nombreOk = ok(user.nombre) && String(user.nombre).trim().length >= 2
  return !(
    nombreOk &&
    ok(user.telefono) &&
    ok(user.tipo_documento) &&
    ok(user.numero_documento) &&
    ok(user.ciudad) &&
    ok(user.banco_codigo) &&
    ok(user.cuenta_tipo) &&
    ok(user.cuenta_numero)
  )
}

/**
 * Validación local alineada con `EmpleadoPerfilController::update` (Laravel).
 * Evita enviar el formulario hasta cumplir todos los requisitos; el HTML `required` solo cubre “no vacío”.
 *
 * @param {Record<string, string | null | undefined>} form
 * @param {string[]} bankCodes Códigos del catálogo (API o fallback).
 * @returns {{ ok: boolean, fieldErrors: Record<string, string[]> }}
 */
export function validateEmpleadoPerfilForm(form, bankCodes) {
  /** @type {Record<string, string[]>} */
  const fieldErrors = {}
  const push = (key, msg) => {
    if (!fieldErrors[key]) fieldErrors[key] = []
    fieldErrors[key].push(msg)
  }

  const nombre = String(form.nombre ?? '')
    .trim()
  if (nombre.length < 2) {
    push('nombre', 'Indica al menos 2 caracteres.')
  }

  const telRaw = String(form.telefono ?? '').trim()
  const digitosTel = telRaw.replace(/\D/g, '')
  if (digitosTel.length < 10) {
    push('telefono', 'Indica un número de contacto válido (mínimo 10 dígitos).')
  }

  const td = String(form.tipo_documento ?? '')
  if (!td || !DOCUMENT_TYPES.has(td)) {
    push('tipo_documento', 'Seleccione un tipo de documento.')
  }

  const nd = String(form.numero_documento ?? '').trim()
  if (nd.length < 5) {
    push('numero_documento', 'El número de documento debe tener al menos 5 caracteres.')
  } else if (!/^[A-Za-z0-9.\s\-]+$/u.test(nd)) {
    push('numero_documento', 'El formato del documento no es válido.')
  }

  const ciudad = String(form.ciudad ?? '').trim()
  if (!ciudad) {
    push('ciudad', 'La ciudad es obligatoria.')
  }

  const dep = String(form.departamento ?? '').trim()
  if (!dep) {
    push('departamento', 'Seleccione el departamento.')
  } else if (dep.length > 120) {
    push('departamento', 'Máximo 120 caracteres.')
  }

  const bc = String(form.banco_codigo ?? '')
  if (!bc) {
    push('banco_codigo', 'Seleccione una entidad financiera.')
  } else if (bankCodes.length > 0 && !bankCodes.includes(bc)) {
    push('banco_codigo', 'Seleccione una entidad válida de la lista.')
  }

  const ct = String(form.cuenta_tipo ?? '')
  if (!ct || !ACCOUNT_TYPES.has(ct)) {
    push('cuenta_tipo', 'Seleccione el tipo de cuenta o medio.')
  }

  const cn = String(form.cuenta_numero ?? '').trim()
  if (cn.length < 4) {
    push('cuenta_numero', 'Indica al menos 4 caracteres.')
  } else if (cn.length > 191) {
    push('cuenta_numero', 'Máximo 191 caracteres.')
  } else if (ct && ACCOUNT_TYPES.has(ct)) {
    if (ct === 'llave_breb') {
      if (!/^[\p{L}\p{N}@._+\-\s]+$/u.test(cn)) {
        push(
          'cuenta_numero',
          'Para Bre-B usa letras, números, espacios, @, punto, guiones o + (correo o llave).'
        )
      }
    } else if (!/^[0-9A-Za-z\-]+$/.test(cn)) {
      push('cuenta_numero', 'Solo números, letras y guiones.')
    }
  }

  const ok = Object.keys(fieldErrors).length === 0
  return { ok, fieldErrors }
}
