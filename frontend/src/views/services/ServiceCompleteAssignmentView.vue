<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ServiceRegisterEmpleadoForm from '@/components/services/ServiceRegisterEmpleadoForm.vue'
import {
  clearServiceDraft,
  completeServiceAssignment,
  fetchCompanies,
  fetchService,
  fetchServiceCatalogActive,
  getRecentClientNames,
  pushRecentClientName,
  rejectServiceAssignment,
} from '@/services/servicesApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'
import { isLineDescriptionStillTemplate } from '@/utils/serviceLineDescriptionTemplate.js'

const route = useRoute()
const router = useRouter()
const uiDialog = useUiDialogStore()

const serviceId = computed(() => Number(route.params.id))

const companies = ref([])
const catalogItems = ref([])
const inventoryLots = ref([])
const clientSuggestions = ref([])
const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const rejecting = ref(false)
const fieldErrors = ref({})
const globalError = ref('')
const toast = ref('')
const photoFiles = ref([])
const formResetKey = ref(0)

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
  inventory_operation_type: 'servicio',
  inventory_lot_id: '',
  inventory_quantity: 1,
  inventory_days: 1,
})

let toastTimer = null

function showToast(message) {
  toast.value = message
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => {
    toast.value = ''
  }, 3200)
}

function lineKey() {
  return `L-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

/**
 * Líneas desde el servicio asignado: el técnico debe escribir descripción e importe;
 * no precargamos el texto ni el importe mínimo que creó administración.
 */
function mapItemToLine(it) {
  const cid = it.catalog_id != null && it.catalog_id !== '' ? Number(it.catalog_id) : null
  if (cid != null && !Number.isNaN(cid)) {
    return {
      key: lineKey(),
      catalog_id: cid,
      label: it.label || 'Ítem',
      custom_name: '',
      line_description: '',
      amount: '',
      propose_catalog: false,
      isOtherLine: false,
      catalog_hint_desc: '',
      catalog_hint_price: null,
      catalog_hint_dismissed: true,
    }
  }
  return {
    key: lineKey(),
    catalog_id: null,
    label: it.label || '',
    custom_name: it.label || '',
    line_description: '',
    amount: '',
    propose_catalog: false,
    isOtherLine: true,
    catalog_hint_desc: '',
    catalog_hint_price: null,
    catalog_hint_dismissed: true,
  }
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

function lotAllowsRental(lot) {
  if (!lot) return false
  if (typeof lot.allow_rental === 'boolean') return lot.allow_rental
  const desc = String(lot.description || '')
  if (!/(disponible|etiqueta).*alquiler/i.test(desc)) return false
  return (
    flagFromDescription(desc, 'Disponible para alquiler', false) ||
    flagFromDescription(desc, 'Etiqueta para alquiler', false)
  )
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
  const opType = String(form.value.inventory_operation_type || 'servicio')
  const hasInventoryOperation = opType === 'venta' || opType === 'alquiler'
  if (!ls.length && !hasInventoryOperation) {
    e.items = ['Añade al menos un ítem del catálogo o «Otro».']
  }
  if (hasInventoryOperation) {
    const lotId = Number(form.value.inventory_lot_id)
    const qty = Number(form.value.inventory_quantity)
    if (!Number.isInteger(lotId) || lotId <= 0) {
      e.inventory_lot_id = ['Selecciona un equipo de inventario para la operación comercial.']
    }
    if (!Number.isFinite(qty) || qty < 1) {
      e.inventory_quantity = ['Indica una cantidad válida (mínimo 1).']
    }
    if (opType === 'alquiler') {
      const days = Number(form.value.inventory_days)
      if (!Number.isFinite(days) || days < 1) {
        e.inventory_days = ['Indica los días de alquiler (mínimo 1).']
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
  const skipLineDescriptionAggregate = hasInventoryOperation && ls.length === 0
  if (!skipLineDescriptionAggregate && built.length < 8) {
    e.items = e.items || [
      'Falta detalle en las líneas para armar la descripción del servicio (mín. 8 caracteres en conjunto).',
    ]
  }

  fieldErrors.value = e
  return Object.keys(e).length === 0
}

onMounted(async () => {
  clientSuggestions.value = getRecentClientNames()
  loading.value = true
  loadError.value = ''
  try {
    const [co, cat, svc] = await Promise.all([
      fetchCompanies(),
      fetchServiceCatalogActive(),
      fetchService(serviceId.value),
    ])
    companies.value = Array.isArray(co) ? co : []
    catalogItems.value = Array.isArray(cat) ? cat : []
    inventoryLots.value = []
    if (svc.assignment_status !== 'awaiting_completion') {
      loadError.value = 'Este servicio no está pendiente de completar o ya fue procesado.'
      return
    }
    form.value = {
      company_id: String(svc.company_id),
      use_quick_client: false,
      quick_telefono: '',
      catalog_id: '',
      /* Vacío: el técnico debe indicar el contacto en obra (no precargar nombre de empresa). */
      client_name: '',
      service_type: svc.service_type || '',
      description: '',
      amount: '',
      lines: Array.isArray(svc.items) ? svc.items.map(mapItemToLine) : [],
      inventory_operation_type: 'servicio',
      inventory_lot_id: '',
      inventory_quantity: 1,
      inventory_days: 1,
    }
    formResetKey.value += 1
  } catch (e) {
    loadError.value = e.data?.message || e.message || 'No se pudo cargar el servicio.'
  } finally {
    loading.value = false
  }
})

onUnmounted(() => {
  clearTimeout(toastTimer)
})

async function onRejectAssignment() {
  const ok = await uiDialog.confirm({
    title: '¿Rechazar este servicio?',
    message:
      'Si pulsaste por error, elige «No, cancelar». Si confirmas, el servicio quedará eliminado, se notificará a administración y no podrás deshacerlo desde aquí.',
    confirmLabel: 'Sí, rechazar servicio',
    cancelLabel: 'No, cancelar',
    danger: true,
  })
  if (!ok) return
  rejecting.value = true
  globalError.value = ''
  try {
    await rejectServiceAssignment(serviceId.value)
    await uiDialog.alert({ title: 'Listo', message: 'Asignación rechazada. Se notificó a administración.' })
    await router.push('/empleado/listado-servicios')
  } catch (e) {
    globalError.value = e.data?.message || e.message || 'No se pudo rechazar.'
  } finally {
    rejecting.value = false
  }
}

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

  saving.value = true
  try {
    const baseClient = form.value.client_name.trim()
    const ls = Array.isArray(form.value.lines) ? form.value.lines : []
    const opType = String(form.value.inventory_operation_type || 'servicio')
    const opItems = []
    if (opType === 'venta' || opType === 'alquiler') {
      const lotId = Number(form.value.inventory_lot_id)
      const qty = Number(form.value.inventory_quantity || 1)
      const days = Number(form.value.inventory_days || 1)
      const lot = inventoryLots.value.find((x) => Number(x.id) === lotId)
      if (lot) {
        const canSale = lotAllowsSale(lot)
        const canRental = lotAllowsRental(lot)
        if (opType === 'venta' && !canSale) {
          throw new Error('El equipo seleccionado no está habilitado para venta.')
        }
        if (opType === 'alquiler' && !canRental) {
          throw new Error('El equipo seleccionado no está habilitado para alquiler.')
        }
        const unit = Number(lot.unit_price || 0)
        const total = opType === 'alquiler' ? unit * qty * days : unit * qty
        const label = opType === 'alquiler' ? `Alquiler equipo: ${lot.name}` : `Venta equipo: ${lot.name}`
        const lotRef = lot.internal_code || lot.serial_number || (lot.id != null ? `LOT-${lot.id}` : 'sin código interno')
        const lineDesc =
          opType === 'alquiler'
            ? `Equipo ${lot.name} (${lotRef}), cantidad ${qty}, días ${days}, tarifa diaria fija ${unit.toFixed(2)}.`
            : `Equipo ${lot.name} (${lotRef}), cantidad ${qty}, precio unitario fijo ${unit.toFixed(2)}.`
        opItems.push({
          custom_name: label,
          amount: Number(total.toFixed(2)),
          custom_description: lineDesc,
          line_description: lineDesc,
        })
      }
    }
    const payload = {
      client_name: baseClient,
      service_type: form.value.service_type.trim(),
      items: [...buildPayloadItemsFromLines(ls), ...opItems],
    }

    await completeServiceAssignment(serviceId.value, payload, photoFiles.value)
    pushRecentClientName(form.value.client_name)
    clearServiceDraft()
    showToast('Servicio completado')
    await router.push({ name: 'empleado-dashboard' })
  } catch (e) {
    if (e.data?.errors) fieldErrors.value = e.data.errors
    else globalError.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="mx-auto w-full max-w-7xl px-4 pb-8 sm:px-6 lg:px-8">
    <header class="mb-6 text-center lg:text-left">
      <h1 class="text-xl font-bold tracking-tight text-white sm:text-2xl">Completar asignación</h1>
      <p class="mt-2 text-[0.8rem] leading-snug text-slate-500">
        Sustituya las líneas provisionales por el detalle real del trabajo e importes de referencia.
      </p>
    </header>

    <p v-if="loading" class="muted text-center">Cargando…</p>
    <p v-else-if="loadError" class="banner" role="alert">{{ loadError }}</p>

    <template v-else>
      <p v-if="globalError" class="banner" role="alert">{{ globalError }}</p>

      <form
        class="rounded-3xl border border-slate-800/80 bg-[#121820] p-5 shadow-xl shadow-black/30 sm:p-6"
        @submit.prevent="onSubmit"
      >
        <ServiceRegisterEmpleadoForm
          :key="formResetKey"
          v-model="form"
          v-model:photos="photoFiles"
          wide-layout
          billing-locked
          hide-submit-button
          :allow-inventory-commercial-ops="false"
          :companies="companies"
          :catalog-items="catalogItems"
          :inventory-lots="inventoryLots"
          :client-suggestions="clientSuggestions"
          :field-errors="fieldErrors"
          :disabled="saving || rejecting"
        />
        <div class="complete-actions">
          <button type="submit" class="btn-primary btn-primary--wide" :disabled="saving || rejecting">
            {{ saving ? 'Finalizando…' : 'Finalizar servicio' }}
          </button>
          <button
            type="button"
            class="btn-reject btn-reject--wide"
            :disabled="saving || rejecting"
            @click="onRejectAssignment"
          >
            {{ rejecting ? 'Rechazando…' : 'Rechazar servicio' }}
          </button>
        </div>
      </form>
    </template>

    <Teleport to="body">
      <Transition name="toast">
        <div
          v-if="toast"
          class="fixed bottom-6 right-4 z-[100] flex items-center gap-2 rounded-2xl border border-emerald-500/40 bg-[#0f1a14] px-4 py-3 text-sm font-medium text-emerald-100 shadow-lg shadow-black/40"
          role="status"
        >
          <span
            class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-500/25 text-emerald-400"
            aria-hidden="true"
            >✓</span
          >
          {{ toast }}
        </div>
      </Transition>
    </Teleport>
  </section>
</template>

<style scoped>
.muted {
  color: #64748b;
}
.banner {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}
.complete-actions {
  margin-top: 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.btn-primary {
  border-radius: 0.75rem;
  padding: 0.65rem 1.25rem;
  font-weight: 600;
  background: #0ea5e9;
  color: #0f172a;
  border: none;
  cursor: pointer;
}

.btn-primary--wide {
  width: 100%;
  padding: 0.95rem 1.25rem;
  font-size: 1rem;
  background: linear-gradient(to right, #0ea5e9, #2563eb);
  color: #f8fafc;
  box-shadow: 0 10px 25px -5px rgba(14, 165, 233, 0.35);
}

.btn-primary--wide:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.btn-primary:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.btn-reject {
  border-radius: 0.75rem;
  padding: 0.65rem 1rem;
  font-weight: 600;
  font-size: 0.9rem;
  background: transparent;
  color: #fca5a5;
  border: 1px solid rgba(248, 113, 113, 0.45);
  cursor: pointer;
  transition: background 0.15s ease;
}

.btn-reject--wide {
  width: 100%;
  padding: 0.75rem 1rem;
}

.btn-reject:hover:not(:disabled) {
  background: rgba(248, 113, 113, 0.12);
}

.btn-reject:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.toast-enter-active,
.toast-leave-active {
  transition:
    opacity 0.25s ease,
    transform 0.25s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(12px);
}
</style>
