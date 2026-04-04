<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute } from 'vue-router'

const route = useRoute()

const isNotif = computed(() => route.name === 'admin-notificaciones-tecnicos')
const isHistorial = computed(() => route.name === 'admin-config-historial')
</script>

<template>
  <div class="admin-config px-4 py-4 sm:px-6 lg:px-8">
    <div class="mb-6 border-b border-slate-700/50 pb-4">
      <p class="mb-2 text-[0.75rem] font-semibold uppercase tracking-wide text-slate-500">Configuración</p>
      <nav class="flex flex-wrap gap-2" aria-label="Secciones de configuración">
        <RouterLink
          to="/admin/configuracion/notificaciones-tecnicos"
          class="rounded-xl px-3 py-2 text-sm font-medium transition"
          :class="
            isNotif
              ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
              : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'
          "
        >
          Avisos a técnicos
        </RouterLink>
        <RouterLink
          to="/admin/configuracion/historial"
          class="rounded-xl px-3 py-2 text-sm font-medium transition"
          :class="
            isHistorial
              ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
              : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'
          "
        >
          Historial
        </RouterLink>
      </nav>
    </div>
    <RouterView />
  </div>
</template>
