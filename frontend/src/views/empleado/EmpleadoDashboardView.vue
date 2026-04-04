<script setup>
import { computed, onMounted, ref, useId } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/services/api.js'

const auth = useAuthStore()
const chartGradId = useId()

const loading = ref(true)
const loadError = ref('')
const data = ref(null)

const companyFilter = ref('')
const datePreset = ref('all')
const selectedRowId = ref(null)

const period = computed(() => data.value?.period)

const summary = computed(() => data.value?.summary)
const recent = computed(() => data.value?.recent_services || [])

const uniqueCompanies = computed(() => {
  const set = new Set()
  for (const r of recent.value) {
    if (r.company_name) set.add(r.company_name)
  }
  return [...set].sort((a, b) => a.localeCompare(b))
})

const filteredRecent = computed(() => {
  let rows = [...recent.value]
  if (companyFilter.value) {
    rows = rows.filter((r) => r.company_name === companyFilter.value)
  }
  if (datePreset.value === '7d') {
    const cutoff = new Date()
    cutoff.setDate(cutoff.getDate() - 7)
    cutoff.setHours(0, 0, 0, 0)
    rows = rows.filter((r) => {
      if (!r.service_date) return false
      const d = new Date(r.service_date + 'T12:00:00')
      return !Number.isNaN(d.getTime()) && d >= cutoff
    })
  }
  return rows.sort((a, b) => (a.service_date || '').localeCompare(b.service_date || ''))
})

/** Ej. "Javier García" → "Javier G." */
const nombreCorto = computed(() => {
  const n = (auth.user?.nombre || '').trim()
  if (!n) return 'Técnico'
  const parts = n.split(/\s+/).filter(Boolean)
  if (parts.length === 1) return parts[0]
  const last = parts[parts.length - 1]
  return `${parts[0]} ${last.charAt(0).toUpperCase()}.`
})

/** Puntos para gráfico (acumulado por fecha de servicio en el subconjunto filtrado) */
const chartSeries = computed(() => {
  const rows = [...filteredRecent.value].sort((a, b) =>
    (a.service_date || '').localeCompare(b.service_date || '')
  )
  let cum = 0
  return rows.map((r) => {
    cum += Number(r.amount) || 0
    return {
      date: r.service_date,
      cum,
      code: r.code,
    }
  })
})

const chartSvg = computed(() => {
  const series = chartSeries.value
  if (!series.length) {
    return { lineMain: '', lineRef: '', area: '', labels: [], w: 400, h: 240, hasData: false, maxV: 0 }
  }
  const w = 400
  const h = 240
  const pad = { t: 16, r: 16, b: 28, l: 8 }
  const innerW = w - pad.l - pad.r
  const innerH = h - pad.t - pad.b
  const maxV = Math.max(...series.map((p) => p.cum), 1)
  const n = series.length
  const step = n <= 1 ? innerW / 2 : innerW / (n - 1)

  const coords = series.map((p, i) => {
    const x = pad.l + (n <= 1 ? innerW / 2 : i * step)
    const yMain = pad.t + innerH - (p.cum / maxV) * innerH
    const yRef = pad.t + innerH - ((p.cum * 0.88) / maxV) * innerH
    return { x, yMain, yRef }
  })

  const lineMain = coords.map((c) => `${c.x},${c.yMain}`).join(' ')
  const lineRef = coords.map((c) => `${c.x},${c.yRef}`).join(' ')
  const bottom = pad.t + innerH
  const topLine = coords.map((c) => `L ${c.x},${c.yMain}`).join(' ')
  const area = `M ${coords[0].x},${bottom} ${topLine} L ${coords[coords.length - 1].x},${bottom} Z`

  const labels = series.map((p, i) => ({
    x: pad.l + (n <= 1 ? innerW / 2 : i * step),
    text: formatChartLabel(p.date),
    yText: h - 10,
  }))

  const bottomY = pad.t + innerH
  const gridYs = [0.25, 0.5, 0.75].map((r) => pad.t + innerH * r)

  return { lineMain, lineRef, area, labels, w, h, hasData: true, maxV, bottomY, gridYs, padL: pad.l, innerW }
})

