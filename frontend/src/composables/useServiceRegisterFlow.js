import { onMounted, onUnmounted, ref, watch } from 'vue'
import {
  clearServiceDraft,
  createService,
  fetchCompanies,
  fetchServiceCatalogActive,
  getRecentClientNames,
  pushRecentClientName,
} from '@/services/servicesApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'
import { isLineDescriptionStillTemplate } from '@/utils/serviceLineDescriptionTemplate.js'

/**
 * Flujo compartido: alta de servicio por líneas (catálogo + Otro).
 * @param {{
 *   isEmpleadoRegistro: import('vue').ComputedRef<boolean>,
 *   onAdminAfterCreate: (created: { id: number|string, code?: string }) => void | Promise<void>,
 *   onEmpleadoAfterCreate?: (created: { id: number|string, code?: string }) => void | Promise<void>,
 *   panelOpenRef?: import('vue').Ref<boolean> | null,
 * }} opts
 */
export function useServiceRegisterFlow({
  isEmpleadoRegistro,
  onAdminAfterCreate,
  onEmpleadoAfterCreate,
  panelOpenRef = null,
}) {
  const uiDialog = useUiDialogStore()

  const companies = ref([])
  const catalogItems = ref([])
  const clientSuggestions = ref([])
  const loading = ref(false)
  const fieldErrors = ref({})
  const globalError = ref('')
  const toast = ref('')
  const lastCreated = ref(null)
  const formResetKey = ref(0)
  const photoFiles = ref([])

  const form = ref({
    company_id: '',
    use_quick_client: false,
    quick_telefono: '',
    catalog_id: '',
    client_name: '',
    service_type: '',
    description: '',
    amount: '',
    lines: [],
  })

  let toastTimer = null

  function showToast(message) {
    toast.value = message
    clearTimeout(toastTimer)
    toastTimer = setTimeout(() => {
      toast.value = ''
    }, 3200)
  }

  function resetFormToDefaults() {
    form.value = {
      company_id: '',
      use_quick_client: false,
      quick_telefono: '',
      catalog_id: '',
      client_name: '',
      service_type: '',
      description: '',
      amount: '',
      lines: [],
    }
    photoFiles.value = []
    formResetKey.value += 1
  }

  function digitsOnly(s) {
    return String(s ?? '').replace(/\D/g, '')
  }

  function buildServiceDescriptionFromLines(rawLines) {
    const parts = []
    for (const row of rawLines) {
      const ld = String(row.line_description || '').trim()
      if (!ld) continue
      const head =
        row.catalog_id != null && row.catalog_id !== ''
          ? String(row.label || 'Ítem').trim()
          : String(row.custom_name || '').trim() || 'Concepto'
      parts.push(`${head}: ${ld}`)
    }
    return parts.join('\n\n')
  }

  function buildPayloadItemsFromLines(rawLines) {
    return rawLines.map((row) => {
      if (row.catalog_id != null && row.catalog_id !== '') {
        const o = { catalog_id: Number(row.catalog_id), amount: Number(row.amount) }
        const d = String(row.line_description || '').trim()
        if (d) o.line_description = d
        return o
      }
      const o = {
        custom_name: String(row.custom_name || '').trim(),
        amount: Number(row.amount),
      }
      const d = String(row.line_description || '').trim()
      if (d) {
        o.custom_description = d
        o.line_description = d
      }
      return o
    })
  }

  function validateBeforeSubmit() {
    fieldErrors.value = {}
    const e = {}
    if (form.value.use_quick_client) {
      if (digitsOnly(form.value.quick_telefono).length < 7) {
        e.quick_telefono = ['Indica un teléfono con al menos 7 dígitos (identifica al cliente puntual).']
      }
    } else if (!form.value.company_id) {
      e.company_id = ['Selecciona una empresa o activa «Cliente puntual».']
    }
    if (!String(form.value.client_name || '').trim()) {
      e.client_name = ['Indica el nombre del cliente atendido.']
    }
    if (!String(form.value.service_type || '').trim()) {
      e.service_type = ['Indica el tipo de servicio (catálogo o texto libre).']
    }

    const ls = Array.isArray(form.value.lines) ? form.value.lines : []
    if (!ls.length) {
      e.items = ['Añade al menos un ítem del catálogo o «Otro».']
    }
    for (let i = 0; i < ls.length; i++) {
      const row = ls[i]
      const amt = Number(row.amount)
      if (Number.isNaN(amt) || amt < 0.01) {
        e.items = [`Revisa el importe de la línea ${i + 1}.`]
        break
      }
      if (row.catalog_id != null && row.catalog_id !== '') {
        const ld = String(row.line_description || '').trim()
        if (ld.length < 8) {
          e.items = [`Describe el trabajo en la línea ${i + 1} (mín. 8 caracteres).`]
          break
        }
      } else {
        const name = String(row.custom_name || '').trim()
        if (name.length < 2) {
          e.items = [`Indica el nombre del servicio en la línea ${i + 1} («Otro» o lista orientativa).`]
          break
        }
        const ld = String(row.line_description || '').trim()
        if (ld.length < 8) {
          e.items = [`Describe el trabajo en la línea ${i + 1} (mín. 8 caracteres).`]
          break
        }
      }
    }
    const built = buildServiceDescriptionFromLines(ls)
    if (built.length < 8) {
      e.items = e.items || ['Falta detalle en las líneas para armar la descripción del servicio (mín. 8 caracteres en conjunto).']
    }

    fieldErrors.value = e
    return Object.keys(e).length === 0
  }

  async function refreshCatalog() {
    try {
      catalogItems.value = await fetchServiceCatalogActive()
    } catch {
      catalogItems.value = []
    }
  }

  async function loadInitialData() {
    clientSuggestions.value = getRecentClientNames()
    try {
      companies.value = await fetchCompanies()
    } catch (e) {
      globalError.value = e.data?.message || 'No se pudieron cargar las empresas.'
    }
    await refreshCatalog()
  }

  onMounted(async () => {
    if (!panelOpenRef) {
      await loadInitialData()
    }
  })

  if (panelOpenRef) {
    watch(
      panelOpenRef,
      async (open) => {
        if (open) {
          globalError.value = ''
          fieldErrors.value = {}
          resetFormToDefaults()
          await loadInitialData()
        }
      },
      { flush: 'post', immediate: true }
    )
  }

  watch(
    () => form.value.company_id,
    (cid, prev) => {
      if (
        !form.value.use_quick_client &&
        prev !== undefined &&
        String(cid) !== String(prev)
      ) {
        form.value.lines = []
        form.value.amount = ''
        form.value.catalog_id = ''
      }
    }
  )

  watch(
    () => form.value.use_quick_client,
    (quick, prev) => {
      if (prev === undefined) return
      form.value.lines = []
      form.value.amount = ''
      form.value.catalog_id = ''
      if (quick) {
        form.value.company_id = ''
      } else {
        form.value.quick_telefono = ''
      }
    }
  )

  onUnmounted(() => {
    clearTimeout(toastTimer)
  })

  async function onSubmit() {
    fieldErrors.value = {}
    globalError.value = ''
    if (!validateBeforeSubmit()) return

    const lsPre = Array.isArray(form.value.lines) ? form.value.lines : []
    const anyTemplate = lsPre.some((row) => isLineDescriptionStillTemplate(row, catalogItems.value))
    if (anyTemplate) {
      const proceed = await uiDialog.confirm({
        title: 'Descripciones sin personalizar',
        message:
          'Una o más líneas siguen con el texto automático del catálogo (no lo editaste). Es mejor aclarar qué trabajo hiciste en cada concepto. ¿Deseas enviar igualmente?',
        confirmLabel: 'Enviar igual',
        cancelLabel: 'Revisar líneas',
      })
      if (!proceed) return
    }

    loading.value = true
    try {
      const baseClient = form.value.client_name.trim()
      const payload = {
        client_name: baseClient,
        service_type: form.value.service_type.trim(),
        description: form.value.description.trim(),
        amount: Number(form.value.amount),
      }
      if (form.value.use_quick_client) {
        payload.quick_client = {
          nombre: baseClient,
          telefono: String(form.value.quick_telefono || '').trim(),
        }
      } else {
        payload.company_id = Number(form.value.company_id)
      }
      const ls = Array.isArray(form.value.lines) ? form.value.lines : []
      payload.items = buildPayloadItemsFromLines(ls)
      payload.description = buildServiceDescriptionFromLines(ls)
      let t = 0
      for (const row of ls) {
        const n = Number(row.amount)
        if (!Number.isNaN(n)) t += n
      }
      payload.amount = Number(t.toFixed(2))

      const created = await createService(payload, photoFiles.value)
      pushRecentClientName(form.value.client_name)
      clearServiceDraft()

      if (isEmpleadoRegistro.value) {
        showToast('Servicio guardado')
        if (typeof onEmpleadoAfterCreate === 'function') {
          await onEmpleadoAfterCreate(created)
        } else {
          lastCreated.value = { id: created.id, code: created.code }
          resetFormToDefaults()
        }
      } else {
        await onAdminAfterCreate(created)
      }
    } catch (e) {
      if (e.data?.errors) fieldErrors.value = e.data.errors
      else globalError.value = e.data?.message || e.message || 'No se pudo guardar.'
    } finally {
      loading.value = false
    }
  }

  return {
    companies,
    catalogItems,
    clientSuggestions,
    loading,
    fieldErrors,
    globalError,
    toast,
    lastCreated,
    formResetKey,
    photoFiles,
    form,
    resetFormToDefaults,
    onSubmit,
    showToast,
  }
}
