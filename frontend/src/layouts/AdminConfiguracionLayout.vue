<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'

const route = useRoute()

const isNotif = computed(() => route.name === 'admin-notificaciones-tecnicos')
const isHistorial = computed(() => route.name === 'admin-config-historial')
const isBillingAuto = computed(() => route.name === 'admin-config-billing-automation')
const isMailNotif = computed(() => route.name === 'admin-config-mail-notifications')
const isSystemOrg = computed(() => route.name === 'admin-config-system-organization')
const isInventoryConfig = computed(() => route.name === 'admin-config-inventario')
const isServicesData = computed(() => route.name === 'admin-config-services-data')
</script>

<template>
  <div class="admin-config px-4 py-4 sm:px-6 lg:px-8">
    <div class="mb-6 border-b border-slate-700/50 pb-5">
      <p class="mb-3 text-[0.75rem] font-semibold uppercase tracking-wide text-slate-500">Configuración</p>
      <!-- Pestañas: 2×2 en móvil y fila equitativa en pantallas grandes; bandeja única para agrupar -->
      <nav
        class="w-full rounded-2xl border border-slate-700/55 bg-slate-900/45 p-1 shadow-[inset_0_1px_0_rgba(255,255,255,0.04)]"
        aria-label="Secciones de configuración"
      >
        <div class="flex flex-wrap gap-1">
          <RouterLink
            to="/admin/configuracion/empresa-sistema"
            class="flex min-h-[2.75rem] min-w-[calc(50%-0.125rem)] flex-1 basis-[calc(50%-0.125rem)] items-center justify-center rounded-xl px-2 py-2.5 text-center text-sm font-medium leading-snug transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500/80 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0f172a] sm:min-w-0 sm:basis-0 sm:px-3"
            :class="
              isSystemOrg
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25 sm:shadow-sm'
                : 'text-slate-400 hover:bg-slate-800/70 hover:text-white active:scale-[0.98] sm:hover:bg-slate-800/50'
            "
            :aria-current="isSystemOrg ? 'page' : undefined"
          >
            Empresa sistema
          </RouterLink>
          <RouterLink
            to="/admin/configuracion/notificaciones-tecnicos"
            class="flex min-h-[2.75rem] min-w-[calc(50%-0.125rem)] flex-1 basis-[calc(50%-0.125rem)] items-center justify-center rounded-xl px-2 py-2.5 text-center text-sm font-medium leading-snug transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500/80 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0f172a] sm:min-w-0 sm:basis-0 sm:px-3"
            :class="
              isNotif
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25 sm:shadow-sm'
                : 'text-slate-400 hover:bg-slate-800/70 hover:text-white active:scale-[0.98] sm:hover:bg-slate-800/50'
            "
            :aria-current="isNotif ? 'page' : undefined"
          >
            Avisos a técnicos
          </RouterLink>
          <RouterLink
            to="/admin/configuracion/historial"
            class="flex min-h-[2.75rem] min-w-[calc(50%-0.125rem)] flex-1 basis-[calc(50%-0.125rem)] items-center justify-center rounded-xl px-2 py-2.5 text-center text-sm font-medium leading-snug transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500/80 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0f172a] sm:min-w-0 sm:basis-0 sm:px-3"
            :class="
              isHistorial
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25 sm:shadow-sm'
                : 'text-slate-400 hover:bg-slate-800/70 hover:text-white active:scale-[0.98] sm:hover:bg-slate-800/50'
            "
            :aria-current="isHistorial ? 'page' : undefined"
          >
            Historial
          </RouterLink>
          <RouterLink
            to="/admin/configuracion/correo-notificaciones"
            class="flex min-h-[2.75rem] min-w-[calc(50%-0.125rem)] flex-1 basis-[calc(50%-0.125rem)] items-center justify-center rounded-xl px-2 py-2.5 text-center text-sm font-medium leading-snug transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500/80 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0f172a] sm:min-w-0 sm:basis-0 sm:px-3"
            :class="
              isMailNotif
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25 sm:shadow-sm'
                : 'text-slate-400 hover:bg-slate-800/70 hover:text-white active:scale-[0.98] sm:hover:bg-slate-800/50'
            "
            :aria-current="isMailNotif ? 'page' : undefined"
          >
            Correo sistema
          </RouterLink>
          <RouterLink
            to="/admin/configuracion/inventario"
            class="flex min-h-[2.75rem] min-w-[calc(50%-0.125rem)] flex-1 basis-[calc(50%-0.125rem)] items-center justify-center rounded-xl px-2 py-2.5 text-center text-sm font-medium leading-snug transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500/80 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0f172a] sm:min-w-0 sm:basis-0 sm:px-3"
            :class="
              isInventoryConfig
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25 sm:shadow-sm'
                : 'text-slate-400 hover:bg-slate-800/70 hover:text-white active:scale-[0.98] sm:hover:bg-slate-800/50'
            "
            :aria-current="isInventoryConfig ? 'page' : undefined"
          >
            Inventario
          </RouterLink>
          <RouterLink
            to="/admin/configuracion/servicios-datos"
            class="flex min-h-[2.75rem] min-w-[calc(50%-0.125rem)] flex-1 basis-[calc(50%-0.125rem)] items-center justify-center rounded-xl px-2 py-2.5 text-center text-sm font-medium leading-snug transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500/80 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0f172a] sm:min-w-0 sm:basis-0 sm:px-3"
            :class="
              isServicesData
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25 sm:shadow-sm'
                : 'text-slate-400 hover:bg-slate-800/70 hover:text-white active:scale-[0.98] sm:hover:bg-slate-800/50'
            "
            :aria-current="isServicesData ? 'page' : undefined"
          >
            Servicios CSV
          </RouterLink>
          <RouterLink
            to="/admin/configuracion/facturacion-automatica"
            class="flex min-h-[2.75rem] min-w-[calc(50%-0.125rem)] flex-1 basis-[calc(50%-0.125rem)] items-center justify-center rounded-xl px-2 py-2.5 text-center text-sm font-medium leading-snug transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500/80 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0f172a] sm:min-w-0 sm:basis-0 sm:px-3"
            :class="
              isBillingAuto
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25 sm:shadow-sm'
                : 'text-slate-400 hover:bg-slate-800/70 hover:text-white active:scale-[0.98] sm:hover:bg-slate-800/50'
            "
            :aria-current="isBillingAuto ? 'page' : undefined"
          >
            Facturación y Margen
          </RouterLink>
        </div>
      </nav>
    </div>
    <RouterView />
  </div>
</template>
