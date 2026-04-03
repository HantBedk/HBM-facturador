import { defineStore } from 'pinia'

/**
 * Diálogos globales (sustituye window.confirm / alert / prompt) para evitar cuadros del navegador.
 */
export const useUiDialogStore = defineStore('uiDialog', {
  state: () => ({
    open: false,
    mode: null,
    title: '',
    message: '',
    confirmLabel: 'Aceptar',
    cancelLabel: 'Cancelar',
    placeholder: '',
    inputValue: '',
    danger: false,
    /** @type {((value: unknown) => void) | null} */
    _resolve: null,
  }),
  actions: {
    _finish() {
      this.open = false
      this.mode = null
      this.title = ''
      this.message = ''
      this.placeholder = ''
      this.inputValue = ''
      this.danger = false
      this.confirmLabel = 'Aceptar'
      this.cancelLabel = 'Cancelar'
      this._resolve = null
    },

    /**
     * @param {{ title?: string, message?: string, confirmLabel?: string, cancelLabel?: string, danger?: boolean }} [opts]
     * @returns {Promise<boolean>}
     */
    confirm(opts = {}) {
      return new Promise((resolve) => {
        this.mode = 'confirm'
        this.title = opts.title ?? 'Confirmar'
        this.message = opts.message ?? ''
        this.confirmLabel = opts.confirmLabel ?? 'Aceptar'
        this.cancelLabel = opts.cancelLabel ?? 'Cancelar'
        this.danger = Boolean(opts.danger)
        this._resolve = resolve
        this.open = true
      })
    },

    /**
     * @param {{ title?: string, message?: string, confirmLabel?: string }} [opts]
     * @returns {Promise<void>}
     */
    alert(opts = {}) {
      return new Promise((resolve) => {
        this.mode = 'alert'
        this.title = opts.title ?? 'Aviso'
        this.message = opts.message ?? ''
        this.confirmLabel = opts.confirmLabel ?? 'Entendido'
        this._resolve = resolve
        this.open = true
      })
    },

    /**
     * @param {{ title?: string, message?: string, placeholder?: string, defaultValue?: string, confirmLabel?: string, cancelLabel?: string, danger?: boolean }} [opts]
     * @returns {Promise<string|null>} texto enviado o null si canceló
     */
    prompt(opts = {}) {
      return new Promise((resolve) => {
        this.mode = 'prompt'
        this.title = opts.title ?? 'Entrada'
        this.message = opts.message ?? ''
        this.placeholder = opts.placeholder ?? ''
        this.inputValue = opts.defaultValue != null ? String(opts.defaultValue) : ''
        this.confirmLabel = opts.confirmLabel ?? 'Continuar'
        this.cancelLabel = opts.cancelLabel ?? 'Cancelar'
        this.danger = Boolean(opts.danger)
        this._resolve = resolve
        this.open = true
      })
    },

    submitConfirm() {
      const fn = this._resolve
      this._finish()
      fn?.(true)
    },

    cancelConfirm() {
      const fn = this._resolve
      this._finish()
      fn?.(false)
    },

    closeAlert() {
      const fn = this._resolve
      this._finish()
      fn?.()
    },

    submitPrompt() {
      const fn = this._resolve
      const val = this.inputValue.trim()
      this._finish()
      fn?.(val)
    },

    cancelPrompt() {
      const fn = this._resolve
      this._finish()
      fn?.(null)
    },
  },
})
