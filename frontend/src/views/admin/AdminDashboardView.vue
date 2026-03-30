<script setup>
import { onMounted, ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/services/api.js'

const auth = useAuthStore()

const loading = ref(true)
const loadError = ref('')
const data = ref(null)

const INVOICE_STATUS_ROWS = [
  { key: 'borrador', label: 'Borrador', hint: 'Pte. enviar', iconColor: 'text-slate-400', bg: 'bg-slate-500/10', icon: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z' },
  { key: 'aprobada', label: 'Aprobadas', hint: 'Validadas', iconColor: 'text-emerald-400', bg: 'bg-emerald-500/10', icon: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z' },
  { key: 'enviada', label: 'Enviadas', hint: 'Al cliente', iconColor: 'text-blue-400', bg: 'bg-blue-500/10', icon: 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z' },
  { key: 'parcialmente_pagada', label: 'Pago Parcial', hint: 'Incompleto', iconColor: 'text-amber-400', bg: 'bg-amber-500/10', icon: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z' },
  { key: 'pagada', label: 'Pagadas', hint: 'Completadas', iconColor: 'text-emerald-500', bg: 'bg-emerald-500/20', icon: 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z' },
]

async function loadDashboard() {
  loading.value = true
  loadError.value = ''
  try {
    data.value = await api('/admin/dashboard')
  } catch (e) {
    loadError.value =
      e.data?.message || e.message || 'No se pudieron cargar los datos del dashboard.'
    data.value = null
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadDashboard()
})

const metrics = computed(() => data.value?.metrics)
const period = computed(() => data.value?.period)
const statusCounts = computed(() => data.value?.invoice_status_counts || {})
const recent = computed(() => data.value?.recent || { services: [], invoices: [], payments: [] })

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

function formatDateTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString('es-CO', {
    dateStyle: 'short',
    timeStyle: 'short',
  })
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso.length === 10 ? `${iso}T12:00:00` : iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { dateStyle: 'medium' })
}
</script>

<template>
  <div class="w-full h-full p-4 sm:p-6 lg:p-8 bg-transparent text-slate-200 font-sans">
    
    <!-- ENCABEZADO IDÉNTICO AL MOCKUP (Texto gigante blanco) -->
    <header class="flex flex-col sm:flex-row sm:items-start justify-between gap-4 mb-8">
      <div>
        <h1 class="text-3xl font-bold text-white tracking-wide mb-1">Hola, {{ auth.user?.nombre || 'Administrador' }}!</h1>
        <p class="text-sm text-slate-400 m-0">
          {{ period?.label || 'Resumen del negocio' }}
        </p>
      </div>
      <button 
        type="button" 
        @click="loadDashboard" 
        :disabled="loading"
        class="inline-flex items-center gap-2 rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-blue-500 transition-colors disabled:opacity-70 border border-blue-500"
      >
        <svg v-if="loading" class="animate-spin h-4 w-4 text-white" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
        <svg v-else class="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" /></svg>
        <span>{{ loading ? 'Actualizando...' : 'Actualizar' }}</span>
      </button>
    </header>

    <div v-if="loadError && !data" class="rounded-xl bg-red-500/10 border border-red-500/20 p-4 mb-8">
      <div class="flex">
        <svg class="h-5 w-5 text-red-400 mr-3 mt-0.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" /></svg>
        <div>
          <h3 class="text-sm font-medium text-red-400">Error de conexión</h3>
          <p class="mt-1 text-sm text-red-300">{{ loadError }}</p>
        </div>
      </div>
    </div>

    <!-- SKELETON OSCURO -->
    <div v-else-if="loading && !data" class="animate-pulse space-y-6">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div v-for="i in 4" :key="i" class="h-[120px] bg-[#1e2532] rounded-2xl border border-slate-700/50"></div>
      </div>
      <div class="h-64 bg-[#1e2532] rounded-2xl border border-slate-700/50"></div>
    </div>

    <template v-else>
      <div v-if="loadError" class="rounded-xl bg-amber-500/10 p-4 mb-8 text-sm text-amber-300 flex items-center border border-amber-500/20">
        <svg class="h-5 w-5 text-amber-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
        Mostrando memoria caché: {{ loadError }}
      </div>

      <!-- ROW 1: 4 MÉTICAS OSCURAS (Idéntico a Dashboard.png) -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
        <!-- Tarjeta 1: Total Facturado (Monedas doradas) -->
        <article class="bg-[#1e2532] rounded-[18px] p-5 border border-slate-700/40 shadow-lg">
          <div class="flex items-center gap-3 mb-3">
             <div class="flex-shrink-0 h-[34px] w-[34px] flex items-center justify-center rounded-lg bg-yellow-500/10 text-yellow-500">
               <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
             </div>
             <h3 class="text-[0.8rem] font-medium text-slate-300">Total Facturado (Este Mes)</h3>
          </div>
          <p class="text-[1.7rem] font-bold text-white tracking-tight">{{ formatMoney(metrics?.invoiced_month) }}</p>
          <p class="text-[0.7rem] text-emerald-400 mt-1 flex items-center font-semibold">
             <svg class="h-3 w-3 mr-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 10l7-7m0 0l7 7m-7-7v18" /></svg>
             Ingresos brutos
          </p>
        </article>

        <!-- Tarjeta 2: Servicios Realizados (Engranaje azul) -->
        <article class="bg-[#1e2532] rounded-[18px] p-5 border border-slate-700/40 shadow-lg">
          <div class="flex items-center gap-3 mb-3">
             <div class="flex-shrink-0 h-[34px] w-[34px] flex items-center justify-center rounded-lg bg-blue-500/10 text-blue-500">
               <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
             </div>
             <h3 class="text-[0.8rem] font-medium text-slate-300">Recibido (Mes)</h3>
          </div>
          <p class="text-[1.7rem] font-bold text-white tracking-tight">{{ formatMoney(metrics?.received_month) }}</p>
          <p class="text-[0.7rem] text-emerald-400 mt-1 flex items-center font-semibold">
            Dinero real ingresado
          </p>
        </article>

        <!-- Tarjeta 3: Facturas Pendientes (Reloj rojo) -->
        <article class="bg-[#1e2532] rounded-[18px] p-5 border border-slate-700/40 shadow-lg">
          <div class="flex items-center gap-3 mb-3">
             <div class="flex-shrink-0 h-[34px] w-[34px] flex items-center justify-center rounded-lg bg-red-500/10 text-red-500">
               <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
             </div>
             <h3 class="text-[0.8rem] font-medium text-slate-300">Facturas Pendientes</h3>
          </div>
          <p class="text-[1.7rem] font-bold text-white tracking-tight">{{ formatMoney(metrics?.pending_collect) }}</p>
          <p class="text-[0.7rem] text-slate-400 mt-1 font-medium">Saldo por cobrar abierto</p>
        </article>

        <!-- Tarjeta 4: Facturas Pagadas (Check verde) -->
        <article class="bg-[#1e2532] rounded-[18px] p-5 border border-slate-700/40 shadow-lg">
          <div class="flex items-center gap-3 mb-3">
             <div class="flex-shrink-0 h-[34px] w-[34px] flex items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-500">
               <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
             </div>
             <h3 class="text-[0.8rem] font-medium text-slate-300">Volumen Facturas</h3>
          </div>
          <p class="text-[1.7rem] font-bold text-white tracking-tight">{{ metrics?.invoices_count_month ?? 0 }}</p>
          <p class="text-[0.7rem] text-slate-400 mt-1 font-medium">Cantidad periodo actual</p>
        </article>
      </div>

      <!-- ROW 2: Accesos Directos Opcionales integrados en el dark mode de forma elegante -->
      <div class="mb-8">
        <h2 class="text-xs font-semibold text-slate-400 mb-3 tracking-widest uppercase">Accesos Rápidos</h2>
        <div class="flex flex-wrap items-center gap-3">
          <RouterLink to="/admin/facturas/nueva" class="rounded-lg bg-blue-600 text-white px-5 py-2 text-[0.85rem] font-semibold hover:bg-blue-500 transition-colors shadow-lg shadow-blue-500/20">
            Crear factura
          </RouterLink>
          <RouterLink to="/admin/servicios" class="rounded-lg bg-slate-800 text-slate-300 border border-slate-700/50 px-5 py-2 text-[0.85rem] font-medium hover:bg-slate-700 transition-colors">
            Ver servicios
          </RouterLink>
          <RouterLink to="/admin/empresas" class="rounded-lg bg-slate-800 text-slate-300 border border-slate-700/50 px-5 py-2 text-[0.85rem] font-medium hover:bg-slate-700 transition-colors">
            Empresas
          </RouterLink>
          <RouterLink to="/admin/empleados" class="rounded-lg bg-slate-800 text-slate-300 border border-slate-700/50 px-5 py-2 text-[0.85rem] font-medium hover:bg-slate-700 transition-colors">
            Personal
          </RouterLink>
          <RouterLink to="/admin/empleados/rendimiento" class="rounded-lg bg-slate-800 text-slate-300 border border-slate-700/50 px-5 py-2 text-[0.85rem] font-medium hover:bg-slate-700 transition-colors">
            Rendimiento
          </RouterLink>
        </div>
      </div>

      <!-- ROW 3: TABLAS INFERIORES FRESCAS (Sin bordes bruscos, idénticas a Dashboard.png) -->
      <div class="grid grid-cols-1 xl:grid-cols-[1.5fr_1fr] gap-6">
        
        <!-- Izquierda: Últimos Servicios y Actividad -->
        <section class="bg-[#1e2532] rounded-2xl shadow-sm border border-slate-700/40 overflow-hidden self-start">
          <div class="px-7 py-5">
            <h2 class="text-[1.05rem] font-bold text-white tracking-wide m-0">Últimos Servicios Registrados</h2>
          </div>

          <div class="overflow-x-auto px-7 pb-6">
            <table class="w-full text-left border-collapse">
              <thead>
                <tr>
                  <th class="text-[0.7rem] font-semibold text-slate-400 uppercase tracking-widest pb-3 border-b border-slate-700/50">Código</th>
                  <th class="text-[0.7rem] font-semibold text-slate-400 uppercase tracking-widest pb-3 border-b border-slate-700/50">Empresa</th>
                  <th class="text-[0.7rem] font-semibold text-slate-400 uppercase tracking-widest pb-3 border-b border-slate-700/50 hidden sm:table-cell">Empleado</th>
                  <th class="text-[0.7rem] font-semibold text-slate-400 uppercase tracking-widest pb-3 border-b border-slate-700/50 text-right">Fecha</th>
                </tr>
              </thead>
              <tbody v-if="recent.services?.length" class="divide-y divide-slate-700/30">
                <tr v-for="s in recent.services" :key="s.id" class="hover:bg-slate-800/50 transition-colors group">
                  <td class="py-3.5 pr-4">
                    <RouterLink :to="`/admin/servicios/${s.id}`" class="text-[0.9rem] font-medium text-slate-200 group-hover:text-blue-400 transition-colors">
                      {{ s.code }}
                    </RouterLink>
                  </td>
                  <td class="py-3.5 pr-4 text-[0.85rem] text-slate-400 whitespace-nowrap">{{ s.company_name || 'Sin empresa' }}</td>
                  <td class="py-3.5 pr-4 text-[0.85rem] text-slate-400 whitespace-nowrap hidden sm:table-cell">{{ s.user_name || 'Desconocido' }}</td>
                  <td class="py-3.5 text-[0.85rem] text-slate-400 text-right whitespace-nowrap">{{ formatDateTime(s.created_at) }}</td>
                </tr>
              </tbody>
              <tbody v-else>
                <tr><td colspan="4" class="py-6 text-center text-slate-500 text-sm">Sin servicios recientes.</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Derecha: Estado de facturación/Recientes -->
        <section class="bg-[#1e2532] rounded-2xl shadow-sm border border-slate-700/40 overflow-hidden sticky top-6 self-start">
          <div class="px-7 py-5">
            <h2 class="text-[1.05rem] font-bold text-white tracking-wide m-0">Estado de Facturación</h2>
          </div>
          
          <div class="px-7 pb-6">
            <table class="w-full text-left border-collapse">
               <thead>
                 <tr>
                   <th class="text-[0.7rem] font-semibold text-slate-400 uppercase tracking-widest pb-3 border-b border-slate-700/50">Estado</th>
                   <th class="text-[0.7rem] font-semibold text-slate-400 uppercase tracking-widest pb-3 border-b border-slate-700/50 text-right">Monto (Cant.)</th>
                 </tr>
               </thead>
               <tbody class="divide-y divide-slate-700/30">
                 <tr v-for="row in INVOICE_STATUS_ROWS" :key="row.key" class="hover:bg-slate-800/50 transition-colors">
                   <td class="py-3.5 pr-4">
                      <span :class="['inline-flex items-center px-2.5 py-1 rounded border border-transparent text-[0.75rem] font-semibold', row.bg, row.iconColor]">
                         {{ row.label }}
                      </span>
                   </td>
                   <td class="py-3.5 text-right font-bold text-[0.95rem] text-white">
                      {{ statusCounts[row.key] ?? 0 }}
                   </td>
                 </tr>
               </tbody>
            </table>

            <!-- Divider -->
            <div class="mt-6 pt-4 border-t border-slate-700/50">
               <h3 class="text-[0.7rem] font-semibold text-slate-400 uppercase tracking-widest mb-4">Facturas Recientes</h3>
               <table class="w-full text-left border-collapse">
                 <tbody v-if="recent.invoices?.length" class="divide-y divide-slate-700/30">
                    <tr v-for="inv in recent.invoices" :key="inv.id" class="hover:bg-slate-800/50 transition-colors">
                      <td class="py-2.5 pr-2">
                        <p class="text-[0.8rem] text-slate-300 font-medium">{{ inv.code }}</p>
                        <p class="text-[0.7rem] text-slate-500">{{ inv.company_name || 'S/N' }}</p>
                      </td>
                      <td class="py-2.5 text-right">
                         <p class="text-[0.8rem] text-slate-200 font-bold">{{ formatMoney(inv.total) }}</p>
                         <span class="text-[0.65rem] text-slate-400">{{ formatDateTime(inv.created_at) }}</span>
                      </td>
                    </tr>
                 </tbody>
                 <tbody v-else>
                    <tr><td colspan="2" class="py-4 text-center text-slate-500 text-xs">Sin facturas recientes.</td></tr>
                 </tbody>
               </table>
            </div>
          </div>
        </section>

      </div>
    </template>
  </div>
</template>

<style scoped>
/* Scoped para proteger tu UI global. Todo controlado internamente. */
</style>
