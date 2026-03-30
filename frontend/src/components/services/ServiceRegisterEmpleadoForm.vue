<script setup>
import { computed, onMounted, onUnmounted, ref, useId, watch } from 'vue'
import { SERVICE_TYPE_CATALOG } from '@/constants/serviceTypeCatalog.js'

const clientListId = useId()
const photoInputId = useId()

const props = defineProps({
  modelValue: { type: Object, required: true },
  photos: { type: Array, default: () => [] },
  companies: { type: Array, default: () => [] },
  clientSuggestions: { type: Array, default: () => [] },
  fieldErrors: { type: Object, default: () => ({}) },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue', 'update:photos'])

const previewUrls = ref([])

watch(
  () => props.photos,
  (files) => {
    previewUrls.value.forEach((u) => URL.revokeObjectURL(u))
    const list = Array.isArray(files) ? files : []
    previewUrls.value = list.filter((f) => f instanceof File).map((f) => URL.createObjectURL(f))
  },
  { deep: true, immediate: true }
)

onUnmounted(() => {
  previewUrls.value.forEach((u) => URL.revokeObjectURL(u))
})

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

const typeMenuOpen = ref(false)
const typeFilter = ref('')
const rootEl = ref(null)

const inner = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

function patch(partial) {
  emit('update:modelValue', { ...props.modelValue, ...partial })
}

const filteredCatalog = computed(() => {
  const q = typeFilter.value.trim().toLowerCase()
  if (!q) return SERVICE_TYPE_CATALOG
  return SERVICE_TYPE_CATALOG.filter(
    (i) => i.label.toLowerCase().includes(q) || i.id.includes(q)
  )
})

function pickCatalogItem(item) {
  patch({
    service_type: item.label,
    amount: String(item.basePrice),
  })
  typeFilter.value = item.label
  typeMenuOpen.value = false
}

function onTypeInput(e) {
  const v = e.target.value
  typeFilter.value = v
  patch({ service_type: v })
  typeMenuOpen.value = true
}

function onTypeFocus() {
  typeFilter.value = props.modelValue.service_type || ''
  typeMenuOpen.value = true
}

function closeTypeMenu() {
  typeMenuOpen.value = false
}

function onDocClick(ev) {
  if (!rootEl.value?.contains(ev.target)) closeTypeMenu()
}

onMounted(() => {
  typeFilter.value = props.modelValue.service_type || ''
  document.addEventListener('click', onDocClick)
})
onUnmounted(() => document.removeEventListener('click', onDocClick))

watch(
  () => props.modelValue.service_type,
  (v) => {
    if (document.activeElement?.closest?.('[data-type-field]')) return
    typeFilter.value = v || ''
  }
)
</script>

<template>
  <div ref="rootEl" class="flex flex-col gap-5">
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

    <!-- Tipo de servicio + catálogo -->
    <div data-type-field class="relative">
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Tipo de servicio <span class="text-red-400">*</span>
      </label>
      <div class="relative">
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
          :value="typeFilter"
          type="text"
          autocomplete="off"
          placeholder="Buscar en catálogo o escribir manualmente"
          :disabled="disabled"
          class="w-full rounded-2xl border border-slate-700/90 bg-[#141a22] py-3.5 pl-12 pr-12 text-[0.9375rem] text-white placeholder:text-slate-600 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
          @input="onTypeInput"
          @focus="onTypeFocus"
        />
        <span class="pointer-events-none absolute right-3.5 top-1/2 -translate-y-1/2 text-slate-600" aria-hidden="true">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path
              d="M12 15a3 3 0 100-6 3 3 0 000 6z M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 009 4.6V4a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06A1.65 1.65 0 0018.4 9c.09 0 .17 0 .26.01H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"
              stroke-linecap="round"
              stroke-linejoin="round"
            />
          </svg>
        </span>
      </div>
      <p class="mt-1 text-[0.7rem] text-slate-600">El catálogo sugiere precio; puedes cambiar el valor abajo.</p>
      <div
        v-if="typeMenuOpen && filteredCatalog.length && !disabled"
        class="absolute left-0 right-0 top-full z-30 mt-1 max-h-48 overflow-auto rounded-xl border border-slate-700 bg-[#1a222d] py-1 shadow-xl shadow-black/40"
        role="listbox"
      >
        <button
          v-for="item in filteredCatalog"
          :key="item.id"
          type="button"
          class="flex w-full items-center gap-2 px-4 py-2.5 text-left text-sm text-slate-200 hover:bg-sky-500/15"
          @mousedown.prevent="pickCatalogItem(item)"
        >
          <span class="font-medium">{{ item.label }}</span>
          <span class="text-xs text-slate-500">${{ item.basePrice.toLocaleString('es-CO') }} sugerido</span>
        </button>
      </div>
      <p v-if="fieldErrors.service_type" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.service_type[0] }}</p>
    </div>

    <!-- Descripción -->
    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Descripción <span class="text-red-400">*</span>
      </label>
      <textarea
        :value="inner.description"
        rows="4"
        placeholder="Descripción del trabajo realizado..."
        :disabled="disabled"
        class="w-full resize-y rounded-2xl border border-slate-700/90 bg-[#141a22] px-4 py-3.5 text-[0.9375rem] text-white placeholder:text-slate-600 outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
        @input="patch({ description: $event.target.value })"
      />
      <p v-if="fieldErrors.description" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.description[0] }}</p>
    </div>

    <!-- Valor -->
    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Valor (COP) <span class="text-red-400">*</span>
      </label>
      <div class="relative">
        <span class="pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-lg font-semibold text-slate-500" aria-hidden="true">$</span>
        <input
          :value="inner.amount"
          type="number"
          inputmode="decimal"
          min="0"
          step="1"
          placeholder="0"
          :disabled="disabled"
          class="w-full rounded-2xl border border-slate-700/90 bg-[#141a22] py-3.5 pl-11 pr-4 text-[0.9375rem] text-white tabular-nums placeholder:text-slate-600 outline-none transition focus:border-sky-400 focus:shadow-[0_0_0_3px_rgba(56,189,248,0.35)] focus:ring-0 disabled:opacity-50"
          @input="patch({ amount: $event.target.value })"
        />
      </div>
      <p v-if="fieldErrors.amount" class="mt-1.5 text-sm text-red-400">{{ fieldErrors.amount[0] }}</p>
    </div>

    <!-- Fecha del servicio -->
    <div>
      <label class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
        Fecha del servicio <span class="text-red-400">*</span>
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
          :disabled="disabled"
          class="w-full rounded-2xl border border-slate-700/90 bg-[#141a22] py-3.5 pl-12 pr-4 text-[0.9375rem] text-white outline-none transition focus:border-sky-500 focus:ring-2 focus:ring-sky-500/35 disabled:opacity-50"
          @input="patch({ service_date: $event.target.value })"
        />
      </div>
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
