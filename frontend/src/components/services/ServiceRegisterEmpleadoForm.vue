<script setup>
import { computed, onUnmounted, ref, useId, watch } from 'vue'
import { SERVICE_TYPE_CATALOG } from '@/constants/serviceTypeCatalog.js'

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
})

const emit = defineEmits(['update:modelValue', 'update:photos'])

const previewUrls = ref([])

function lineKey() {
  return `L-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`
}

function descFromCatalog(item) {
  const d = (item.description || '').trim()
  const label = item.label || item.name
  if (d.length >= 8) return d
  return `Servicio estándar: ${label}. Detalle del trabajo realizado según visita en sitio.`
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
  if (props.disabled || !props.modelValue.company_id) return
  catalogPanelOpen.value = !catalogPanelOpen.value
  if (catalogPanelOpen.value) typeFilter.value = ''
}

watch(
  () => props.modelValue.company_id,
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

function addCatalogLine(item) {
  const label = item.label || item.name
  const cid = item.catalog_id != null && item.catalog_id !== '' ? Number(item.catalog_id) : null
  const base = String(item.basePrice ?? item.base_price ?? '')
  let row
  if (cid != null && !Number.isNaN(cid)) {
    row = {
      key: lineKey(),
      catalog_id: cid,
      label,
      custom_name: '',
      line_description: descFromCatalog(item),
      amount: base,
    }
  } else {
    row = {
      key: lineKey(),
      catalog_id: null,
      label,
      custom_name: label,
      line_description: descFromCatalog(item),
      amount: base,
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

const effectiveTypeCatalog = computed(() => {
  if (props.catalogItems.length > 0) {
    return props.catalogItems.map((c) => ({
      id: `db-${c.id}`,
      label: c.name,
      basePrice: Number(c.base_price),
      description: c.description,
      catalog_id: c.id,
      isOther: false,
    }))
  }
  return SERVICE_TYPE_CATALOG.map((c) => ({ ...c, catalog_id: null, isOther: false }))
})

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
  if (props.modelValue.company_id) catalogPanelOpen.value = true
}

function onTypeFocus() {
  if (!props.modelValue.company_id) return
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
</script>

<template>
  <div class="flex flex-col gap-5">
    <!-- Empresa -->
    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Empresa <span class="text-red-400">*</span>
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
          :value="inner.company_id"
          :disabled="disabled"
          @change="patch({ company_id: $event.target.value ? Number($event.target.value) : '' })"
        >
          <option value="" disabled>Seleccionar empresa</option>
          <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.nombre }}</option>
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
    <div>
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

    <!-- Catálogo: panel inline (no desplegable flotante) -->
    <div class="rounded-2xl border border-slate-700/60 bg-slate-900/30 p-3">
      <label class="mb-2 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Ítems del servicio <span class="text-red-400">*</span>
      </label>
      <div class="flex flex-col gap-2 sm:flex-row sm:items-stretch sm:gap-2">
        <button
          type="button"
          class="flex min-h-[3.25rem] shrink-0 cursor-pointer items-center justify-center gap-2 rounded-2xl border-2 border-dashed border-sky-500/55 bg-sky-500/20 px-4 py-2 text-sm font-bold text-sky-100 shadow-inner shadow-sky-950/30 transition hover:bg-sky-500/30 active:scale-[0.99] disabled:pointer-events-none disabled:opacity-40 sm:min-w-[10.5rem]"
          :disabled="disabled || !inner.company_id"
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
            :disabled="disabled || !inner.company_id"
            class="h-[3.25rem] w-full rounded-2xl border border-slate-700/90 bg-[#141a22] py-3.5 pl-12 pr-4 text-[0.9375rem] text-white placeholder:text-slate-600 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
            @input="onTypeInput"
            @focus="onTypeFocus"
          />
        </div>
      </div>

      <div
        v-show="catalogPanelOpen && inner.company_id && filteredCatalog.length && !disabled"
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

      <p v-if="inner.company_id && catalogPanelOpen && !filteredCatalog.length && !disabled" class="mt-2 text-sm text-amber-400/90">
        No hay ítems en el catálogo para esta empresa.
      </p>
      <p v-if="!inner.company_id" class="mt-2 text-[0.75rem] text-amber-500/90">Primero elige empresa; luego abre «Ver catálogo» y toca cada ítem que quieras sumar.</p>
      <p v-else-if="inner.company_id" class="mt-2 text-[0.75rem] text-slate-500">
        <template v-if="catalogItems.length">{{ catalogItems.length }} ítem(s) de tu empresa en servidor.</template>
        <template v-else>Sin ítems en servidor para esta empresa: se muestra lista orientativa.</template>
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
              {{ row.catalog_id != null ? row.label : 'Otro (catálogo)' }}
            </p>
            <p v-if="row.catalog_id == null" class="mt-1 text-[0.7rem] text-slate-500">Nombre del servicio nuevo</p>
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
          @input="updateLine(idx, { custom_name: $event.target.value })"
        />
        <label class="mb-1 block text-[0.65rem] font-semibold uppercase tracking-wide text-slate-500">Descripción de la línea</label>
        <textarea
          :value="row.line_description"
          rows="2"
          :disabled="disabled"
          class="mb-3 w-full resize-y rounded-xl border border-slate-700/90 bg-[#141a22] px-3 py-2 text-sm text-white outline-none focus:border-sky-500"
          @input="updateLine(idx, { line_description: $event.target.value })"
        />
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
            class="w-full rounded-xl border border-slate-700/90 bg-[#141a22] py-2.5 pl-8 pr-3 text-sm text-white tabular-nums outline-none focus:border-sky-400"
            @input="updateLine(idx, { amount: $event.target.value })"
          />
        </div>
      </li>
    </ul>

    <p v-if="lines.length" class="rounded-2xl border border-sky-500/25 bg-sky-500/10 px-4 py-3 text-center text-sm font-semibold text-sky-100">
      Total del servicio: {{ totalDisplay }}
    </p>

    <!-- Tipo de servicio (resumen editable) -->
    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Tipo de servicio (resumen) <span class="text-red-400">*</span>
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

    <!-- Descripción general -->
    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Descripción general <span class="text-red-400">*</span>
      </label>
      <textarea
        :value="inner.description"
        rows="4"
        placeholder="Resumen del trabajo realizado (visita, alcance…)"
        :disabled="disabled"
        class="w-full resize-y rounded-2xl border border-slate-700/90 bg-[#141a22] px-4 py-3.5 text-[0.9375rem] text-white placeholder:text-slate-600 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
        @input="patch({ description: $event.target.value })"
      />
      <p v-if="fieldErrors.description" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.description[0] }}</p>
    </div>

    <!-- Fecha del servicio (solo lectura) -->
    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Fecha del servicio
      </label>
      <div class="relative">
        <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-500" aria-hidden="true">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path
              d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </span>
        <input
          :value="inner.service_date"
          type="date"
          disabled
          class="w-full cursor-not-allowed rounded-2xl border border-slate-700/60 bg-slate-900/80 py-3.5 pl-12 pr-4 text-[0.9375rem] text-slate-400 outline-none"
        />
      </div>
      <p class="mt-1 text-[0.7rem] text-slate-600">La fecha se toma del día de registro y no es editable.</p>
      <p v-if="fieldErrors.service_date" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.service_date[0] }}</p>
    </div>

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

    <button
      type="submit"
      class="mt-2 w-full rounded-2xl bg-gradient-to-r from-sky-500 to-blue-600 py-4 text-base font-bold text-white shadow-lg shadow-sky-500/25 transition hover:brightness-110 active:scale-[0.99] disabled:opacity-50"
      :disabled="disabled"
    >
      {{ disabled ? 'Guardando…' : 'Guardar servicio' }}
    </button>
  </div>
</template>
