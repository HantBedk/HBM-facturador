<script setup>
import { computed, onUnmounted, ref, useId, watch } from 'vue'
import { isLineDescriptionStillTemplate } from '@/utils/serviceLineDescriptionTemplate.js'

const clientListId = useId()
const photoInputId = useId()

const props = defineProps({
  modelValue: { type: Object, required: true },
  photos: { type: Array, default: () => [] },
  companies: { type: Array, default: () => [] },
  catalogItems: { type: Array, default: () => [] },
  inventoryLots: { type: Array, default: () => [] },
  clientSuggestions: { type: Array, default: () => [] },
  fieldErrors: { type: Object, default: () => ({}) },
  disabled: { type: Boolean, default: false },
  /** No permitir cambiar empresa / cliente puntual (p. ej. completar asignación administrativa). */
  billingLocked: { type: Boolean, default: false },
  /** Ocultar el botón interno de envío (p. ej. la vista padre pone su propio `type="submit"`). */
  hideSubmitButton: { type: Boolean, default: false },
  /**
   * Venta y alquiler de inventario solo en panel administración.
   * Técnicos: según permisos en configuración, o rutas dedicadas venta/alquiler.
   */
  allowInventoryCommercialOps: { type: Boolean, default: true },
  /** servicio | venta | alquiler — rutas dedicadas evitan el selector triple. */
  registerKind: { type: String, default: 'servicio' },
})

const emit = defineEmits(['update:modelValue', 'update:photos'])

const previewUrls = ref([])

