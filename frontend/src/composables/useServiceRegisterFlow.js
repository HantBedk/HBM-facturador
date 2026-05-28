import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import {
  clearServiceDraft,
  createService,
  fetchCompanies,
  fetchServiceCatalogActive,
  getRecentClientNames,
  pushRecentClientName,
} from '@/services/servicesApi.js'
import {
  createInventoryRental,
  createInventorySale,
  deleteInventoryRental,
  deleteInventorySale,
  fetchEmpleadoCommercialInventorySettings,
  fetchInventoryLots,
} from '@/services/inventoryApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'
import { isLineDescriptionStillTemplate } from '@/utils/serviceLineDescriptionTemplate.js'

function inventoryLotShortRef(lot) {
  if (!lot) return 'sin código interno'
  return lot.internal_code || lot.serial_number || (lot.id != null ? `LOT-${lot.id}` : 'sin código interno')
}

/**
 * Flujo compartido: alta de servicio por líneas (catálogo + Otro).
 * @param {{
 *   isEmpleadoRegistro: import('vue').ComputedRef<boolean>,
 *   registerKind?: import('vue').Ref<string> | import('vue').ComputedRef<string>,
 *   onAdminAfterCreate: (created: { id: number|string, code?: string }) => void | Promise<void>,
 *   onEmpleadoAfterCreate?: (created: { id: number|string, code?: string }) => void | Promise<void>,
 *   panelOpenRef?: import('vue').Ref<boolean> | null,
 * }} opts
 */
