<script setup>
import { computed, onUnmounted, ref, useId, watch } from 'vue'
import { isLineDescriptionStillTemplate, templateLineDescription } from '@/utils/serviceLineDescriptionTemplate.js'

const clientListId = useId()
const photoInputId = useId()

const props = defineProps({
  modelValue: { type: Object, required: true },
  photos: { type: Array, default: () => [] },
  companies: { type: Array, default: () => [] },
  catalogItems: { type: Array, default: () => [] },
  clientSuggestions: { type: Array, default: () => [] },
  fieldErrors: { type: Object, default: () => ({}) },
  disabled: { type: Boolean, default: false },
  /** No permitir cambiar empresa / cliente puntual (p. ej. completar asignación administrativa). */
  billingLocked: { type: Boolean, default: false },
  /** Ocultar el botón interno de envío (p. ej. la vista padre pone su propio `type="submit"`). */
  hideSubmitButton: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'update:photos'])

const previewUrls = ref([])

function lineKey() {
  return `L-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

function descFromCatalog(item) {
  const label = item.label || item.name
  return templateLineDescription(label, item.description)
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

/** Panel del catálogo en flujo normal (no flotante): evita cortes por overflow y clics fuera. */
const catalogPanelOpen = ref(false)
const typeFilter = ref('')
const catalogSearchInputRef = ref(null)

function afterLineAdded() {
  typeFilter.value = ''
  catalogPanelOpen.value = true
}

function toggleCatalogPanel() {
  const canBill =
    props.modelValue.use_quick_client || (props.modelValue.company_id !== '' && props.modelValue.company_id != null)
  if (props.disabled || !canBill) return
  catalogPanelOpen.value = !catalogPanelOpen.value
  if (catalogPanelOpen.value) typeFilter.value = ''
}

watch(
  () => [props.modelValue.company_id, props.modelValue.use_quick_client],
  () => {
    catalogPanelOpen.value = false
    typeFilter.value = ''
  }
)

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

function addCatalogLine(item) {
  const label = item.label || item.name
  const cid = item.catalog_id != null && item.catalog_id !== '' ? Number(item.catalog_id) : null
  const baseRaw = item.basePrice ?? item.base_price ?? ''
  const hintPrice =
    baseRaw !== '' && baseRaw != null && !Number.isNaN(Number(baseRaw)) ? Number(baseRaw) : null
  const hintDesc = descFromCatalog(item)
  let row
  if (cid != null && !Number.isNaN(cid)) {
    row = {
      key: lineKey(),
      catalog_id: cid,
      label,
      custom_name: '',
      line_description: '',
      amount: '',
      propose_catalog: false,
      isOtherLine: false,
      catalog_hint_desc: hintDesc,
      catalog_hint_price: hintPrice,
      catalog_hint_dismissed: false,
    }
  } else {
    row = {
      key: lineKey(),
      catalog_id: null,
      label,
      custom_name: label,
      line_description: '',
      amount: '',
      propose_catalog: false,
      isOtherLine: false,
      catalog_hint_desc: hintDesc || `Referencia orientativa: ${String(label).trim()}`,
      catalog_hint_price: hintPrice,
      catalog_hint_dismissed: false,
    }
  }
  patch(syncTypeAndAmount([...lines.value, row]))
  afterLineAdded()
}

function addOtherLine() {
  const row = {
    key: lineKey(),
    catalog_id: null,
    label: '',
    custom_name: '',
    line_description: '',
    amount: '',
    propose_catalog: false,
    isOtherLine: true,
    catalog_hint_desc: '',
    catalog_hint_price: null,
    catalog_hint_dismissed: true,
  }
  patch(syncTypeAndAmount([...lines.value, row]))
  afterLineAdded()
}

function removeLine(index) {
  const next = lines.value.filter((_, i) => i !== index)
  patch(syncTypeAndAmount(next))
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

/** Solo ítems que devuelve el servidor. Si el admin vació el catálogo, no se muestra lista orientativa local. */
const effectiveTypeCatalog = computed(() =>
  props.catalogItems.map((c) => ({
    id: `db-${c.id}`,
    label: c.name,
    basePrice: Number(c.base_price),
    description: c.description,
    catalog_id: c.id,
    isOther: false,
  }))
)

const catalogWithOther = computed(() => [
  ...effectiveTypeCatalog.value,
  { id: '__otro__', label: 'Otro…', isOther: true, catalog_id: null, basePrice: 0, description: '' },
])

const filteredCatalog = computed(() => {
  const q = typeFilter.value.trim().toLowerCase()
  const list = catalogWithOther.value
  if (!q) return list
  const hit = list.filter(
    (i) =>
      (i.label || '').toLowerCase().includes(q) ||
      String(i.id).toLowerCase().includes(q)
  )
  // Si el texto no coincide con nada, mostrar todo el catálogo para no dejar el menú vacío
  return hit.length ? hit : list
})

function pickCatalogRow(item) {
  if (item.isOther) {
    addOtherLine()
    return
  }
  addCatalogLine(item)
}

function onTypeInput(e) {
  typeFilter.value = e.target.value
  if (props.modelValue.company_id || props.modelValue.use_quick_client) catalogPanelOpen.value = true
}

function onTypeFocus() {
  if (!props.modelValue.company_id && !props.modelValue.use_quick_client) return
  catalogPanelOpen.value = true
  if (lines.value.length > 0) typeFilter.value = ''
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
</script>

<template>
  <div class="flex flex-col gap-8">
    <!-- Paso 1 -->
    <section class="step-section" aria-labelledby="reg-svc-step1">
      <h2 id="reg-svc-step1" class="step-title">
        <span class="step-badge" aria-hidden="true">1</span>
        Empresa y cliente
      </h2>
      <p class="step-lede">
        <template v-if="billingLocked">
          Empresa y cliente fijados por la asignación administrativa; complete conceptos e importes abajo.
        </template>
        <template v-else>
          Elige la empresa en el listado; al final puedes indicar cliente puntual si no está registrado en el sistema.
        </template>
      </p>

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
      <p v-if="inner.use_quick_client" class="mt-2 text-[0.75rem] leading-snug text-amber-500/90">
        Mismo teléfono agrupa servicios de este cliente para facturación.
      </p>
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
        Conceptos cobrados
      </h2>
        <p class="step-lede">
        Añade cada trabajo desde el catálogo de la empresa o «Otro…». En ítems del catálogo verás texto y precio solo como
        referencia: debes describir el trabajo y el importe queda según las reglas de facturación del sistema.
      </p>

    <!-- Catálogo: panel inline (no desplegable flotante) -->
    <div class="rounded-2xl border border-slate-700/60 bg-slate-900/30 p-3">
      <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Añadir desde catálogo <span class="text-red-400">*</span>
      </label>
      <div class="flex flex-col gap-2 sm:flex-row sm:items-stretch sm:gap-2">
        <button
          type="button"
          class="flex min-h-[3.25rem] shrink-0 cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-sky-500/55 bg-sky-500/20 px-4 py-2 text-sm font-bold text-sky-100 shadow-inner shadow-sky-950/30 transition hover:bg-sky-500/30 active:scale-[0.99] disabled:pointer-events-none disabled:opacity-40 sm:min-w-[10.5rem]"
          :disabled="disabled || (!inner.company_id && !inner.use_quick_client)"
          :aria-expanded="catalogPanelOpen"
          aria-controls="catalog-panel-list"
          @click="toggleCatalogPanel"
        >
          <span class="text-2xl font-light leading-none" aria-hidden="true">+</span>
          <span>{{ catalogPanelOpen ? 'Ocultar catálogo' : 'Ver catálogo' }}</span>
        </button>
        <div class="relative min-w-0 flex-1">
          <span class="pointer-events-none absolute left-3.5 top-1/2 z-[1] -translate-y-1/2 text-slate-500" aria-hidden="true">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
              <path
                d="M21 21l-4.35-4.35M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16z"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
            </svg>
          </span>
          <input
            id="catalog-filter-input"
            ref="catalogSearchInputRef"
            :value="typeFilter"
            type="text"
            autocomplete="off"
            placeholder="Filtrar lista (opcional)"
            :disabled="disabled || (!inner.company_id && !inner.use_quick_client)"
            class="h-[3.25rem] w-full rounded-2xl border border-slate-700/90 bg-[#141a22] py-3.5 pl-12 pr-4 text-[0.9375rem] text-white placeholder:text-slate-600 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
            @input="onTypeInput"
            @focus="onTypeFocus"
          />
        </div>
      </div>

      <div
        v-show="catalogPanelOpen && (inner.company_id || inner.use_quick_client) && filteredCatalog.length && !disabled"
        id="catalog-panel-list"
        class="mt-3 max-h-60 overflow-y-auto rounded-xl border border-slate-600/80 bg-[#1a222d]"
        role="listbox"
        aria-label="Ítems del catálogo"
      >
        <button
          v-for="item in filteredCatalog"
          :key="item.id"
          type="button"
          class="flex w-full items-center gap-2 border-b border-slate-700/50 px-4 py-3 text-left text-sm text-slate-200 last:border-b-0 hover:bg-sky-500/15 active:bg-sky-500/25"
          @click.prevent="pickCatalogRow(item)"
        >
          <span class="font-medium">{{ item.label }}</span>
          <span v-if="!item.isOther" class="ml-auto text-xs tabular-nums text-slate-500">
            ${{ Number(item.basePrice).toLocaleString('es-CO') }}
          </span>
          <span v-else class="ml-auto text-xs text-amber-400/90">nuevo</span>
        </button>
      </div>

      <p
        v-if="(inner.company_id || inner.use_quick_client) && catalogPanelOpen && !filteredCatalog.length && !disabled"
        class="mt-2 text-sm text-amber-400/90"
      >
        No hay ítems en el catálogo para esta empresa.
      </p>
      <p v-if="!inner.company_id && !inner.use_quick_client" class="mt-2 text-[0.75rem] text-amber-500/90">
        Primero elige empresa o cliente puntual; luego abre «Ver catálogo» y toca cada ítem que quieras sumar.
      </p>
      <p v-else-if="inner.company_id || inner.use_quick_client" class="mt-2 text-[0.75rem] text-slate-500">
        <template v-if="catalogItems.length">{{ catalogItems.length }} ítem(s) en catálogo (definidos por administración).</template>
        <template v-else>Sin ítems en catálogo: use solo «Nuevo ítem» y complete nombre del concepto, descripción e importe.</template>
        Puedes tocar varios ítems seguidos sin cerrar el panel.
      </p>
      <p v-if="fieldErrors.items" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.items[0] }}</p>
    </div>

    <!-- Líneas -->
    <ul v-if="lines.length" class="flex flex-col gap-3">
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
        <p class="mb-1.5 text-[0.7rem] leading-snug text-slate-500">
          No basta el nombre del ítem: indica trabajo real (falla, repuesto, zona, duración…).
        </p>
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
          <p class="mt-1.5 text-[0.65rem] text-slate-500">
            Al tocar descripción o importe abajo, esta guía se oculta. Escribe tu propio detalle e importe de referencia.
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
            min="0"
            step="1"
            :disabled="disabled"
            :placeholder="row.catalog_id != null ? 'Importe de referencia (obligatorio para validar)' : ''"
            class="w-full rounded-xl border border-slate-700/90 bg-[#141a22] py-2.5 pl-8 pr-3 text-sm text-white tabular-nums outline-none focus:border-sky-400"
            @focus="dismissCatalogHint(idx)"
            @input="updateLine(idx, { amount: $event.target.value })"
            @wheel.prevent
          />
        </div>
      </li>
    </ul>

    <p v-if="lines.length" class="rounded-2xl border border-sky-500/25 bg-sky-500/10 px-4 py-3 text-center text-sm font-semibold text-sky-100">
      Total referencia (lo que ingresas por línea): {{ totalDisplay }}
    </p>

    <!-- Resumen tipo servicio (se sincroniza con los ítems; editable) -->
    <div v-if="lines.length">
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Resumen en una línea <span class="text-red-400">*</span>
      </label>
      <p class="mb-2 text-[0.75rem] leading-relaxed text-slate-500">
        Se arma con los nombres de los conceptos; puedes acortarlo para que se entienda de un vistazo.
      </p>
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
      <p class="step-lede">Las fotos ayudan a respaldar el trabajo (opcional).</p>

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