function lineKey() {
  return `L-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

function lineHasTemplateOnly(idx) {
  const row = lines.value[idx]
  if (!row) return false
  return isLineDescriptionStillTemplate(row, props.catalogItems)
}

watch(
  () => props.photos,
  (files) => {
    previewUrls.value.forEach((u) => URL.revokeObjectURL(u))
    const list = Array.isArray(files) ? files : []
    previewUrls.value = list.filter((f) => f instanceof File).map((f) => URL.createObjectURL(f))
  },
  { deep: true, immediate: true }
)

function onPhotoInput(ev) {
  const input = ev.target
  const picked = [...(input.files || [])]
  input.value = ''
  const imageOnly = picked.filter((f) => f.type.startsWith('image/'))
  const next = [...props.photos, ...imageOnly].slice(0, 4)
  emit('update:photos', next)
}

function removePhoto(index) {
  emit(
    'update:photos',
    props.photos.filter((_, i) => i !== index)
  )
}

const inner = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const lines = computed(() =>
  Array.isArray(props.modelValue.lines) ? props.modelValue.lines : []
)

function patch(partial) {
  emit('update:modelValue', { ...props.modelValue, ...partial })
}

function totalFromLines(list) {
  let t = 0
  for (const row of list) {
    const n = Number(row?.amount)
    if (!Number.isNaN(n)) t += n
  }
  return t
}

function syncTypeAndAmount(list) {
  const labels = list.map((r) => (r.label || r.custom_name || '').trim()).filter(Boolean)
  const service_type = labels.length ? labels.join(' · ') : props.modelValue.service_type || ''
  return {
    lines: list,
    amount: String(totalFromLines(list).toFixed(2)),
    service_type,
  }
}

function formatHintMoneyCop(n) {
  const x = Number(n)
  if (Number.isNaN(x)) return '—'
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  }).format(x)
}

/** Una sola línea libre «Servicio» (sin catálogo) para el flujo de solo servicio. */
function createServicioLine() {
  return {
    key: lineKey(),
    catalog_id: null,
    label: 'Servicio',
    custom_name: 'Servicio',
    line_description: '',
    amount: '',
    propose_catalog: false,
    isOtherLine: true,
    catalog_hint_desc: '',
    catalog_hint_price: null,
    catalog_hint_dismissed: true,
  }
}

function removeLine(index) {
  const next = lines.value.filter((_, i) => i !== index)
  patch(syncTypeAndAmount(next))
}

function addServicioConceptLine() {
  if (props.disabled || props.billingLocked) return
  if (String(props.modelValue.inventory_operation_type || 'servicio') !== 'servicio') return
  patch(syncTypeAndAmount([...lines.value, createServicioLine()]))
}

function updateLine(index, partial) {
  const next = lines.value.map((row, i) => (i === index ? { ...row, ...partial } : row))
  if (partial.custom_name != null && next[index]) {
    next[index].label = String(partial.custom_name || '').trim()
  }
  patch(syncTypeAndAmount(next))
}

function dismissCatalogHint(index) {
  const row = lines.value[index]
  if (!row || row.catalog_hint_dismissed) return
  if (!row.catalog_hint_desc && row.catalog_hint_price == null) return
  updateLine(index, { catalog_hint_dismissed: true })
}

/** Precio máximo (COP) para líneas de catálogo: `base_price` del ítem. */
function catalogMaxPriceForRow(row) {
  if (row == null || row.catalog_id == null || row.isOtherLine) return null
  const stored = row.catalog_max_price
  if (stored != null && stored !== '') {
    const n = Number(stored)
    if (Number.isFinite(n) && n > 0) return n
  }
  const c = props.catalogItems.find((x) => Number(x.id) === Number(row.catalog_id))
  if (c != null && c.base_price != null) {
    const n = Number(c.base_price)
    if (Number.isFinite(n) && n > 0) return n
  }
  return null
}

function onLineAmountInput(index, ev) {
  const raw = ev.target.value
  const row = lines.value[index]
  const maxP = catalogMaxPriceForRow(row)
  let next = raw
  if (maxP != null && raw !== '' && String(raw).trim() !== '') {
    const n = Number(raw)
    if (!Number.isNaN(n) && n > maxP) {
      next = Number.isInteger(maxP) ? String(maxP) : String(Number(maxP.toFixed(2)))
    }
  }
  updateLine(index, { amount: next })
}

function amountInputMaxAttr(row) {
  const m = catalogMaxPriceForRow(row)
  return m != null ? m : undefined
}

onUnmounted(() => {
  previewUrls.value.forEach((u) => URL.revokeObjectURL(u))
})

const totalDisplay = computed(() => {
  const n = totalFromLines(lines.value)
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    maximumFractionDigits: 0,
  }).format(n)
})

/** Valor sentinela al final del &lt;select&gt; (no es ID de empresa). */
const QUICK_CLIENT_OPTION = '__quick_client__'

const companySelectValue = computed(() => {
  if (props.modelValue.use_quick_client) return QUICK_CLIENT_OPTION
  const id = props.modelValue.company_id
  if (id === '' || id == null) return ''
  return String(id)
})

function onCompanySelectChange(ev) {
  if (props.billingLocked) return
  const v = ev.target.value
  if (v === QUICK_CLIENT_OPTION) {
    patch({ use_quick_client: true, company_id: '' })
  } else if (v) {
    patch({ use_quick_client: false, company_id: Number(v) })
  } else {
    patch({ use_quick_client: false, company_id: '' })
  }
}

function readDescriptionField(description, key) {
  const text = String(description || '')
  const line = text
    .split('\n')
    .map((item) => item.trim())
    .find((item) => item.toLowerCase().startsWith(`${key.toLowerCase()}:`) || item.toLowerCase().includes(key.toLowerCase()))
  if (!line) return ''
  const idx = line.indexOf(':')
  if (idx >= 0) return line.slice(idx + 1).trim()
  return line.trim()
}

function parseDescriptionFlag(description, keys) {
  const lines = String(description || '')
    .split('\n')
    .map((x) => x.trim())
    .filter(Boolean)
  for (const raw of lines) {
    const line = raw.toLowerCase()
    const hit = keys.some((k) => line.includes(String(k).toLowerCase()))
    if (!hit) continue
    const idx = raw.indexOf(':')
    const value = (idx >= 0 ? raw.slice(idx + 1) : raw).trim().toLowerCase()
    if (value.includes('no') || value === 'false' || value === '0') return false
    if (value.includes('si') || value.includes('sí') || value.includes('true') || value === '1') return true
    return true
  }
  return false
}

function isEnabledForSale(row) {
  if (typeof row.allow_sale === 'boolean') return row.allow_sale
  const desc = String(row.description || '')
  if (!/(disponible|etiqueta).*venta/i.test(desc)) return true
  return parseDescriptionFlag(desc, ['Disponible para venta', 'Etiqueta para venta'])
}

function isEnabledForRental(row) {
  const desc = String(row.description || '')
  const taggedEnabled = /(disponible|etiqueta).*alquiler/i.test(desc)
    ? parseDescriptionFlag(desc, ['Disponible para alquiler', 'Etiqueta para alquiler'])
    : false
  if (typeof row.allow_rental === 'boolean') return row.allow_rental || taggedEnabled
  return taggedEnabled
}

const operationType = computed(() => String(props.modelValue.inventory_operation_type || 'servicio'))
const operationLots = computed(() => {
  if (operationType.value === 'venta') return props.inventoryLots.filter((x) => isEnabledForSale(x))
  if (operationType.value === 'alquiler') return props.inventoryLots.filter((x) => isEnabledForRental(x))
  return []
})
const selectedOperationLot = computed(() =>
  operationLots.value.find((x) => Number(x.id) === Number(props.modelValue.inventory_lot_id))
)
const operationPreview = computed(() => {
  const lot = selectedOperationLot.value
  if (!lot) return null
  const qty = Math.max(1, Number(props.modelValue.inventory_quantity || 1))
  const days = Math.max(1, Number(props.modelValue.inventory_days || 1))
  const unit = Number(lot.unit_price || 0)
  const total = operationType.value === 'alquiler' ? unit * qty * days : unit * qty
  return { unit, total, qty, days }
})

const lotPickSearchQ = ref('')
watch(
  () => props.modelValue.inventory_operation_type,
  () => {
    lotPickSearchQ.value = ''
  }
)
const filteredOperationLots = computed(() => {
  const q = lotPickSearchQ.value.trim().toLowerCase()
  const rows = operationLots.value
  if (!q) return rows
  return rows.filter((lot) => {
    const blob = `${lot.name || ''} ${lot.sku || ''}`.toLowerCase()
    return blob.includes(q)
  })
})

function setInventoryOperationType(t) {
  if (props.disabled) return
  patch({
    inventory_operation_type: t,
    inventory_lot_id: '',
    inventory_quantity: 1,
    inventory_days: 1,
  })
}

function pickInventoryLotRow(lot) {
  if (props.disabled) return
  patch({ inventory_lot_id: String(lot.id) })
}

watch(
  () => props.allowInventoryCommercialOps,
  (allow) => {
    if (!allow && props.registerKind === 'servicio') {
      patch({
        inventory_operation_type: 'servicio',
        inventory_lot_id: '',
        inventory_quantity: 1,
        inventory_days: 1,
      })
    }
  },
  { immediate: true }
)

watch(
  () => props.registerKind,
  (k) => {
    if (k === 'venta' || k === 'alquiler') {
      patch({
        inventory_operation_type: k,
        inventory_lot_id: '',
        inventory_quantity: 1,
        inventory_days: 1,
      })
    }
  },
  { immediate: true }
)

const showCommercialInventoryShell = computed(
  () =>
    props.registerKind === 'venta' ||
    props.registerKind === 'alquiler' ||
    props.allowInventoryCommercialOps
)

const showCommercialTypeSwitcher = computed(
  () => props.registerKind === 'servicio' && props.allowInventoryCommercialOps
)

const isSimpleServicioMode = computed(() => {
  if (props.billingLocked) return false
  if (String(props.modelValue.inventory_operation_type || 'servicio') !== 'servicio') return false
  const ls = lines.value
  if (ls.length !== 1) return false
  const r = ls[0]
  return (r.catalog_id == null || r.catalog_id === '') && r.isOtherLine
})

watch(
  () => [props.modelValue.inventory_operation_type, props.billingLocked],
  () => {
    if (props.billingLocked) {
      return
    }
    const op = String(props.modelValue.inventory_operation_type || 'servicio')
    if (op === 'servicio') {
      const ls = lines.value
      if (ls.length === 0) {
        patch(syncTypeAndAmount([createServicioLine()]))
      }
    } else if (lines.value.length) {
      patch(syncTypeAndAmount([]))
    }
  },
  { immediate: true }
)

watch(
  () => lines.value.length,
  (len) => {
    if (props.disabled || props.billingLocked) return
    if (String(props.modelValue.inventory_operation_type || 'servicio') !== 'servicio') return
    if (len === 0) {
      patch(syncTypeAndAmount([createServicioLine()]))
    }
  }
)
</script>

<template>
  <div class="flex flex-col gap-8">
    <!-- Paso 1 -->
    <section class="step-section" aria-labelledby="reg-svc-step1">
      <h2 id="reg-svc-step1" class="step-title">
        <span class="step-badge" aria-hidden="true">1</span>
        Empresa y cliente
      </h2>

    <!-- Empresa / cliente puntual (una sola lista) -->
    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Empresa o cliente a facturar <span class="text-red-400">*</span>
      </label>
      <div class="relative">
        <span
          class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500"
          aria-hidden="true"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path
              d="M3 21h18M5 21V7l8-4v18M13 21V11l6-3v13M9 9h.01M9 12h.01M9 15h.01"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </span>
        <select
          class="w-full appearance-none rounded-2xl border border-slate-700/90 bg-[#141a22] py-3.5 pl-12 pr-10 text-[0.9375rem] text-white outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
          :class="inner.use_quick_client ? 'border-amber-500/35' : ''"
          :value="companySelectValue"
          :disabled="disabled || billingLocked"
          @change="onCompanySelectChange"
        >
          <option value="" disabled>Seleccionar…</option>
          <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
          <option :value="QUICK_CLIENT_OPTION" class="text-amber-200">
            Cliente sin registro
          </option>
        </select>
        <span
          class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-500"
          aria-hidden="true"
        >
          <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M6 9l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </span>
      </div>
      <p v-if="fieldErrors.company_id" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.company_id[0] }}</p>
    </div>

    <!-- Cliente -->
    <div v-if="!inner.use_quick_client">
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Cliente atendido <span class="text-red-400">*</span>
      </label>
      <div class="relative">
        <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500" aria-hidden="true">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path
              d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </span>
        <input
          :value="inner.client_name"
          :list="clientListId"
          autocomplete="off"
          placeholder="Nombre del contacto en obra"
          :disabled="disabled"
          class="w-full rounded-2xl border border-slate-700/90 bg-[#141a22] py-3.5 pl-12 pr-4 text-[0.9375rem] text-white placeholder:text-slate-600 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
          @input="patch({ client_name: $event.target.value })"
        />
        <datalist :id="clientListId">
          <option v-for="s in clientSuggestions" :key="s" :value="s" />
        </datalist>
      </div>
      <p v-if="fieldErrors.client_name" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.client_name[0] }}</p>
    </div>

    <!-- Cliente puntual: nombre + teléfono -->
    <template v-else>
    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Nombre del cliente <span class="text-red-400">*</span>
      </label>
      <div class="relative">
        <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500" aria-hidden="true">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path
              d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </span>
        <input
          :value="inner.client_name"
          :list="clientListId"
          autocomplete="off"
          placeholder="Nombre del cliente"
          :disabled="disabled"
          class="w-full rounded-2xl border border-slate-700/90 bg-[#141a22] py-3.5 pl-12 pr-4 text-[0.9375rem] text-white placeholder:text-slate-600 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
          @input="patch({ client_name: $event.target.value })"
        />
        <datalist :id="clientListId">
          <option v-for="s in clientSuggestions" :key="s" :value="s" />
        </datalist>
      </div>
      <p v-if="fieldErrors.client_name" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.client_name[0] }}</p>
    </div>

    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Teléfono <span class="text-red-400">*</span>
      </label>
      <div class="relative">
        <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500" aria-hidden="true">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path
              d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </span>
        <input
          :value="inner.quick_telefono"
          type="tel"
          inputmode="tel"
          autocomplete="tel"
          placeholder="Ej. 3001234567"
          :disabled="disabled"
          class="w-full rounded-2xl border border-slate-700/90 bg-[#141a22] py-3.5 pl-12 pr-4 text-[0.9375rem] text-white placeholder:text-slate-600 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
          @input="patch({ quick_telefono: $event.target.value })"
        />
      </div>
      <p v-if="fieldErrors.quick_telefono" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.quick_telefono[0] }}</p>
    </div>
    </template>
    </section>

    <!-- Paso 2 -->
    <section class="step-section" aria-labelledby="reg-svc-step2">
      <h2 id="reg-svc-step2" class="step-title">
        <span class="step-badge" aria-hidden="true">2</span>
        <template v-if="registerKind === 'venta'">Venta de equipo</template>
        <template v-else-if="registerKind === 'alquiler'">Alquiler de equipo</template>
        <template v-else>Conceptos cobrados</template>
      </h2>

    <!-- Venta / alquiler (admin con selector, o ruta dedicada) -->
    <div
      v-if="showCommercialInventoryShell"
      class="rounded-2xl border border-slate-700/60 bg-slate-900/30 p-3"
    >
      <template v-if="showCommercialTypeSwitcher">
        <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
          Operación comercial
        </label>
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            :disabled="disabled"
            class="rounded-xl border px-3 py-2.5 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-sky-500/40 disabled:opacity-50"
            :class="
              operationType === 'servicio'
                ? 'border-sky-500 bg-sky-600/20 text-sky-100'
                : 'border-slate-700/90 bg-[#141a22] text-slate-200 hover:border-slate-600'
            "
            @click="setInventoryOperationType('servicio')"
          >
            Servicio
          </button>
          <button
            type="button"
            :disabled="disabled"
            class="rounded-xl border px-3 py-2.5 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-sky-500/40 disabled:opacity-50"
            :class="
              operationType === 'venta'
                ? 'border-sky-500 bg-sky-600/20 text-sky-100'
                : 'border-slate-700/90 bg-[#141a22] text-slate-200 hover:border-slate-600'
            "
            @click="setInventoryOperationType('venta')"
          >
            Venta de equipo
          </button>
          <button
            type="button"
            :disabled="disabled"
            class="rounded-xl border px-3 py-2.5 text-sm font-medium outline-none transition focus-visible:ring-2 focus-visible:ring-sky-500/40 disabled:opacity-50"
            :class="
              operationType === 'alquiler'
                ? 'border-sky-500 bg-sky-600/20 text-sky-100'
                : 'border-slate-700/90 bg-[#141a22] text-slate-200 hover:border-slate-600'
            "
            @click="setInventoryOperationType('alquiler')"
          >
            Alquiler de equipo
          </button>
        </div>
      </template>
      <div v-if="operationType !== 'servicio'" class="mt-3 space-y-2">
        <input
          v-model="lotPickSearchQ"
          type="search"
          autocomplete="off"
          placeholder="Buscar equipo por nombre…"
          :disabled="disabled"
          class="w-full rounded-xl border border-slate-700/90 bg-[#141a22] px-3 py-2.5 text-sm text-white placeholder:text-slate-600 outline-none focus:border-sky-500 disabled:opacity-50"
        />
        <p v-if="selectedOperationLot" class="text-xs text-emerald-400/95">
          Seleccionado: {{ selectedOperationLot.name }}
        </p>
        <ul
          class="max-h-44 divide-y divide-slate-700/50 overflow-y-auto rounded-xl border border-slate-700/80 bg-[#141a22]"
          role="listbox"
        >
          <li
            v-for="lot in filteredOperationLots"
            :key="lot.id"
            role="option"
            class="cursor-pointer px-3 py-2.5 text-sm transition hover:bg-slate-800/80"
            :class="
              Number(inner.inventory_lot_id) === Number(lot.id) ? 'bg-sky-900/35 text-sky-100' : 'text-slate-200'
            "
            @click="pickInventoryLotRow(lot)"
          >
            {{ lot.name }} · Stock {{ lot.quantity_available }} · {{ formatHintMoneyCop(lot.unit_price) }}
          </li>
        </ul>
      </div>
      <div v-if="operationType !== 'servicio'" class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label class="block text-xs text-slate-400">
          Cantidad
          <input
            :value="inner.inventory_quantity || 1"
            type="number"
            min="1"
            :disabled="disabled"
            class="mt-1 w-full rounded-xl border border-slate-700/90 bg-[#141a22] px-3 py-2 text-sm text-white outline-none focus:border-sky-500"
            @input="patch({ inventory_quantity: Number($event.target.value || 1) })"
          />
        </label>
        <label v-if="operationType === 'alquiler'" class="block text-xs text-slate-400">
          Días de alquiler
          <input
            :value="inner.inventory_days || 1"
            type="number"
            min="1"
            :disabled="disabled"
            class="mt-1 w-full rounded-xl border border-slate-700/90 bg-[#141a22] px-3 py-2 text-sm text-white outline-none focus:border-sky-500"
            @input="patch({ inventory_days: Number($event.target.value || 1) })"
          />
        </label>
      </div>
      <p v-if="fieldErrors.inventory_lot_id" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.inventory_lot_id[0] }}</p>
      <p v-if="fieldErrors.inventory_quantity" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.inventory_quantity[0] }}</p>
      <p v-if="fieldErrors.inventory_days" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.inventory_days[0] }}</p>
      <p v-if="operationType !== 'servicio' && fieldErrors.items" class="mt-2 text-sm text-red-400">
        {{ fieldErrors.items[0] }}
      </p>
    </div>

    <!-- Servicio (registro): una sola descripción + valor, sin catálogo -->
    <div
      v-if="isSimpleServicioMode && lines[0]"
      class="rounded-2xl border border-slate-700/80 bg-[#0f1419] p-4"
    >
      <h3 class="mb-3 text-sm font-semibold text-slate-200">Servicio</h3>
      <label class="mb-1 block text-[0.65rem] font-semibold uppercase tracking-wide text-slate-500">
        Descripción del trabajo <span class="text-red-400">*</span>
      </label>
      <textarea
        :value="lines[0].line_description"
        rows="4"
        :disabled="disabled"
        placeholder="Describe el trabajo realizado…"
        class="mb-4 w-full resize-y rounded-xl border border-slate-700/90 bg-[#141a22] px-3 py-2 text-sm text-white outline-none focus:border-sky-500"
        @input="updateLine(0, { line_description: $event.target.value })"
      />
      <label class="mb-1 block text-[0.65rem] font-semibold uppercase tracking-wide text-slate-500">
        Valor (COP) <span class="text-red-400">*</span>
      </label>
      <div class="relative">
        <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">$</span>
        <input
          :value="lines[0].amount"
          type="number"
          inputmode="decimal"
          min="0.01"
          step="0.01"
          :disabled="disabled"
          placeholder="Importe de referencia"
          class="w-full rounded-xl border border-slate-700/90 bg-[#141a22] py-2.5 pl-8 pr-3 text-sm text-white tabular-nums outline-none focus:border-sky-400"
          @input="onLineAmountInput(0, $event)"
          @wheel.prevent
        />
      </div>
      <p v-if="fieldErrors.items" class="mt-3 text-sm text-red-400">{{ fieldErrors.items[0] }}</p>
      <div class="mt-3 flex justify-end">
        <button
          v-if="!disabled"
          type="button"
          class="rounded-lg border border-slate-600 px-3 py-2 text-xs font-semibold text-slate-200 transition hover:border-sky-500 hover:text-sky-300"
          @click="addServicioConceptLine"
        >
          + Agregar otro concepto
        </button>
      </div>
    </div>

    <!-- Líneas (p. ej. asignación administrativa con conceptos previos) -->
    <div v-else-if="lines.length" class="flex flex-col gap-3">
      <ul class="flex flex-col gap-3">
      <li
        v-for="(row, idx) in lines"
        :key="row.key"
        class="rounded-2xl border border-slate-700/80 bg-[#0f1419] p-4"
      >
        <div class="mb-2 flex items-start justify-between gap-2">
          <div>
            <p class="text-sm font-semibold text-slate-200">
              {{
                row.catalog_id != null
                  ? row.label
                  : row.isOtherLine
                    ? 'Nuevo ítem'
                    : 'Orientativo (sin fila en catálogo servidor)'
              }}
            </p>
          </div>
          <button
            v-if="!disabled"
            type="button"
            class="shrink-0 rounded-lg px-2 py-1 text-xs font-semibold text-red-400 hover:bg-red-500/10"
            @click="removeLine(idx)"
          >
            Quitar
          </button>
        </div>
        <input
          v-if="row.catalog_id == null"
          :value="row.custom_name"
          placeholder="Nombre del servicio"
          :disabled="disabled"
          class="mb-2 w-full rounded-xl border border-slate-700/90 bg-[#141a22] px-3 py-2.5 text-sm text-white outline-none focus:border-sky-500"
          @focus="dismissCatalogHint(idx)"
          @input="updateLine(idx, { custom_name: $event.target.value })"
        />
        <label class="mb-1 block text-[0.65rem] font-semibold uppercase tracking-wide text-slate-500"
          >Qué hiciste en este concepto</label
        >
        <div
          v-if="
            !row.isOtherLine &&
            !row.catalog_hint_dismissed &&
            (row.catalog_hint_desc || row.catalog_hint_price != null)
          "
          class="catalog-hint mb-2 rounded-lg border border-slate-600/60 bg-slate-800/40 px-3 py-2"
          role="note"
        >
          <p class="mb-1 text-[0.65rem] font-semibold uppercase tracking-wide text-sky-400/90">
            {{ row.catalog_id != null ? 'Referencia del catálogo (solo guía)' : 'Lista orientativa (solo guía)' }}
          </p>
          <p v-if="row.catalog_hint_desc" class="text-[0.8rem] leading-snug text-slate-300">
            {{ row.catalog_hint_desc }}
          </p>
          <p
            v-if="row.catalog_hint_price != null"
            class="mt-1 text-[0.85rem] tabular-nums text-slate-400 line-through decoration-slate-500"
          >
            {{ formatHintMoneyCop(row.catalog_hint_price) }}
          </p>
        </div>
        <textarea
          :value="row.line_description"
          rows="2"
          :disabled="disabled"
          :placeholder="
            row.catalog_id != null ? 'Describe aquí el trabajo que hiciste en obra para este concepto…' : ''
          "
          :class="[
            'w-full resize-y rounded-xl border border-slate-700/90 bg-[#141a22] px-3 py-2 text-sm text-white outline-none focus:border-sky-500',
            lineHasTemplateOnly(idx) ? 'mb-1' : 'mb-3',
          ]"
          @focus="dismissCatalogHint(idx)"
          @input="updateLine(idx, { line_description: $event.target.value })"
        />
        <p
          v-if="lineHasTemplateOnly(idx)"
          class="mb-3 text-[0.7rem] font-medium text-amber-400/95"
          role="status"
        >
          Sigues con el texto sugerido por el catálogo. Personalízalo antes de enviar.
        </p>
        <label class="mb-1 block text-[0.65rem] font-semibold uppercase tracking-wide text-slate-500">Importe (COP)</label>
        <div class="relative">
          <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">$</span>
          <input
            :value="row.amount"
            type="number"
            inputmode="decimal"
            min="0.01"
            step="0.01"
            :max="amountInputMaxAttr(row)"
            :disabled="disabled"
            :placeholder="row.catalog_id != null ? 'Importe de referencia (obligatorio para validar)' : ''"
            class="w-full rounded-xl border border-slate-700/90 bg-[#141a22] py-2.5 pl-8 pr-3 text-sm text-white tabular-nums outline-none focus:border-sky-400"
            @focus="dismissCatalogHint(idx)"
            @input="onLineAmountInput(idx, $event)"
            @wheel.prevent
          />
        </div>
      </li>
      </ul>
      <div
        v-if="!disabled && !billingLocked && operationType === 'servicio'"
        class="flex justify-end"
      >
        <button
          type="button"
          class="rounded-lg border border-slate-600 px-3 py-2 text-xs font-semibold text-slate-200 transition hover:border-sky-500 hover:text-sky-300"
          @click="addServicioConceptLine"
        >
          + Agregar otro concepto
        </button>
      </div>
    </div>

    <p v-if="lines.length" class="rounded-2xl border border-sky-500/25 bg-sky-500/10 px-4 py-3 text-center text-sm font-semibold text-sky-100">
      Total referencia (lo que ingresas por línea): {{ totalDisplay }}
    </p>

    <!-- Resumen tipo servicio (se sincroniza con los ítems; editable) -->
    <div v-if="lines.length">
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Resumen en una línea <span class="text-red-400">*</span>
      </label>
      <input
        :value="inner.service_type"
        type="text"
        :disabled="disabled"
        class="w-full rounded-2xl border border-slate-700/90 bg-[#141a22] px-4 py-3.5 text-[0.9375rem] text-white outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
        @input="patch({ service_type: $event.target.value })"
      />
      <p v-if="fieldErrors.service_type" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.service_type[0] }}</p>
    </div>
    </section>

    <!-- Paso 3 -->
    <section class="step-section" aria-labelledby="reg-svc-step3">
      <h2 id="reg-svc-step3" class="step-title">
        <span class="step-badge" aria-hidden="true">3</span>
        Evidencias
      </h2>

    <!-- Fotos (máx. 4) -->
    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500" :for="photoInputId">
        Fotos del servicio <span class="font-normal text-slate-600">(opcional, máximo 4)</span>
      </label>
      <div class="flex flex-wrap items-center gap-3">
        <input
          :id="photoInputId"
          type="file"
          accept="image/jpeg,image/png,image/webp,image/gif"
          multiple
          class="sr-only"
          :disabled="disabled || photos.length >= 4"
          @change="onPhotoInput"
        />
        <label
          :for="photoInputId"
          class="inline-flex cursor-pointer items-center gap-2 rounded-2xl border border-dashed border-slate-600 bg-slate-900/50 px-4 py-3 text-sm font-medium text-slate-300 transition hover:border-sky-500/50 hover:bg-slate-800/50"
          :class="disabled || photos.length >= 4 ? 'pointer-events-none opacity-45' : ''"
        >
          <svg class="h-5 w-5 text-sky-400" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true">
            <path
              d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
          {{ photos.length >= 4 ? 'Límite de 4 fotos' : 'Añadir fotos' }}
        </label>
        <span class="text-xs text-slate-600">JPG, PNG, WebP o GIF · hasta 8&nbsp;MB c/u</span>
      </div>
      <p v-if="fieldErrors.photos" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.photos[0] }}</p>
      <ul v-if="previewUrls.length" class="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <li
          v-for="(url, i) in previewUrls"
          :key="url"
          class="group relative aspect-square overflow-hidden rounded-xl border border-slate-700/80 bg-slate-900"
        >
          <img :src="url" alt="" class="h-full w-full object-cover" />
          <button
            v-if="!disabled"
            type="button"
            class="absolute right-1.5 top-1.5 rounded-lg bg-black/65 px-2 py-1 text-xs font-semibold text-white opacity-0 transition group-hover:opacity-100"
            @click="removePhoto(i)"
          >
            Quitar
          </button>
        </li>
      </ul>
    </div>
    </section>

    <button
      v-if="!hideSubmitButton"
      type="submit"
      class="w-full rounded-2xl bg-gradient-to-r from-sky-500 to-blue-600 py-4 text-base font-bold text-white shadow-lg shadow-sky-500/25 transition hover:brightness-110 active:scale-[0.99] disabled:opacity-50"
      :disabled="disabled"
    >
      {{ disabled ? 'Guardando…' : 'Cargar servicio' }}
    </button>
  </div>
</template>

<style scoped>
.step-section {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  padding-bottom: 0.25rem;
  border-bottom: 1px solid rgba(51, 65, 85, 0.35);
}

.step-section:last-of-type {
  border-bottom: none;
  padding-bottom: 0;
}

.step-title {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  margin: 0;
  font-size: 0.95rem;
  font-weight: 700;
  color: #f1f5f9;
  letter-spacing: 0.02em;
}

.step-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 1.75rem;
  height: 1.75rem;
  border-radius: 0.5rem;
  font-size: 0.8rem;
  font-weight: 800;
  background: rgba(56, 189, 248, 0.15);
  color: #7dd3fc;
  border: 1px solid rgba(56, 189, 248, 0.35);
}

.step-lede {
  margin: -0.25rem 0 0;
  font-size: 0.8rem;
  line-height: 1.45;
  color: #94a3b8;
}
</style>
