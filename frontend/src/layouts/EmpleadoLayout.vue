<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const displayName = computed(() => auth.user?.nombre || 'Empleado')

/** Ej. "Javier García" → "Historial Javier G." */
const historialBarLabel = computed(() => {
  const n = (auth.user?.nombre || '').trim()
  if (!n) return 'Historial'
  const parts = n.split(/\s+/).filter(Boolean)
  if (parts.length === 1) return `Historial ${parts[0]}`
  const first = parts[0]
  const last = parts[parts.length - 1]
  const initial = last.charAt(0).toUpperCase()
  return `Historial ${first} ${initial}.`
})

const showPanelLink = computed(() => route.path !== '/empleado')

async function salir() {
  await auth.logout()
  await router.replace('/login')
}
</script>

<template>
  <div class="min-h-screen bg-[#0b0f14] text-slate-200">
    <!-- Barra superior como mockup (logo + contexto) -->
    <header
      class="sticky top-0 z-20 flex items-center justify-between border-b border-slate-800/80 bg-[#0f1419]/95 px-4 py-3 shadow-sm shadow-black/20 backdrop-blur-md sm:px-6"
    >
      <div class="flex min-w-0 flex-1 items-center gap-3 sm:gap-5">
        <div class="flex items-center gap-2.5">
          <div
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-sky-400 to-blue-600 text-white shadow-[0_0_20px_rgba(56,189,248,0.35)]"
            aria-hidden="true"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M3 3v18h18M7 16l4-4 4 4 6-6" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
          </div>
          <span class="text-sm font-bold tracking-tight text-white sm:text-base">HBM</span>
        </div>
        <span class="hidden text-slate-600 md:inline" aria-hidden="true">|</span>
        <p class="hidden max-w-[200px] truncate text-sm font-medium text-slate-400 md:block md:max-w-xs lg:max-w-md">
          {{ historialBarLabel }}
        </p>
        <nav class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.7rem] font-semibold sm:gap-x-3 sm:text-xs">
          <RouterLink
            to="/empleado"
            class="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-800/80 hover:text-sky-300"
            :class="{ 'bg-slate-800/60 text-sky-300': route.path === '/empleado' }"
          >
            Panel
          </RouterLink>
          <RouterLink
            to="/empleado/historial"
            class="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-800/80 hover:text-sky-300"
            :class="{ 'bg-slate-800/60 text-sky-300': route.path.startsWith('/empleado/historial') }"
          >
            Historial
          </RouterLink>
          <RouterLink
            to="/empleado/listado-servicios"
            class="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-800/80 hover:text-sky-300"
            :class="{ 'bg-slate-800/60 text-sky-300': route.path.startsWith('/empleado/listado-servicios') }"
          >
            Listado
          </RouterLink>
          <RouterLink
            to="/empleado/registro-servicio"
            class="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-800/80 hover:text-sky-300"
            :class="{ 'bg-slate-800/60 text-sky-300': route.path.startsWith('/empleado/registro-servicio') }"
          >
            Registrar
          </RouterLink>
        </nav>
        <RouterLink
          v-if="showPanelLink"
          to="/empleado"
          class="ml-auto shrink-0 text-xs font-semibold text-sky-400/80 hover:text-sky-300 md:hidden"
        >
          ← Panel
        </RouterLink>
      </div>
      <div class="flex shrink-0 items-center gap-2 sm:gap-3">
        <span
          class="hidden max-w-[140px] truncate rounded-full border border-slate-700/80 bg-slate-800/50 px-3 py-1 text-xs text-slate-300 sm:inline"
        >
          {{ displayName }}
        </span>
        <button
          type="button"
          class="rounded-xl border border-slate-600/80 bg-slate-800/40 px-3 py-1.5 text-xs font-semibold text-slate-200 transition hover:border-slate-500 hover:bg-slate-800 sm:px-4 sm:py-2 sm:text-sm"
          @click="salir"
        >
          Salir
        </button>
      </div>
    </header>

    <main class="mx-auto max-w-[1200px] px-4 py-6 sm:px-6 sm:py-8">
      <RouterView />
    </main>
  </div>
</template>
