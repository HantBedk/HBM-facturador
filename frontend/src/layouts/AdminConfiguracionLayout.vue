<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'

const route = useRoute()
const activeTab = computed(() => route.name)
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
            v-for="tab in [
              { to: '/admin/configuracion/empresa-sistema',       name: 'admin-config-system-organization',    label: 'Empresa sistema' },
              { to: '/admin/configuracion/notificaciones-tecnicos', name: 'admin-notificaciones-tecnicos',     label: 'Avisos a técnicos' },
              { to: '/admin/configuracion/historial',             name: 'admin-config-historial',              label: 'Historial' },
              { to: '/admin/configuracion/correo-notificaciones', name: 'admin-config-mail-notifications',    label: 'Correo sistema' },
              { to: '/admin/configuracion/inventario',            name: 'admin-config-inventario',             label: 'Inventario' },
              { to: '/admin/configuracion/servicios-datos',       name: 'admin-config-services-data',          label: 'Servicios CSV' },
              { to: '/admin/configuracion/facturacion-automatica', name: 'admin-config-billing-automation',   label: 'Facturación y Margen' },
              { to: '/admin/configuracion/respaldo',              name: 'admin-config-respaldo',               label: 'Respaldo' },
            ]"
            :key="tab.name"
            :to="tab.to"
            class="flex min-h-[2.75rem] min-w-[calc(50%-0.125rem)] flex-1 basis-[calc(50%-0.125rem)] items-center justify-center rounded-xl px-2 py-2.5 text-center text-sm font-medium leading-snug transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500/80 focus-visible:ring-offset-2 focus-visible:ring-offset-[#0f172a] sm:min-w-0 sm:basis-0 sm:px-3"
            :class="
              activeTab === tab.name
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/25 sm:shadow-sm'
                : 'text-slate-400 hover:bg-slate-800/70 hover:text-white active:scale-[0.98] sm:hover:bg-slate-800/50'
            "
            :aria-current="activeTab === tab.name ? 'page' : undefined"
          >
            {{ tab.label }}
          </RouterLink>
        </div>
      </nav>
    </div>
    <RouterView />
  </div>
</template>
