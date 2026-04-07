<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import AdminServiceDetailPanel from '@/components/admin/AdminServiceDetailPanel.vue'
import { fetchServices } from '@/services/servicesApi.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Año del mes mostrado (p. ej. desde dashboard `period.year`). */
  year: { type: Number, default: null },
  /** Mes 1–12 (desde dashboard `period.month`). */
  month: { type: Number, default: null },
  /** Etiqueta legible, p. ej. «Abril 2026». */
  periodLabel: { type: String, default: '' },
})

const emit = defineEmits(['close'])

const rows = ref([])
const loading = ref(false)
const error = ref('')

const detailOpen = ref(false)
const detailServiceId = ref(null)

const effectiveYear = computed(() => {
  const y = props.year
  if (y != null && y > 0) return y
  return new Date().getFullYear()
})

const effectiveMonth = computed(() => {
  const m = props.month
  if (m != null && m >= 1 && m <= 12) return m
  return new Date().getMonth() + 1
})

const titleSuffix = computed(() => {
  if (props.periodLabel?.trim()) return props.periodLabel.trim()
  const y = effectiveYear.value
  const m = effectiveMonth.value
  const d = new Date(y, m - 1, 1)
  return d.toLocaleDateString('es-CO', { month: 'long', year: 'numeric' })
})

function monthRangeIso() {
  const y = effectiveYear.value
  const m = effectiveMonth.value
  const from = `${y}-${String(m).padStart(2, '0')}-01`
  const lastDay = new Date(y, m, 0).getDate()
  const to = `${y}-${String(m).padStart(2, '0')}-${String(lastDay).padStart(2, '0')}`
  return { from, to }
}

async function load() {
  error.value = ''
  loading.value = true
  const { from, to } = monthRangeIso()
  try {
    const res = await fetchServices({
      service_date_from: from,
      service_date_to: to,
      per_page: 100,
      page: 1,
      sort: 'service_date',
      sort_dir: 'desc',
    })
    rows.value = Array.isArray(res.data) ? res.data : []
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar los servicios.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

function formatServiceDate(s) {
  if (!s?.service_date) return '—'
  const d = new Date(s.service_date + 'T12:00:00')
  if (Number.isNaN(d.getTime())) return s.service_date
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function estadoLabel(status) {
  const m = { activo: 'Activo', corregido: 'Corregido', eliminado: 'Eliminado' }
  return m[status] ?? status ?? '—'
}

function pillClass(status) {
  const s = String(status || '')
  if (s === 'activo') return 'border-emerald-400/40 text-emerald-200'
  if (s === 'corregido') return 'border-sky-400/40 text-sky-200'
  if (s === 'eliminado') return 'border-red-400/40 text-red-200'
  return 'border-slate-500/40 text-slate-300'
}

function openDetail(row) {
  detailServiceId.value = row.id
  detailOpen.value = true
}

function closeDetail() {
  detailOpen.value = false
  detailServiceId.value = null
}

function closePanel() {
  closeDetail()
  emit('close')
}

function onGlobalEscape(ev) {
  if (ev.key !== 'Escape') return
  if (detailOpen.value) {
    ev.preventDefault()
    closeDetail()
    return
  }
  if (props.open) {
    ev.preventDefault()
    closePanel()
  }
}

watch(
  () => props.open,
  (v) => {
    if (v) {
      load()
      document.addEventListener('keydown', onGlobalEscape)
    } else {
      document.removeEventListener('keydown', onGlobalEscape)
      closeDetail()
    }
  }
)

onUnmounted(() => {
  document.removeEventListener('keydown', onGlobalEscape)
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 z-[200] flex items-center justify-center p-4 sm:p-6"
      role="presentation"
    >
      <button
        type="button"
        class="absolute inset-0 bg-black/60 backdrop-blur-sm"
        aria-label="Cerrar panel"
        @click="closePanel"
      />
      <div
        class="relative z-10 flex max-h-[min(85vh,720px)] w-full max-w-lg flex-col overflow-hidden rounded-2xl border border-slate-700/80 bg-[#151a24] shadow-2xl"
        role="dialog"
        aria-modal="true"
        aria-labelledby="dash-svc-panel-title"
      >
        <header class="flex shrink-0 items-start justify-between gap-3 border-b border-slate-700/80 px-5 py-4">
          <div>
            <h2 id="dash-svc-panel-title" class="text-lg font-bold text-white">
              Servicios del mes
            </h2>
            <p class="mt-1 text-xs text-slate-400">
              {{ titleSuffix }} · hasta 100 registros (fecha de servicio en el período).
            </p>
          </div>
          <button
            type="button"
            class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-800 hover:text-white"
            aria-label="Cerrar"
            @click="closePanel"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto px-4 py-3">
          <p v-if="error" class="mb-3 rounded-lg border border-red-500/35 bg-red-500/10 px-3 py-2 text-sm text-red-200">
            {{ error }}
          </p>
          <p v-if="loading" class="py-8 text-center text-sm text-slate-400">Cargando…</p>
          <ul v-else-if="rows.length" class="flex flex-col gap-3">
            <li
              v-for="s in rows"
              :key="s.id"
              class="rounded-xl border border-slate-700/60 bg-[#1e2532] p-4 shadow-md shadow-black/20"
            >
              <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
                <div class="min-w-0">
                  <button
                    type="button"
                    class="font-mono text-sm font-semibold text-sky-300 underline decoration-sky-500/40 hover:text-sky-200"
                    @click="openDetail(s)"
                  >
                    {{ s.code }}
                  </button>
                  <p class="mt-0.5 truncate text-sm text-slate-200">
                    {{ s.company?.nombre || '—' }}
                  </p>
                  <p class="text-[0.7rem] text-slate-500">
                    {{ s.empleado?.nombre || '—' }} · {{ formatServiceDate(s) }}
                  </p>
                </div>
                <span
                  class="inline-flex shrink-0 rounded-full border px-2 py-0.5 text-[0.7rem] font-semibold"
                  :class="pillClass(s.status)"
                >
                  {{ estadoLabel(s.status) }}
                </span>
              </div>
              <p class="line-clamp-2 text-xs text-slate-400">{{ s.service_type || s.description || '—' }}</p>
              <p class="mt-2 text-sm font-semibold text-slate-200">{{ money(s.amount) }}</p>
            </li>
          </ul>
          <p v-else class="py-10 text-center text-sm text-slate-500">
            No hay servicios registrados en este mes.
          </p>
        </div>

        <footer class="shrink-0 border-t border-slate-700/80 px-4 py-3">
          <RouterLink
            to="/admin/servicios"
            class="block w-full rounded-xl border border-slate-600/50 bg-slate-800/40 py-2.5 text-center text-sm font-semibold text-sky-300 hover:bg-slate-800/70"
            @click="closePanel"
          >
            Ir a servicios (listado completo)
          </RouterLink>
        </footer>
      </div>
    </div>

    <AdminServiceDetailPanel
      :open="detailOpen"
      :service-id="detailServiceId"
      :overlay-z-index="210"
      @close="closeDetail"
    />
  </Teleport>
</template>
