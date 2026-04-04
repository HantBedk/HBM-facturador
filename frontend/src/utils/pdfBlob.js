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
 * Abre el PDF en una pestaña nueva (visor integrado del navegador).
 * Revoca el blob URL con retardo para que la pestaña alcance a cargar.
 * @param {Blob} blob
 */
export function openPdfBlobInNewTab(blob) {
  const url = URL.createObjectURL(blob)
  const w = window.open(url, '_blank', 'noopener,noreferrer')
  if (!w) {
    URL.revokeObjectURL(url)
    throw new Error(
      'El navegador bloqueó la ventana emergente. Permita ventanas para este sitio o use Descargar PDF.'
    )
  }
  setTimeout(() => URL.revokeObjectURL(url), 120_000)
}
