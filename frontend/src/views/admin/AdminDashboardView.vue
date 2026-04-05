<script setup>
import { onMounted, ref, computed, defineAsyncComponent } from 'vue'
import { api } from '@/services/api.js'
import { useClientSortedRows } from '@/composables/useClientSortedRows.js'
import AdminDashboardPendingInvoicesPanel from '@/components/admin/AdminDashboardPendingInvoicesPanel.vue'
import AdminDashboardServicesPanel from '@/components/admin/AdminDashboardServicesPanel.vue'
import AdminDashboardTechnicianPendingPanel from '@/components/admin/AdminDashboardTechnicianPendingPanel.vue'

const VueApexCharts = defineAsyncComponent(() => import('vue3-apexcharts'))

const loading = ref(true)
const loadError = ref('')
const data = ref(null)

// Gráfico Area Spline (ApexCharts) idéntico a Dashboard.png
const chartSeries = ref([
  { name: 'Este Mes', data: [18000, 25000, 42000, 45680, 52000] },
  { name: 'Mes Anterior', data: [15000, 24000, 39000, 40580, 43000] }
])

const chartOptions = ref({
  chart: {
    type: 'area',
    background: 'transparent',
    toolbar: { show: false },
    zoom: { enabled: false },
    fontFamily: 'inherit'
  },
  colors: ['#22c55e', '#10b981'], // Tonos verde fluorecente del mockup
  fill: {
    type: 'gradient',
    gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.05, stops: [0, 90, 100] }
  },
  dataLabels: { enabled: false },
  stroke: { curve: 'smooth', width: 2.5 },
  xaxis: {
    categories: ['Jun', 'Jul', 'Aug', 'Sept', 'Oct'],
    axisBorder: { show: true, color: '#334155' },
    axisTicks: { show: false },
    labels: { style: { colors: '#94a3b8', fontSize: '12px' } }
  },
  yaxis: {
    labels: {
      style: { colors: '#94a3b8', fontSize: '11px' },
      formatter: (value) => value >= 1000 ? (value / 1000) + 'k' : value
    }
  },
  grid: {
    borderColor: '#334155',
    strokeDashArray: 0,
    xaxis: { lines: { show: true } },
    yaxis: { lines: { show: true } }
  },
  legend: { show: false },
  theme: { mode: 'dark' },
  tooltip: {
    theme: 'dark',
    y: { formatter: (val) => '$ ' + val.toLocaleString() }
  }
})

// MOCK DATA si la API no trae el formato exacto requerido por el mockup para las tablas
const mockServices = [
  { id: 1, code: '15 Oct', company_name: 'Tech Solutions', user_name: 'C. Ruíz', valor: 2500, created_at: '2023-10-15T12:00:00Z' },
  { id: 2, code: '14 Oct', company_name: 'Innova Corp', user_name: 'M. Gómez', valor: 850, created_at: '2023-10-14T12:00:00Z' },
  { id: 3, code: '13 Oct', company_name: 'Green Energy', user_name: 'L. Flores', valor: 4100, created_at: '2023-10-13T12:00:00Z' },
  { id: 4, code: '12 Oct', company_name: 'Global L.', user_name: 'A. García', valor: 1800, created_at: '2023-10-12T12:00:00Z' }
]

const mockInvoices = [
  { id: 1, code: 'FAC-231015-INV', company_name: 'Innova Corp', total: 1200, status_label: 'Pendiente' },
  { id: 2, code: 'FAC-231014-GRE', company_name: 'Green Energy', total: 4120, status_label: 'Pagado' },
  { id: 3, code: 'FAC-231013-TEC', company_name: 'Tech Solutions', total: 2500, status_label: 'Pagado' },
  { id: 4, code: 'FAC-231012-GLO', company_name: 'Global L.', total: 850, status_label: 'Vencida' }
]