/** Mini sparkline decorativo (tendencia simplificada) */
const sparklinePoints = computed(() => {
  const series = chartSeries.value
  if (!series.length) return ''
  const w = 48
  const h = 20
  const vals = series.map((p) => p.cum)
  const max = Math.max(...vals, 1)
  const n = vals.length
  const step = n <= 1 ? w / 2 : w / (n - 1)
  return vals
    .map((v, i) => {
      const x = n <= 1 ? w / 2 : i * step
      const y = h - (v / max) * (h - 2) - 1
      return `${x},${y}`
    })
    .join(' ')
})

const companyBreakdown = computed(() => {
  const map = new Map()
  for (const r of filteredRecent.value) {
    const name = r.company_name || '—'
    const prev = map.get(name) || { name, count: 0, total: 0 }
    prev.count += 1
    prev.total += Number(r.amount) || 0
    map.set(name, prev)
  }
  return [...map.values()].sort((a, b) => b.total - a.total)
})

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    data.value = await api('/empleado/dashboard')
  } catch (e) {
    loadError.value =
      e.data?.message || e.message || 'No se pudo cargar tu panel. Intenta de nuevo.'
    data.value = null
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
})

function formatMoney(value) {
  if (value === undefined || value === null) return '—'
  const n = Number(value)
  if (Number.isNaN(n)) return String(value)
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(n)
}

function formatMoneyAvg(value) {
  if (value === undefined || value === null) return '—'
  const n = Number(value)
  if (Number.isNaN(n)) return String(value)
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(n)
}

function formatCode(code) {
  if (code == null || code === '') return '—'
  const s = String(code).trim()
  return s.startsWith('#') ? s : `#${s}`
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso.length === 10 ? `${iso}T12:00:00` : iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { dateStyle: 'medium' })
}

/** Fecha compacta tipo mock (ej. 12 oct) */
function formatDateShort(iso) {
  if (!iso) return '—'
  const d = new Date(iso.length === 10 ? `${iso}T12:00:00` : iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })
}

function formatChartLabel(iso) {
  if (!iso) return ''
  const d = new Date(iso + 'T12:00:00')
  if (Number.isNaN(d.getTime())) return iso.slice(5)
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short' })
}

function toggleRow(id) {
  selectedRowId.value = selectedRowId.value === id ? null : id
}
</script>

