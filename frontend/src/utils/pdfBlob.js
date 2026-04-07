/**
 * @param {Blob} blob
 * @param {string} suggestedName
 */
export function triggerPdfDownload(blob, suggestedName) {
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = suggestedName
  a.rel = 'noopener'
  document.body.appendChild(a)
  a.click()
  a.remove()
  URL.revokeObjectURL(a.href)
}

/**
 * Garantiza `application/pdf` para que el visor del navegador abra el documento
 * (un Blob sin tipo suele descargarse como archivo genérico).
 * @param {Blob} blob
 * @returns {Blob}
 */
function normalizePdfBlob(blob) {
  const t = (blob.type || '').toLowerCase().split(';')[0].trim()
  if (t === 'application/pdf') return blob
  return new Blob([blob], { type: 'application/pdf' })
}

/**
 * Si no hay ventana emergente, muestra el PDF en un panel a pantalla completa (iframe).
 * @param {string} objectUrl
 */
function openPdfInOverlay(objectUrl) {
  const root = document.createElement('div')
  root.setAttribute('role', 'dialog')
  root.setAttribute('aria-modal', 'true')
  root.setAttribute('aria-label', 'Vista previa PDF')
  root.style.cssText =
    'position:fixed;inset:0;z-index:99999;background:rgba(15,23,42,.92);display:flex;flex-direction:column;'

  const bar = document.createElement('div')
  bar.style.cssText =
    'flex:0 0 auto;padding:10px 12px;display:flex;justify-content:flex-end;gap:8px;background:#0f172a;border-bottom:1px solid #334155;'

  const closeBtn = document.createElement('button')
  closeBtn.type = 'button'
  closeBtn.textContent = 'Cerrar'
  closeBtn.style.cssText =
    'padding:8px 14px;border-radius:8px;border:1px solid #475569;background:#1e293b;color:#e2e8f0;cursor:pointer;font:inherit;'

  const iframe = document.createElement('iframe')
  iframe.src = objectUrl
  iframe.title = 'Factura PDF'
  iframe.style.cssText = 'flex:1 1 auto;border:0;width:100%;min-height:0;background:#fff;'

  function cleanup() {
    URL.revokeObjectURL(objectUrl)
    root.remove()
    document.removeEventListener('keydown', onKey)
  }

  function onKey(e) {
    if (e.key === 'Escape') cleanup()
  }

  closeBtn.addEventListener('click', cleanup)
  root.addEventListener('click', (e) => {
    if (e.target === root) cleanup()
  })
  document.addEventListener('keydown', onKey)

  bar.appendChild(closeBtn)
  root.appendChild(bar)
  root.appendChild(iframe)
  document.body.appendChild(root)
}

/**
 * Abre el PDF en una pestaña nueva (visor integrado del navegador).
 * Revoca el blob URL con retardo para que la pestaña alcance a cargar.
 * @param {Blob} blob
 */
export function openPdfBlobInNewTab(blob) {
  const pdfBlob = normalizePdfBlob(blob)
  const url = URL.createObjectURL(pdfBlob)
  const w = window.open(url, '_blank', 'noopener,noreferrer')
  if (!w) {
    openPdfInOverlay(url)
    return
  }
  setTimeout(() => URL.revokeObjectURL(url), 120_000)
}