async function loadDashboard() {
  loading.value = true
  loadError.value = ''
  try {
    data.value = await api('/admin/dashboard')
  } catch (e) {
    loadError.value = e.data?.message || e.message || 'No se pudieron cargar.'
    data.value = null
  } finally {
    loading.value = false
  }
}

const pendingInvoicesPanelOpen = ref(false)
const servicesPanelOpen = ref(false)
const technicianPendingPanelOpen = ref(false)

onMounted(() => {
  loadDashboard()
})

function openPendingInvoicesPanel() {
  pendingInvoicesPanelOpen.value = true
}

function closePendingInvoicesPanel() {
  pendingInvoicesPanelOpen.value = false
}

function openServicesPanel() {
  servicesPanelOpen.value = true
}

function closeServicesPanel() {
  servicesPanelOpen.value = false
}

function openTechnicianPendingPanel() {
  technicianPendingPanelOpen.value = true
}

function closeTechnicianPendingPanel() {
  technicianPendingPanelOpen.value = false
}

/** Facturas con posible acción (alineado con el panel lateral). */
const pendingAttentionCount = computed(() => {
  const c = data.value?.invoice_status_counts
  if (!c) return null
  return (
    (c.borrador ?? 0) +
    (c.aprobada ?? 0) +
    (c.enviada ?? 0) +
    (c.parcialmente_pagada ?? 0)
  )
})

const metrics = computed(() => data.value?.metrics)
const recent = computed(() => data.value?.recent || { services: [], invoices: [] })

const dashSvcTableSource = computed(() =>
  recent.value.services?.length ? recent.value.services : mockServices
)
const {
  sortedRows: dashSortedServices,
  toggleSort: toggleDashSvcSort,
  sortIndicator: dashSvcSortInd,
  ariaSort: dashSvcAriaSort,
} = useClientSortedRows(
  dashSvcTableSource,
  {
    fecha: (s) => s.created_at || s.code || '',
    company_name: (s) => s.company_name || '',
    user_name: (s) => s.user_name || '',
    valor: (s) => Number(s.price ?? s.valor) || 0,
  },
  { initialKey: 'fecha', initialDir: 'desc' }
)

const dashInvTableSource = computed(() =>
  recent.value.invoices?.length ? recent.value.invoices : mockInvoices
)
const {
  sortedRows: dashSortedInvoices,
  toggleSort: toggleDashInvSort,
  sortIndicator: dashInvSortInd,
  ariaSort: dashInvAriaSort,
} = useClientSortedRows(
  dashInvTableSource,
  {
    code: (inv) => inv.code || '',
    company_name: (inv) => inv.company_name || '',
    total: (inv) => Number(inv.total) || 0,
    status: (inv) => inv.status || inv.status_label || '',
  },
  { initialKey: 'code', initialDir: 'desc' }
)

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

function formatValor(value) {
  if (value === undefined || value === null) return '—'
  const n = Number(value)
  if (Number.isNaN(n)) return String(value)
  if (n >= 1000) return '$ ' + (n / 1000).toFixed(1) + 'k'
  return '$ ' + n
}

function formatDateOnly(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-ES', { day: 'numeric', month: 'short' })
}

function getStatusClasses(status) {
  const s = String(status || '').toLowerCase()
  if (s.includes('pendiente') || s.includes('borrador') || s.includes('parcial')) return 'bg-[#40361F] text-[#ebb434] border border-[#ebb434]/20'
  if (s.includes('pagad') || s.includes('aprobada')) return 'bg-[#183a2d] text-[#22c55e] border border-[#22c55e]/20'
  if (s.includes('vencida') || s.includes('anulada')) return 'bg-[#3b1c20] text-[#ef4444] border border-[#ef4444]/20'
  return 'bg-slate-700/30 text-slate-400 border border-slate-600/30'
}

const netAfterTechnicians = computed(() => {
  const raw = metrics.value?.net_collected_after_technician_payouts
  if (raw === undefined || raw === null || raw === '') return null
  const n = Number(raw)
  return Number.isNaN(n) ? null : n
})