<template>
  <div class="mx-auto max-w-6xl font-sans text-slate-200">
    <header class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
      <div class="min-w-0">
        <h1 class="text-2xl font-bold leading-tight tracking-tight text-white sm:text-[1.65rem]">
          Historial de Rendimiento
        </h1>
        <p v-if="period" class="mt-2 text-sm text-slate-500">
          Periodo mostrado: <span class="text-slate-400">{{ period.label }}</span>
        </p>
      </div>
      <div class="flex shrink-0 flex-wrap items-center gap-2 sm:gap-3">
        <button
          type="button"
          class="rounded-xl border border-slate-700/90 bg-slate-900/60 px-3 py-2 text-xs font-medium text-slate-400 transition hover:border-slate-600 hover:text-slate-300 sm:text-sm"
          :disabled="loading"
          @click="load"
        >
          {{ loading ? 'Actualizando…' : 'Actualizar' }}
        </button>
        <RouterLink
          to="/empleado/registro-servicio"
          class="inline-flex items-center gap-2 rounded-2xl border-2 border-sky-400 bg-slate-950/90 px-5 py-2.5 text-sm font-semibold text-white shadow-[0_0_28px_rgba(56,189,248,0.45)] transition hover:border-sky-300 hover:shadow-[0_0_36px_rgba(56,189,248,0.55)]"
        >
          Registrar servicio
          <span class="text-base font-light leading-none text-sky-200">+</span>
        </RouterLink>
      </div>
    </header>

    <div v-if="loadError && !data" class="mb-6 rounded-xl border border-red-400/30 bg-red-950/40 p-4 text-red-200" role="alert">
      <p>{{ loadError }}</p>
      <button type="button" class="mt-2 text-sm font-semibold text-sky-300 underline" @click="load">Reintentar</button>
    </div>

    <p v-else-if="loading && !data" class="text-slate-400">Cargando…</p>

    <template v-else>
      <div v-if="loadError && data" class="mb-4 rounded-lg border border-amber-400/30 bg-amber-950/30 px-3 py-2 text-sm text-amber-100" role="status">
        {{ loadError }}
      </div>

      <!-- KPIs (totales historial, alineados al mock) -->
      <div class="mb-8 grid gap-4 sm:grid-cols-3">
        <article
          class="flex items-start justify-between gap-3 rounded-2xl border border-slate-700/50 bg-[#1a2332] p-5 shadow-lg shadow-black/25"
        >
          <div>
            <h3 class="text-[0.7rem] font-semibold uppercase tracking-wide text-slate-500">
              Total Facturado Historial
            </h3>
            <p class="mt-3 text-2xl font-bold tabular-nums text-white sm:text-[1.75rem]">
              {{ formatMoney(summary?.total_amount_historial) }}
            </p>
          </div>
          <div
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-800/90 text-sky-400"
            aria-hidden="true"
          >
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </article>
        <article
          class="flex items-start justify-between gap-3 rounded-2xl border border-slate-700/50 bg-[#1a2332] p-5 shadow-lg shadow-black/25"
        >
          <div>
            <h3 class="text-[0.7rem] font-semibold uppercase tracking-wide text-slate-500">
              Servicios Realizados Historial
            </h3>
            <p class="mt-3 text-2xl font-bold tabular-nums text-sky-400 sm:text-[1.75rem]">
              {{ summary?.services_count_historial ?? 0 }}
            </p>
          </div>
          <div
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-800/90 text-sky-400"
            aria-hidden="true"
          >
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
            </svg>
          </div>
        </article>
        <article
          class="flex items-start justify-between gap-3 rounded-2xl border border-slate-700/50 bg-[#1a2332] p-5 shadow-lg shadow-black/25"
        >
          <div>
            <h3 class="text-[0.7rem] font-semibold uppercase tracking-wide text-slate-500">Promedio por Servicio</h3>
            <p class="mt-3 text-2xl font-bold tabular-nums text-white sm:text-[1.75rem]">
              {{ formatMoneyAvg(summary?.avg_per_service_historial) }}
            </p>
          </div>
          <div
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-slate-800/90 text-emerald-400/90"
            aria-hidden="true"
          >
            <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
              <path d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
            </svg>
          </div>
        </article>
      </div>

      <!-- Tarjeta principal: controles + gráfico + tabla -->
      <section class="mb-8 rounded-2xl border border-slate-700/40 bg-[#141b26] p-5 sm:p-6">
        <div class="flex flex-col gap-5 border-b border-slate-700/50 pb-5 lg:flex-row lg:items-end lg:justify-between">
          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              class="rounded-xl border border-transparent p-2 text-slate-500 transition hover:bg-slate-800/80 hover:text-slate-300"
              title="Mes anterior (próximamente)"
              aria-label="Mes anterior"
            >
              <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M15 18l-6-6 6-6" />
              </svg>
            </button>
            <span class="text-sm font-semibold text-white">{{ period?.label }}</span>
            <button
              type="button"
              class="rounded-xl border border-sky-500/50 bg-sky-500/15 p-2 text-sky-400 shadow-[0_0_12px_rgba(56,189,248,0.2)]"
              title="Mes siguiente (próximamente)"
              aria-label="Mes siguiente"
            >
              <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M9 18l6-6-6-6" />
              </svg>
            </button>
          </div>
          <div class="flex flex-wrap gap-4 lg:gap-5">
            <div class="flex min-w-[160px] flex-1 flex-col gap-1.5 sm:min-w-[200px]">
              <label class="text-xs font-medium text-slate-500">Filtrar por Empresa</label>
              <select
                v-model="companyFilter"
                class="rounded-xl border border-slate-600/90 bg-[#0d1219] px-3 py-2.5 text-sm text-slate-200 focus:border-sky-500/50 focus:outline-none focus:ring-2 focus:ring-sky-500/20"
              >
                <option value="">Todas las empresas</option>
                <option v-for="c in uniqueCompanies" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
            <div class="flex min-w-[160px] flex-1 flex-col gap-1.5 sm:min-w-[200px]">
              <label class="text-xs font-medium text-slate-500">Rango de Fechas</label>
              <select
                v-model="datePreset"
                class="rounded-xl border border-slate-600/90 bg-[#0d1219] px-3 py-2.5 text-sm text-slate-200 focus:border-sky-500/50 focus:outline-none focus:ring-2 focus:ring-sky-500/20"
              >
                <option value="all">Todo el historial (lista reciente)</option>
                <option value="7d">Últimos 7 días</option>
              </select>
            </div>
          </div>
        </div>

        <div class="mt-6 rounded-2xl border border-slate-700/30 bg-[#1a2332]/80 p-4 sm:p-5">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="text-sm font-semibold text-slate-200 sm:text-base">
                Evolución de Rendimiento - {{ nombreCorto }} (Historial)
              </h2>
              <p class="mt-1 text-xs text-slate-500">Acumulado según filtros sobre los servicios listados</p>
            </div>
            <div
              v-if="chartSvg.hasData"
              class="rounded-lg border border-emerald-500/20 bg-emerald-500/5 px-2 py-1"
              aria-hidden="true"
            >
              <svg class="h-5 w-12" viewBox="0 0 48 20" preserveAspectRatio="none">
                <polyline
                  :points="sparklinePoints"
                  fill="none"
                  stroke="rgb(52, 211, 153)"
                  stroke-width="1.5"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  opacity="0.9"
                />
              </svg>
            </div>
          </div>

          <div class="relative mt-4 h-[240px] w-full overflow-hidden rounded-xl border border-slate-800/60 bg-[#0d1219]/90">
            <svg
              v-if="chartSvg.hasData"
              class="h-full w-full"
              :viewBox="`0 0 ${chartSvg.w} ${chartSvg.h}`"
              preserveAspectRatio="xMidYMid meet"
            >
              <defs>
                <linearGradient :id="chartGradId" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="0%" stop-color="rgb(56, 189, 248)" stop-opacity="0.28" />
                  <stop offset="100%" stop-color="rgb(56, 189, 248)" stop-opacity="0" />
                </linearGradient>
              </defs>
              <g opacity="0.35">
                <line
                  v-for="(gy, i) in chartSvg.gridYs"
                  :key="i"
                  :x1="chartSvg.padL"
                  :x2="chartSvg.padL + chartSvg.innerW"
                  :y1="gy"
                  :y2="gy"
                  stroke="rgb(100, 116, 139)"
                  stroke-width="0.5"
                  stroke-dasharray="4 4"
                />
              </g>
              <path :d="chartSvg.area" :fill="`url(#${chartGradId})`" />
              <polyline
                :points="chartSvg.lineRef"
                fill="none"
                stroke="rgb(52, 211, 153)"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                opacity="0.9"
              />
              <polyline
                :points="chartSvg.lineMain"
                fill="none"
                stroke="rgb(56, 189, 248)"
                stroke-width="2.5"
                stroke-linecap="round"
                stroke-linejoin="round"
              />
              <g v-for="(lb, i) in chartSvg.labels" :key="i">
                <text
                  :x="lb.x"
                  :y="lb.yText"
                  text-anchor="middle"
                  fill="#64748b"
                  style="font-size: 9px"
                >
                  {{ lb.text }}
                </text>
              </g>
            </svg>
            <div v-else class="flex h-full items-center justify-center text-sm text-slate-500">
              Sin datos para el gráfico con los filtros actuales
            </div>
          </div>
          <div class="mt-3 flex flex-wrap gap-5 text-xs">
            <span class="inline-flex items-center gap-2 text-sky-400">
              <span class="h-2 w-6 rounded-sm bg-sky-400" />
              Rendimiento de {{ nombreCorto }}
            </span>
            <span class="inline-flex items-center gap-2 text-emerald-400/95">
              <span class="h-2 w-6 rounded-sm bg-emerald-400" />
              Mes anterior
            </span>
          </div>
        </div>

        <div class="mt-6 overflow-x-auto rounded-2xl border border-slate-800/80">
          <table class="w-full min-w-[640px] border-collapse text-left text-sm">
            <thead>
              <tr
                class="border-b border-slate-700/80 bg-slate-800/40 text-xs font-semibold uppercase tracking-wide text-slate-400"
              >
                <th class="px-4 py-3.5 first:rounded-tl-2xl">Código</th>
                <th class="px-4 py-3.5">Fecha</th>
                <th class="px-4 py-3.5">Empresa</th>
                <th class="px-4 py-3.5">Descripción</th>
                <th class="px-4 py-3.5 text-right">Valor</th>
                <th class="px-4 py-3.5 text-right last:rounded-tr-2xl" />
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="row in filteredRecent"
                :key="row.id"
                class="cursor-pointer border-b border-slate-800/70 transition last:border-0"
                :class="
                  selectedRowId === row.id
                    ? 'bg-sky-500/[0.12] ring-1 ring-inset ring-sky-500/25'
                    : 'hover:bg-slate-800/35'
                "
                @click="toggleRow(row.id)"
              >
                <td class="px-4 py-3.5 font-mono text-xs font-medium text-slate-400">
                  {{ formatCode(row.code) }}
                </td>
                <td class="px-4 py-3.5 capitalize text-slate-300">{{ formatDateShort(row.service_date) }}</td>
                <td class="px-4 py-3.5 font-medium text-slate-200">{{ row.company_name || '—' }}</td>
                <td class="max-w-[220px] truncate px-4 py-3.5 text-slate-400" :title="row.description">
                  {{ row.description }}
                </td>
                <td class="px-4 py-3.5 text-right font-semibold tabular-nums text-white">
                  {{ formatMoney(row.amount) }}
                </td>
                <td class="px-4 py-3.5 text-right">
                  <RouterLink
                    v-if="selectedRowId === row.id"
                    :to="`/empleado/servicio/${row.id}`"
                    class="inline-flex items-center gap-1.5 text-xs font-semibold text-sky-400 hover:text-sky-300 hover:underline"
                    @click.stop
                  >
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.75" viewBox="0 0 24 24" aria-hidden="true">
                      <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                      <circle cx="12" cy="12" r="3" />
                    </svg>
                    ver detalle
                  </RouterLink>
                </td>
              </tr>
            </tbody>
          </table>
          <p v-if="!filteredRecent.length" class="px-4 py-8 text-center text-sm text-slate-500">
            No hay servicios con estos filtros.
          </p>
        </div>
      </section>

      <!-- Desglose por empresa -->
      <section class="rounded-2xl border border-slate-700/40 bg-[#141b26] p-5 sm:p-6">
        <h2 class="text-base font-semibold text-white">Desglose por Empresa (Historial)</h2>
        <p class="mt-1 text-xs text-slate-500">Según los mismos filtros aplicados a la tabla</p>
        <ul class="mt-4 divide-y divide-slate-800/80">
          <li
            v-for="row in companyBreakdown"
            :key="row.name"
            class="flex flex-wrap items-center justify-between gap-2 py-3 first:pt-0"
          >
            <span class="font-medium text-slate-200">{{ row.name }}</span>
            <span class="text-sm text-slate-500">{{ row.count }} servicio(s)</span>
            <span class="ml-auto font-semibold tabular-nums text-sky-300">{{ formatMoney(row.total) }}</span>
          </li>
        </ul>
        <p v-if="!companyBreakdown.length" class="mt-4 text-sm text-slate-500">Sin datos.</p>
      </section>

    </template>
  </div>
</template>