export function useServiceRegisterFlow({
  isEmpleadoRegistro,
  registerKind: registerKindOption = null,
  onAdminAfterCreate,
  onEmpleadoAfterCreate,
  panelOpenRef = null,
}) {
  const uiDialog = useUiDialogStore()
  const route = useRoute()

  const registerKind = registerKindOption ?? ref('servicio')

  const empleadoCommercialVentaEnabled = ref(false)
  const empleadoCommercialAlquilerEnabled = ref(false)

  const allowInventoryCommercialOps = computed(() => {
    if (!isEmpleadoRegistro.value) return true
    const k = String(registerKind.value || 'servicio')
    if (k === 'servicio') return false
    if (k === 'venta') return empleadoCommercialVentaEnabled.value
    if (k === 'alquiler') return empleadoCommercialAlquilerEnabled.value
    if (k === 'mantenimiento') return false
    return false
  })

  const companies = ref([])
  const catalogItems = ref([])
  const inventoryLots = ref([])
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
    catalog_id: '',
    client_name: '',
    service_type: '',
    description: '',
    amount: '',
    lines: [],
    inventory_operation_type: 'servicio',
    inventory_lot_id: '',
    inventory_quantity: 1,
    inventory_days: 1,
    inventory_sale_items: [],
    inventory_rental_items: [],
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
    const k = String(registerKind.value || 'servicio')
    const invOp = k === 'venta' || k === 'alquiler' ? k : 'servicio'
    const serviceTypeDefault =
      k === 'venta'
        ? 'Venta de equipo'
        : k === 'alquiler'
          ? 'Alquiler de equipo'
          : k === 'mantenimiento'
            ? 'Mantenimiento'
            : ''
    form.value = {
      company_id: '',
      catalog_id: '',
      client_name: '',
      service_type: serviceTypeDefault,
      description: '',
      amount: '',
      lines: [],
      inventory_operation_type: invOp,
      inventory_lot_id: '',
      inventory_quantity: 1,
      inventory_days: 1,
      inventory_sale_items: [],
      inventory_rental_items: [],
    }
    photoFiles.value = []
    formResetKey.value += 1
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
    const rk = String(registerKind.value || 'servicio')
    if (!form.value.company_id) {
      e.company_id = ['Selecciona una empresa registrada.']
    }
    if (!String(form.value.client_name || '').trim()) {
      e.client_name = ['Indica el nombre del cliente atendido.']
    }
    if ((rk === 'venta' || rk === 'alquiler') && !String(form.value.service_type || '').trim()) {
      form.value.service_type = rk === 'venta' ? 'Venta de equipo' : 'Alquiler de equipo'
    }
    if (rk === 'mantenimiento' && !String(form.value.service_type || '').trim()) {
      form.value.service_type = 'Mantenimiento'
    }
    if (!String(form.value.service_type || '').trim()) {
      e.service_type = ['Indica el tipo de servicio (catálogo o texto libre).']
    }

    const ls = Array.isArray(form.value.lines) ? form.value.lines : []
    const opType = String(form.value.inventory_operation_type || 'servicio')
    const hasInventoryOperation = opType === 'venta' || opType === 'alquiler'
    if (!ls.length && !hasInventoryOperation) {
      e.items = ['Añade al menos un ítem del catálogo o «Otro».']
    }
    if (hasInventoryOperation) {
      if (opType === 'venta') {
        const saleItems = Array.isArray(form.value.inventory_sale_items) ? form.value.inventory_sale_items : []
        if (!saleItems.length) {
          e.inventory_lot_id = ['Agrega al menos un producto para registrar la venta.']
        } else {
          const invalidQty = saleItems.some((item) => !Number.isFinite(Number(item?.quantity)) || Number(item?.quantity) < 1)
          const invalidLot = saleItems.some((item) => !Number.isInteger(Number(item?.lot_id)) || Number(item?.lot_id) <= 0)
          if (invalidLot) {
            e.inventory_lot_id = ['Hay productos inválidos en la lista seleccionada.']
          }
          if (invalidQty) {
            e.inventory_quantity = ['Cada producto debe tener una cantidad válida (mínimo 1).']
          }
        }
      } else if (opType === 'alquiler') {
        const rentalItems = Array.isArray(form.value.inventory_rental_items) ? form.value.inventory_rental_items : []
        if (!rentalItems.length) {
          e.inventory_lot_id = ['Agrega al menos un equipo para registrar el alquiler.']
        } else {
          const invalidQty = rentalItems.some(
            (item) => !Number.isFinite(Number(item?.quantity)) || Number(item?.quantity) < 1
          )
          const invalidLot = rentalItems.some(
            (item) => !Number.isInteger(Number(item?.lot_id)) || Number(item?.lot_id) <= 0
          )
          const invalidDays = rentalItems.some((item) => !Number.isFinite(Number(item?.days)) || Number(item?.days) < 1)
          if (invalidLot) {
            e.inventory_lot_id = ['Hay equipos inválidos en la lista seleccionada.']
          }
          if (invalidQty) {
            e.inventory_quantity = ['Cada equipo debe tener una cantidad válida (mínimo 1).']
          }
          if (invalidDays) {
            e.inventory_days = ['Cada equipo debe tener días de alquiler válidos (mínimo 1).']
          }
        }
      }
    }
    if (rk === 'mantenimiento') {
      const lotId = Number(form.value.inventory_lot_id)
      if (!Number.isInteger(lotId) || lotId <= 0) {
        e.inventory_lot_id = ['Seleccione el equipo a intervenir para el mantenimiento.']
      } else {
        const lot = inventoryLots.value.find((x) => Number(x.id) === lotId)
        if (!lot) {
          e.inventory_lot_id = ['El equipo seleccionado no existe en el inventario cargado.']
        } else if (Number(lot.tenant_company_id || 0) !== Number(form.value.company_id || 0)) {
          e.inventory_lot_id = ['El equipo no pertenece a la empresa seleccionada.']
        }
      }
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
    const skipLineDescriptionAggregate =
      hasInventoryOperation && ls.length === 0
    if (!skipLineDescriptionAggregate && built.length < 8) {
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

  function parseDescriptionField(description, key) {
    const line = String(description || '')
      .split('\n')
      .map((item) => item.trim())
      .find((item) => item.toLowerCase().startsWith(`${key.toLowerCase()}:`) || item.toLowerCase().includes(key.toLowerCase()))
    if (!line) return ''
    const idx = line.indexOf(':')
    if (idx >= 0) return line.slice(idx + 1).trim()
    return line.trim()
  }

  function flagFromDescription(description, key, fallback = false) {
    const lines = String(description || '')
      .split('\n')
      .map((x) => x.trim())
      .filter(Boolean)
    const target = String(key).toLowerCase()
    const lineRaw = lines.find((ln) => ln.toLowerCase().includes(target))
    if (!lineRaw) return fallback
    const idx = lineRaw.indexOf(':')
    const value = (idx >= 0 ? lineRaw.slice(idx + 1) : lineRaw).trim().toLowerCase()
    if (!value) return true
    if (value.includes('no') || value === 'false' || value === '0') return false
    if (value.includes('si') || value.includes('sí') || value.includes('true') || value === '1') return true
    return true
  }

  function lotAllowsSale(lot) {
    if (!lot) return false
    if (typeof lot.allow_sale === 'boolean') return lot.allow_sale
    const desc = String(lot.description || '')
    if (!/(disponible|etiqueta).*venta/i.test(desc)) return true
    return (
      flagFromDescription(desc, 'Disponible para venta', false) ||
      flagFromDescription(desc, 'Etiqueta para venta', false)
    )
  }

  /** Query params para inventario interno vs por empresa cliente. */
  function buildInventoryQueryFromLots(lots) {
    const ids = [...new Set(lots.map((l) => (l.tenant_company_id != null ? String(l.tenant_company_id) : 'internal')))]
    if (ids.length > 1) {
      throw new Error('No puede combinar inventario de distintas empresas en una sola operación.')
    }
    if (ids[0] === 'internal') return { tenant_scope: 'internal' }
    return { tenant_company_id: ids[0] }
  }

  function lotAllowsRental(lot) {
    if (!lot) return false
    const desc = String(lot.description || '')
    const taggedEnabled = /(disponible|etiqueta).*alquiler/i.test(desc) && (
      flagFromDescription(desc, 'Disponible para alquiler', false) ||
      flagFromDescription(desc, 'Etiqueta para alquiler', false)
    )
    if (typeof lot.allow_rental === 'boolean') return lot.allow_rental || taggedEnabled
    return taggedEnabled
  }

  async function refreshInventoryLots() {
    const k = String(registerKind.value || 'servicio')
    const needLots =
      !isEmpleadoRegistro.value || k === 'venta' || k === 'alquiler' || k === 'mantenimiento'
    if (!needLots) {
      inventoryLots.value = []
      return
    }
    try {
      const params = { per_page: 200 }
      if (k === 'mantenimiento' && form.value.company_id) {
        params.tenant_company_id = String(form.value.company_id)
        if (isEmpleadoRegistro.value) {
          params.for_maintenance = '1'
        }
      } else if (isEmpleadoRegistro.value) {
        params.active_only = '1'
      }
      const res = await fetchInventoryLots(params)
      inventoryLots.value = res.data || []
    } catch {
      inventoryLots.value = []
    }
  }

  async function loadInitialData() {
    globalError.value = ''
    clientSuggestions.value = getRecentClientNames()
    try {
      companies.value = await fetchCompanies()
    } catch (e) {
      globalError.value = e.data?.message || 'No se pudieron cargar las empresas.'
    }
    if (isEmpleadoRegistro.value) {
      try {
        const r = await fetchEmpleadoCommercialInventorySettings()
        const d = r?.data ?? {}
        empleadoCommercialVentaEnabled.value = Boolean(d.venta_enabled)
        empleadoCommercialAlquilerEnabled.value = Boolean(d.alquiler_enabled)
      } catch {
        empleadoCommercialVentaEnabled.value = false
        empleadoCommercialAlquilerEnabled.value = false
      }
      const k = String(registerKind.value || 'servicio')
      if (k === 'venta' && !empleadoCommercialVentaEnabled.value) {
        globalError.value =
          'Administración no ha habilitado el registro de ventas de inventario para técnicos.'
      } else if (k === 'alquiler' && !empleadoCommercialAlquilerEnabled.value) {
        globalError.value =
          'Administración no ha habilitado el registro de alquileres de inventario para técnicos.'
      }
    }
    await refreshCatalog()
    await refreshInventoryLots()
  }

  /** Desde hoja de vida (custodia): ?company_id=&inventory_lot_id= */
  async function applyMaintenanceQueryFromRoute() {
    const k = String(registerKind.value || 'servicio')
    if (k !== 'mantenimiento') return
    const q = route.query || {}
    const rawCid = q.company_id ?? q.tenant_company_id
    const rawLid = q.inventory_lot_id ?? q.lot_id
    if (!rawCid && !rawLid) return
    if (rawCid) {
      const n = Number(rawCid)
      if (Number.isInteger(n) && n > 0) form.value.company_id = String(n)
    }
    if (rawCid) await refreshInventoryLots()
    if (rawLid) {
      const n = Number(rawLid)
      if (Number.isInteger(n) && n > 0) form.value.inventory_lot_id = String(n)
    }
  }

  onMounted(async () => {
    if (!panelOpenRef) {
      await loadInitialData()
      await applyMaintenanceQueryFromRoute()
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
          await applyMaintenanceQueryFromRoute()
        }
      },
      { flush: 'post', immediate: true }
    )
  }

  watch(
    () => String(registerKind.value || 'servicio'),
    async (k, prev) => {
      if (panelOpenRef) return
      if (prev === undefined) return
      globalError.value = ''
      fieldErrors.value = {}
      resetFormToDefaults()
      await loadInitialData()
    }
  )

  watch(
    () => form.value.company_id,
    (cid, prev) => {
      if (prev !== undefined && String(cid) !== String(prev)) {
        form.value.lines = []
        form.value.amount = ''
        form.value.catalog_id = ''
      }
    }
  )

  watch(
    () => form.value.company_id,
    async (cid, prev) => {
      if (prev === undefined) return
      if (String(registerKind.value || 'servicio') !== 'mantenimiento') return
      await refreshInventoryLots()
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
      if (String(registerKind.value || 'servicio') === 'mantenimiento') {
        payload.kind = 'mantenimiento'
        payload.inventory_lot_id = Number(form.value.inventory_lot_id)
      }
      payload.company_id = Number(form.value.company_id)
      const ls = Array.isArray(form.value.lines) ? form.value.lines : []
      const opType = String(form.value.inventory_operation_type || 'servicio')
      if (isEmpleadoRegistro.value) {
        if (opType === 'venta' && !empleadoCommercialVentaEnabled.value) {
          globalError.value =
            'No tiene permiso para registrar ventas de inventario. Solicite la habilitación en administración.'
          loading.value = false
          return
        }
        if (opType === 'alquiler' && !empleadoCommercialAlquilerEnabled.value) {
          globalError.value =
            'No tiene permiso para registrar alquileres de inventario. Solicite la habilitación en administración.'
          loading.value = false
          return
        }
      }
      const opItems = []
      if (opType === 'venta' || opType === 'alquiler') {
        if (opType === 'venta') {
          const saleItems = Array.isArray(form.value.inventory_sale_items) ? form.value.inventory_sale_items : []
          for (const saleItem of saleItems) {
            const lotId = Number(saleItem?.lot_id)
            const qty = Number(saleItem?.quantity || 1)
            const lot = inventoryLots.value.find((x) => Number(x.id) === lotId)
            if (!lot) continue
            if (!lotAllowsSale(lot)) {
              throw new Error(`El equipo «${lot.name}» no está habilitado para venta.`)
            }
            const unit = Number(lot.unit_price || 0)
            const total = unit * qty
            const lineDesc = `Equipo ${lot.name} (${inventoryLotShortRef(lot)}), cantidad ${qty}, precio unitario fijo ${unit.toFixed(2)}.`
            opItems.push({
              custom_name: `Venta equipo: ${lot.name}`,
              amount: Number(total.toFixed(2)),
              custom_description: lineDesc,
              line_description: lineDesc,
            })
          }
        } else {
          const rentalItems = Array.isArray(form.value.inventory_rental_items) ? form.value.inventory_rental_items : []
          for (const ri of rentalItems) {
            const lotId = Number(ri?.lot_id)
            const qty = Number(ri?.quantity || 1)
            const days = Number(ri?.days || 1)
            const lot = inventoryLots.value.find((x) => Number(x.id) === lotId)
            if (!lot) continue
            if (!lotAllowsRental(lot)) {
              throw new Error(`El equipo «${lot.name}» no está habilitado para alquiler.`)
            }
            const unit = Number(lot.unit_price || 0)
            const total = unit * qty * days
            const lineDesc = `Equipo ${lot.name} (${inventoryLotShortRef(lot)}), cantidad ${qty}, días ${days}, tarifa diaria fija ${unit.toFixed(2)}.`
            opItems.push({
              custom_name: `Alquiler equipo: ${lot.name}`,
              amount: Number(total.toFixed(2)),
              custom_description: lineDesc,
              line_description: lineDesc,
            })
          }
        }
      }

      const mergedLines = [...ls]
      payload.items = [...buildPayloadItemsFromLines(mergedLines), ...opItems]
      payload.description = buildServiceDescriptionFromLines(mergedLines)
      if (!payload.description && opItems.length) {
        payload.description = opItems.map((it) => it.line_description).join('\n\n')
      }
      let t = 0
      for (const row of ls) {
        const n = Number(row.amount)
        if (!Number.isNaN(n)) t += n
      }
      for (const it of opItems) {
        const n = Number(it.amount)
        if (!Number.isNaN(n)) t += n
      }
      payload.amount = Number(t.toFixed(2))

      /** Primero descuenta stock vía API de inventario; si falla el servicio, revierte la venta/alquiler. */
      let inventoryRollback = null
      if (opType === 'venta') {
        const saleItems = Array.isArray(form.value.inventory_sale_items) ? form.value.inventory_sale_items : []
        const lotsUsed = saleItems
          .map((si) => inventoryLots.value.find((x) => Number(x.id) === Number(si.lot_id)))
          .filter(Boolean)
        if (!lotsUsed.length) {
          throw new Error('No hay equipos válidos para registrar la venta en inventario.')
        }
        const q = buildInventoryQueryFromLots(lotsUsed)
        const invRes = await createInventorySale(
          {
            lines: saleItems.map((si) => ({
              inventory_lot_id: Number(si.lot_id),
              quantity: Number(si.quantity || 1),
            })),
            notes: String(form.value.description || '').trim() || null,
          },
          q
        )
        const sid = invRes?.data?.id ?? invRes?.id
        if (!sid) throw new Error('Respuesta inválida al registrar la venta de inventario.')
        inventoryRollback = { type: 'sale', id: sid, query: q }
      } else if (opType === 'alquiler') {
        const rentalItems = Array.isArray(form.value.inventory_rental_items) ? form.value.inventory_rental_items : []
        const lotsUsed = rentalItems
          .map((ri) => inventoryLots.value.find((x) => Number(x.id) === Number(ri.lot_id)))
          .filter(Boolean)
        if (!lotsUsed.length) {
          throw new Error('No hay equipos válidos para registrar el alquiler en inventario.')
        }
        const q = buildInventoryQueryFromLots(lotsUsed)
        const invRes = await createInventoryRental(
          {
            lines: rentalItems.map((ri) => ({
              inventory_lot_id: Number(ri.lot_id),
              quantity: Number(ri.quantity || 1),
              rental_days: Number(ri.days || 1),
            })),
            customer_name: baseClient,
            customer_phone: '',
            notes: String(form.value.description || '').trim() || null,
          },
          q
        )
        const rid = invRes?.data?.id ?? invRes?.id
        if (!rid) throw new Error('Respuesta inválida al registrar el alquiler de inventario.')
        inventoryRollback = { type: 'rental', id: rid, query: q }
      }

      let created
      try {
        const photosToSend =
          opType === 'venta' || opType === 'alquiler' ? [] : photoFiles.value
        created = await createService(payload, photosToSend)
      } catch (svcErr) {
        if (inventoryRollback?.type === 'sale') {
          await deleteInventorySale(inventoryRollback.id, inventoryRollback.query).catch(() => {})
        } else if (inventoryRollback?.type === 'rental') {
          await deleteInventoryRental(inventoryRollback.id, inventoryRollback.query).catch(() => {})
        }
        throw svcErr
      }

      pushRecentClientName(form.value.client_name)
      clearServiceDraft()

      if (opType === 'venta' || opType === 'alquiler') {
        await refreshInventoryLots()
      }

      if (isEmpleadoRegistro.value) {
        const okMsg =
          opType === 'venta'
            ? 'Venta registrada'
            : opType === 'alquiler'
              ? 'Alquiler registrado'
              : 'Servicio guardado'
        showToast(okMsg)
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
    inventoryLots,
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
    registerKind,
    allowInventoryCommercialOps,
    empleadoCommercialVentaEnabled,
    empleadoCommercialAlquilerEnabled,
  }
}