const netAfterTechniciansClass = computed(() => {
  const n = netAfterTechnicians.value
  if (n == null) return 'text-slate-400'
  if (n < 0) return 'text-rose-400'
  return 'text-emerald-400'
})
</script>

<template>
  <div class="h-full w-full -mt-2 px-4 pb-6 pt-0 text-slate-200 sm:-mt-3 sm:px-6 sm:pb-8">
    <div v-if="loadError && !data" class="rounded-xl bg-red-500/10 border border-red-500/20 p-4 mb-8 text-sm text-red-400">
      Error: {{ loadError }}
    </div>

    <!-- SKELETON -->
    <div v-else-if="loading && !data" class="animate-pulse space-y-6">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="i in 4" :key="i" class="h-[120px] bg-[#1e2532] rounded-2xl"></div>
      </div>
      <div class="h-[400px] bg-[#1e2532] rounded-2xl"></div>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <div v-for="j in 4" :key="'b'+j" class="h-[120px] bg-[#1e2532] rounded-2xl"></div>
      </div>
    </div>

    <template v-else>
      <!-- ROW 1: 4 MÉTICAS (Total Facturado, Servicios Realizados, etc.) -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-7">
        
        <!-- Tarjeta 1: Total Facturado -->
        <article class="bg-[#1e2532] rounded-2xl p-6 shadow-lg shadow-black/20 flex flex-col justify-between">
          <div class="flex items-center gap-3 mb-2">
             <div class="flex-shrink-0 h-9 w-9 flex items-center justify-center rounded-lg bg-yellow-500/10 text-yellow-500">
               <!-- Coin Icon -->
               <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 4.46 2 7.5S6.48 13 12 13s10-2.46 10-5.5S17.52 2 12 2zm0 9c-4.42 0-8-1.79-8-4s3.58-4 8-4 8 1.79 8 4-3.58 4-8 4zm0 4c-4.42 0-8-1.79-8-4v3.5c0 3.04 4.48 5.5 10 5.5s10-2.46 10-5.5V11c0 2.21-3.58 4-8 4z"/></svg>
             </div>
             <h3 class="text-[0.85rem] font-medium text-slate-300">Total Facturado (Este Mes)</h3>
          </div>
          <div>
            <p class="text-[1.85rem] font-bold text-white tracking-tight leading-none mb-1.5">{{ metrics?.invoiced_month ? formatMoney(metrics.invoiced_month) : '$ 45.680.000' }}</p>
            <p class="text-[0.75rem] font-bold text-emerald-400 flex items-center gap-1">
              <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 10l7-7m0 0l7 7m-7-7v18" /></svg>
              +12.5%
            </p>
          </div>
        </article>

        <!-- Tarjeta 2: Servicios Realizados (clic → panel centrado) -->
        <button
          type="button"
          class="bg-[#1e2532] rounded-2xl p-6 shadow-lg shadow-black/20 flex flex-col justify-between text-left w-full min-h-[120px] transition hover:ring-2 hover:ring-blue-500/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/50"
          @click="openServicesPanel"
        >
          <div class="flex items-center gap-3 mb-2">
             <div class="flex-shrink-0 h-9 w-9 flex items-center justify-center rounded-lg bg-blue-500/10 text-blue-500">
               <!-- Gear Icon -->
               <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
             </div>
             <h3 class="text-[0.85rem] font-medium text-slate-300">Servicios Realizados (Mes)</h3>
          </div>
          <div>
            <p class="text-[1.85rem] font-bold text-white tracking-tight leading-none mb-1.5">
              {{ metrics?.services_count_month != null ? metrics.services_count_month : (recent.services?.length ?? '—') }}
            </p>
            <p class="text-[0.75rem] font-medium text-slate-400">
              {{ data?.period?.label ? `${data.period.label} · ` : '' }}Clic para ver listado
            </p>
          </div>
        </button>

        <!-- Tarjeta 3: Facturas Pendientes (clic → panel con acciones) -->
        <button
          type="button"
          class="bg-[#1e2532] rounded-2xl p-6 shadow-lg shadow-black/20 flex flex-col justify-between text-left w-full min-h-[120px] transition hover:ring-2 hover:ring-red-500/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500/50"
          @click="openPendingInvoicesPanel"
        >
          <div class="flex items-center gap-3 mb-2">
             <div class="flex-shrink-0 h-9 w-9 flex items-center justify-center rounded-lg bg-red-500/10 text-red-500">
               <!-- Clock Icon -->
               <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
             </div>
             <h3 class="text-[0.85rem] font-medium text-slate-300">Facturas Pendientes</h3>
          </div>
          <div>
            <p class="text-[1.85rem] font-bold text-white tracking-tight leading-none mb-1.5">{{ metrics?.pending_collect ? formatMoney(metrics.pending_collect) : '$ 12.450.000' }}</p>
            <p class="text-[0.75rem] font-medium text-slate-400">
              <template v-if="pendingAttentionCount != null">
                {{ pendingAttentionCount }} factura{{ pendingAttentionCount === 1 ? '' : 's' }} con gestión · Clic para ver
              </template>
              <template v-else>Clic para ver listado y acciones</template>
            </p>
          </div>
        </button>

        <!-- Tarjeta 4: Facturas Pagadas -->
        <article class="bg-[#1e2532] rounded-2xl p-6 shadow-lg shadow-black/20 flex flex-col justify-between">
          <div class="flex items-center gap-3 mb-2">
             <div class="flex-shrink-0 h-9 w-9 flex items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-500">
               <!-- Check Icon -->
               <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
             </div>
             <h3 class="text-[0.85rem] font-medium text-slate-300">Facturas Pagadas</h3>
          </div>
          <div>
            <p class="text-[1.85rem] font-bold text-white tracking-tight leading-none mb-1.5">{{ metrics?.received_month ? formatMoney(metrics.received_month) : '$ 33.230.000' }}</p>
            <p class="text-[0.75rem] font-medium text-slate-400">{{ data?.invoice_status_counts?.pagada || 162 }} facturas</p>
          </div>
        </article>
      </div>

      <!-- ROW 2: CONTENIDO CENTRAL (Gráfico Izquierdo + 2 Tablas Derecha) -->
      <div class="grid grid-cols-1 xl:grid-cols-[1.8fr_1.2fr] gap-6">
        
        <!-- MITAD IZQUIERDA: GRÁFICO APEXCHARTS ("Ingresos Mensuales - Octubre 2023") -->
        <section class="bg-[#1e2532] rounded-2xl shadow-lg border border-transparent overflow-hidden flex flex-col h-[500px]">
          <div class="px-7 py-6 flex items-center justify-between">
            <h2 class="text-xl font-bold text-white tracking-wide m-0">Ingresos Mensuales - Octubre 2023</h2>
            <div class="flex items-center gap-5 text-sm font-semibold">
               <div class="flex items-center gap-2 text-slate-300">
                 <span class="h-2 w-2 rounded-full bg-[#22c55e]"></span> Este Mes
               </div>
               <div class="flex items-center gap-2 text-slate-500">
                 <span class="h-2 w-2 rounded-full bg-[#10b981] opacity-50"></span> Mes Anterior
               </div>
            </div>
          </div>
          <!-- Gráfico -->
          <div class="flex-1 px-4 pb-4">
             <VueApexCharts width="100%" height="100%" type="area" :options="chartOptions" :series="chartSeries" />
          </div>
        </section>

        <!-- MITAD DERECHA: 2 TABLAS -->
        <div class="flex flex-col gap-6 h-[500px]">
          
          <!-- TABLA 1: Últimos Servicios Registrados -->
          <section class="bg-[#1e2532] rounded-2xl shadow-lg flex-1 flex flex-col overflow-hidden">
            <div class="px-6 py-4">
              <h2 class="text-[1.1rem] font-bold text-white tracking-wide m-0">Últimos Servicios Registrados</h2>
            </div>
            <div class="overflow-x-auto flex-1 px-6 pb-4">
              <table class="w-full text-left border-collapse">
                <thead>
                  <tr class="border-b border-[#2b3548]">
                    <th class="py-2.5 text-[0.8rem] font-normal text-slate-400 whitespace-nowrap" scope="col" :aria-sort="dashSvcAriaSort('fecha')">
                      <button type="button" class="th-sort" @click="toggleDashSvcSort('fecha')">
                        Fecha<span class="sort-ind" aria-hidden="true">{{ dashSvcSortInd('fecha') }}</span>
                      </button>
                    </th>
                    <th class="py-2.5 text-[0.8rem] font-normal text-slate-400" scope="col" :aria-sort="dashSvcAriaSort('company_name')">
                      <button type="button" class="th-sort" @click="toggleDashSvcSort('company_name')">
                        Empresa<span class="sort-ind" aria-hidden="true">{{ dashSvcSortInd('company_name') }}</span>
                      </button>
                    </th>
                    <th class="py-2.5 text-[0.8rem] font-normal text-slate-400 hidden sm:table-cell" scope="col" :aria-sort="dashSvcAriaSort('user_name')">
                      <button type="button" class="th-sort" @click="toggleDashSvcSort('user_name')">
                        Empleado<span class="sort-ind" aria-hidden="true">{{ dashSvcSortInd('user_name') }}</span>
                      </button>
                    </th>
                    <th class="py-2.5 text-[0.8rem] font-normal text-slate-400 text-right" scope="col" :aria-sort="dashSvcAriaSort('valor')">
                      <button type="button" class="th-sort th-sort--end" @click="toggleDashSvcSort('valor')">
                        Valor<span class="sort-ind" aria-hidden="true">{{ dashSvcSortInd('valor') }}</span>
                      </button>
                    </th>
                  </tr>
                </thead>
                <tbody class="text-[0.85rem] text-slate-300 font-medium border-t border-transparent">
                   <tr v-for="(s, idx) in dashSortedServices.slice(0, 4)" :key="idx" class="border-b border-[#2b3548]/50 last:border-none hover:bg-slate-800/30 transition-colors">
                     <td class="py-3 pr-4 whitespace-nowrap">{{ s.code?.length < 8 ? s.code : formatDateOnly(s.created_at) }}</td>
                     <td class="py-3 pr-4 text-white truncate max-w-[140px]">{{ s.company_name }}</td>
                     <td class="py-3 pr-4 hidden sm:table-cell truncate max-w-[100px]">{{ s.user_name }}</td>
                     <td class="py-3 text-right text-slate-200 whitespace-nowrap">{{ formatValor(s.price || s.valor) }}</td>
                   </tr>
                </tbody>
              </table>
            </div>
          </section>

          <!-- TABLA 2: Facturas Recientes/Pendientes -->
          <section class="bg-[#1e2532] rounded-2xl shadow-lg flex-1 flex flex-col overflow-hidden">
            <div class="px-6 py-4">
              <h2 class="text-[1.1rem] font-bold text-white tracking-wide m-0">Facturas Recientes/Pendientes</h2>
            </div>
            <div class="overflow-x-auto flex-1 px-6 pb-4">
              <table class="w-full text-left border-collapse">
                <thead>
                  <tr class="border-b border-[#2b3548]">
                    <th class="py-2.5 text-[0.8rem] font-normal text-slate-400 whitespace-nowrap" scope="col" :aria-sort="dashInvAriaSort('code')">
                      <button type="button" class="th-sort" @click="toggleDashInvSort('code')">
                        ID<span class="sort-ind" aria-hidden="true">{{ dashInvSortInd('code') }}</span>
                      </button>
                    </th>
                    <th class="py-2.5 text-[0.8rem] font-normal text-slate-400" scope="col" :aria-sort="dashInvAriaSort('company_name')">
                      <button type="button" class="th-sort" @click="toggleDashInvSort('company_name')">
                        Cliente<span class="sort-ind" aria-hidden="true">{{ dashInvSortInd('company_name') }}</span>
                      </button>
                    </th>
                    <th class="py-2.5 text-[0.8rem] font-normal text-slate-400 whitespace-nowrap text-right" scope="col" :aria-sort="dashInvAriaSort('total')">
                      <button type="button" class="th-sort th-sort--end" @click="toggleDashInvSort('total')">
                        Amount<span class="sort-ind" aria-hidden="true">{{ dashInvSortInd('total') }}</span>
                      </button>
                    </th>
                    <th class="py-2.5 px-4 text-[0.8rem] font-normal text-slate-400 text-center" scope="col" :aria-sort="dashInvAriaSort('status')">
                      <button type="button" class="th-sort" @click="toggleDashInvSort('status')">
                        Status<span class="sort-ind" aria-hidden="true">{{ dashInvSortInd('status') }}</span>
                      </button>
                    </th>
                  </tr>
                </thead>
                <tbody class="text-[0.85rem] text-slate-300 font-medium border-t border-transparent">
                   <tr v-for="(inv, idx) in dashSortedInvoices.slice(0, 4)" :key="idx" class="border-b border-[#2b3548]/50 last:border-none hover:bg-slate-800/30 transition-colors">
                     <td class="py-3 pr-4 whitespace-nowrap">{{ inv.code }}</td>
                     <td class="py-3 pr-4 text-white truncate max-w-[140px]">{{ inv.company_name }}</td>
                     <td class="py-3 text-right text-slate-200 whitespace-nowrap">{{ formatMoney(inv.total) }}</td>
                     <td class="py-3 pl-4 text-center whitespace-nowrap">
                        <span :class="['inline-flex items-center px-2 py-0.5 rounded text-[0.7rem] font-bold border', getStatusClasses(inv.status || inv.status_label)]">
                           {{ inv.status_label || inv.status }}
                        </span>
                     </td>
                   </tr>
                </tbody>
              </table>
            </div>
          </section>

        </div>
      </div>

      <!-- ROW 3: flujo técnico + placeholders -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mt-7">
        <button
          type="button"
          class="bg-[#1e2532] rounded-2xl p-6 shadow-lg shadow-black/20 flex flex-col justify-between text-left w-full min-h-[120px] transition hover:ring-2 hover:ring-amber-500/25 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500/50"
          @click="openTechnicianPendingPanel"
        >
          <div class="flex items-center gap-3 mb-2">
            <div class="flex-shrink-0 h-9 w-9 flex items-center justify-center rounded-lg bg-amber-500/10 text-amber-400">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
              </svg>
            </div>
            <h3 class="text-[0.85rem] font-medium text-slate-300">Pago a técnicos pendiente</h3>
          </div>
          <div>
            <p class="text-[1.85rem] font-bold text-white tracking-tight leading-none mb-1.5">
              {{ metrics?.technician_unpaid_services_count != null ? metrics.technician_unpaid_services_count : '—' }}
            </p>
            <p class="text-[0.75rem] font-medium text-slate-400">
              {{ data?.period?.label ? `${data.period.label} · ` : '' }}
              ref.
              {{
                metrics?.technician_unpaid_services_total != null
                  ? formatMoney(metrics.technician_unpaid_services_total)
                  : '—'
              }}
              · Clic para ver
            </p>
          </div>
        </button>

        <article class="bg-[#1e2532] rounded-2xl p-6 shadow-lg shadow-black/20 flex flex-col justify-between min-h-[120px]">
          <div class="flex items-center gap-3 mb-2">
            <div class="flex-shrink-0 h-9 w-9 flex items-center justify-center rounded-lg bg-violet-500/10 text-violet-400">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
              </svg>
            </div>
            <h3 class="text-[0.85rem] font-medium text-slate-300">Cobrado − pagos a técnicos (mes)</h3>
          </div>
          <div>
            <p class="text-[1.85rem] font-bold tracking-tight leading-none mb-1.5">
              <span :class="netAfterTechniciansClass">
                {{ netAfterTechnicians != null ? formatMoney(netAfterTechnicians) : '—' }}
              </span>
            </p>
            <p class="text-[0.75rem] font-medium text-slate-400">
              Cobrado {{ metrics?.received_month != null ? formatMoney(metrics.received_month) : '—' }} − abonos técn.
              {{ metrics?.technician_payouts_month != null ? formatMoney(metrics.technician_payouts_month) : '—' }}
            </p>
          </div>
        </article>

        <article class="bg-[#1e2532]/80 rounded-2xl p-6 shadow-lg shadow-black/20 flex flex-col justify-between min-h-[120px] border border-dashed border-slate-600/50">
          <div class="flex items-center gap-3 mb-2">
            <div class="flex-shrink-0 h-9 w-9 flex items-center justify-center rounded-lg bg-slate-600/20 text-slate-500">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
              </svg>
            </div>
            <h3 class="text-[0.85rem] font-medium text-slate-500">En construcción</h3>
          </div>
          <div>
            <p class="text-[1.25rem] font-semibold text-slate-500 leading-snug">Próximamente</p>
            <p class="text-[0.75rem] text-slate-600 mt-2">Reservado para otra métrica.</p>
          </div>
        </article>

        <article class="bg-[#1e2532]/80 rounded-2xl p-6 shadow-lg shadow-black/20 flex flex-col justify-between min-h-[120px] border border-dashed border-slate-600/50">
          <div class="flex items-center gap-3 mb-2">
            <div class="flex-shrink-0 h-9 w-9 flex items-center justify-center rounded-lg bg-slate-600/20 text-slate-500">
              <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
              </svg>
            </div>
            <h3 class="text-[0.85rem] font-medium text-slate-500">En construcción</h3>
          </div>
          <div>
            <p class="text-[1.25rem] font-semibold text-slate-500 leading-snug">Próximamente</p>
            <p class="text-[0.75rem] text-slate-600 mt-2">Reservado para otra métrica.</p>
          </div>
        </article>
      </div>
    </template>

    <AdminDashboardPendingInvoicesPanel
      :open="pendingInvoicesPanelOpen"
      @close="closePendingInvoicesPanel"
      @changed="loadDashboard"
    />

    <AdminDashboardServicesPanel
      :open="servicesPanelOpen"
      :year="data?.period?.year"
      :month="data?.period?.month"
      :period-label="data?.period?.label || ''"
      @close="closeServicesPanel"
    />

    <AdminDashboardTechnicianPendingPanel
      :open="technicianPendingPanelOpen"
      :year="data?.period?.year"
      :month="data?.period?.month"
      :period-label="data?.period?.label || ''"
      @close="closeTechnicianPendingPanel"
      @changed="loadDashboard"
    />
  </div>
</template>

<style scoped>
/* CSS Reset Minimalista para ApexCharts interior */
:deep(.apexcharts-tooltip) {
  background: #1e2532 !important;
  border: 1px solid rgba(148, 163, 184, 0.2) !important;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.5) !important;
  color: #f1f5f9 !important;
  border-radius: 8px !important;
}
:deep(.apexcharts-tooltip-title) {
  background: #1c212c !important;
  border-bottom: 1px solid rgba(148, 163, 184, 0.2) !important;
  font-family: inherit !important;
  font-weight: 600 !important;
  padding: 8px 12px !important;
}
:deep(.apexcharts-tooltip-series-group) {
  padding: 8px 12px !important;
  font-family: inherit !important;
}
</style>
