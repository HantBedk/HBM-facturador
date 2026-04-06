<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import AdminNotificationBell from '@/components/AdminNotificationBell.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const displayName = computed(() => auth.user?.nombre || 'Administrador')

/** Primer nombre para el saludo tipo "Hola, Javier!" */
const firstName = computed(() => {
  const n = (auth.user?.nombre || '').trim()
  if (!n) return 'administrador'
  return n.split(/\s+/)[0]
})

const now = ref(new Date())
let timeInterval = null

const fechaLinea = computed(() =>
  now.value.toLocaleString('es-CO', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
)

onMounted(() => {
  timeInterval = setInterval(() => {
    now.value = new Date()
  }, 30000)
})

onUnmounted(() => {
  if (timeInterval) clearInterval(timeInterval)
})

async function salir() {
  await auth.logout()
  await router.replace('/login')
}
</script>

<template>
  <div class="min-h-screen bg-[#13161f] text-slate-300 flex overflow-hidden font-sans">
    
    <!-- BARRA LATERAL (SIDEBAR) -->
    <aside class="w-[260px] flex-shrink-0 bg-[#1c212c] border-r border-slate-700/50 flex-col hidden lg:flex shadow-2xl z-20">
      
      <!-- Logo Superior -->
      <div class="h-[76px] px-6 flex items-center gap-3 border-b border-slate-700/30">
        <div class="h-8 w-8 rounded-lg bg-gradient-to-br from-blue-400 to-indigo-600 flex items-center justify-center flex-shrink-0 shadow-lg shadow-blue-500/20">
          <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
             <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
        </div>
        <span class="text-[1.15rem] font-bold text-white tracking-wide">HBM Admin</span>
      </div>

      <!-- Perfil de Usuario Lateral -->
      <div class="px-6 py-5 flex items-center gap-3">
        <div class="h-10 w-10 flex-shrink-0 rounded-full bg-slate-700 border border-slate-600 flex items-center justify-center overflow-hidden">
           <svg class="h-6 w-6 text-slate-300" fill="currentColor" viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
        </div>
        <div class="overflow-hidden flex-1">
          <p class="text-[0.95rem] font-bold text-white truncate m-0 leading-tight">{{ displayName }}</p>
          <p class="text-[0.75rem] text-slate-400 m-0 mt-0.5 font-medium">Administrador</p>
        </div>
        <div>
           <svg class="h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" /></svg>
        </div>
      </div>

      <!-- Menú de Navegación -->
      <nav class="flex-1 px-4 py-2 space-y-1.5 overflow-y-auto">
        
        <!-- Dashboard (Azul) -->
        <RouterLink 
          to="/admin" 
          :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path === '/admin' ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']"
        >
          <div :class="['flex items-center justify-center p-1', route.path === '/admin' ? 'text-white' : 'text-blue-500 group-hover:text-blue-400']">
            <svg class="h-[1.15rem] w-[1.15rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z" /></svg>
          </div>
          <span>Dashboard</span>
        </RouterLink>

        <!-- Servicios (Rosa/Fuchsia) -->
        <RouterLink 
          to="/admin/servicios" 
          :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors mt-2', route.path.startsWith('/admin/servicios') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']"
        >
          <div :class="['flex items-center justify-center p-1', route.path.startsWith('/admin/servicios') ? 'text-white' : 'text-fuchsia-400 group-hover:text-fuchsia-300']">
            <svg class="h-[1.15rem] w-[1.15rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
          </div>
          <span>Servicios</span>
        </RouterLink>

        <RouterLink
          to="/admin/catalogo-servicios"
          :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.startsWith('/admin/catalogo-servicios') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']"
        >
          <div :class="['flex items-center justify-center p-1', route.path.startsWith('/admin/catalogo-servicios') ? 'text-white' : 'text-teal-400 group-hover:text-teal-300']">
            <svg class="h-[1.15rem] w-[1.15rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" /></svg>
          </div>
          <span>Catálogo</span>
        </RouterLink>

        <!-- Facturas (Cyan) -->
        <RouterLink 
          to="/admin/facturas" 
          :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.startsWith('/admin/facturas') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']"
        >
          <div :class="['flex items-center justify-center p-1', route.path.startsWith('/admin/facturas') ? 'text-white' : 'text-cyan-400 group-hover:text-cyan-300']">
            <svg class="h-[1.15rem] w-[1.15rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" /></svg>
          </div>
          <span>Facturas</span>
        </RouterLink>

        <!-- Empresas (Verde Esmeralda) -->
        <RouterLink 
          to="/admin/empresas" 
          :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.startsWith('/admin/empresas') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']"
        >
          <div :class="['flex items-center justify-center p-1', route.path.startsWith('/admin/empresas') ? 'text-white' : 'text-emerald-400 group-hover:text-emerald-300']">
            <svg class="h-[1.15rem] w-[1.15rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" /></svg>
          </div>
          <span>Empresas</span>
        </RouterLink>

        <!-- Empleados: historial y rendimiento por técnico -->
        <RouterLink
          to="/admin/empleados/rendimiento"
          :class="[
            'group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors',
            route.path.startsWith('/admin/empleados/rendimiento')
              ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20'
              : 'text-slate-300 hover:bg-slate-800/50 hover:text-white',
          ]"
        >
          <div
            :class="[
              'flex items-center justify-center p-1',
              route.path.startsWith('/admin/empleados/rendimiento')
                ? 'text-white'
                : 'text-amber-400 group-hover:text-amber-300',
            ]"
          >
            <svg class="h-[1.15rem] w-[1.15rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"
              />
            </svg>
          </div>
          <span>Empleados</span>
        </RouterLink>

        <!-- Configuración: cuentas del equipo (altas, roles, estados) -->
        <RouterLink
          to="/admin/configuracion/notificaciones-tecnicos"
          :class="[
            'group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors',
            route.path.startsWith('/admin/configuracion') || /^\/admin\/empleados\/\d+\/perfil$/.test(route.path)
              ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20'
              : 'text-slate-300 hover:bg-slate-800/50 hover:text-white',
          ]"
        >
          <div
            :class="[
              'flex items-center justify-center p-1',
              route.path.startsWith('/admin/configuracion') || /^\/admin\/empleados\/\d+\/perfil$/.test(route.path)
                ? 'text-white'
                : 'text-purple-400 group-hover:text-purple-300',
            ]"
          >
            <svg class="h-[1.15rem] w-[1.15rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
              />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
          </div>
          <span>Configuración</span>
        </RouterLink>

      </nav>
    </aside>

    <!-- ÁREA PRINCIPAL CONTENT -->
    <div class="flex-1 flex flex-col min-w-0 bg-[#13161f]">
      
      <!-- TOPBAR: saludo izquierda · búsqueda + acciones derecha (referencia mockup) -->
      <header
        class="min-h-[76px] px-4 sm:px-6 py-2.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-slate-700/30 bg-[#13161f] z-10 sticky top-0"
      >
        <!-- Izquierda: móvil + saludo -->
        <div class="flex items-center gap-3 sm:gap-4 min-w-0 flex-1 lg:flex-none lg:max-w-[min(100%,28rem)]">
          <div class="flex items-center gap-3 shrink-0 lg:hidden">
            <button type="button" class="text-slate-400 hover:text-white transition-colors" aria-label="Abrir menú">
              <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
              </svg>
            </button>
            <span class="text-lg font-bold text-white tracking-wide">HBM</span>
          </div>
          <div class="min-w-0">
            <p class="text-lg sm:text-xl font-bold text-white leading-tight truncate capitalize">
              Hola, {{ firstName }}!
            </p>
            <p class="text-[0.7rem] sm:text-[0.8rem] text-slate-500 mt-0.5 leading-snug first-letter:uppercase">
              {{ fechaLinea }}
            </p>
          </div>
        </div>

        <!-- Derecha: búsqueda (icono a la derecha) + notificaciones + salir -->
        <div class="flex items-center gap-3 sm:gap-4 w-full sm:w-auto flex-1 sm:flex-none justify-end min-w-0">
          <form
            role="search"
            class="relative w-full max-w-[340px] hidden sm:block"
            autocomplete="off"
            @submit.prevent
          >
            <input
              type="search"
              name="q"
              enterkeyhint="search"
              autocomplete="search"
              autocorrect="off"
              autocapitalize="off"
              spellcheck="false"
              data-lpignore="true"
              data-1p-ignore="true"
              data-bwignore="true"
              aria-label="Buscar en servicios y facturas"
              placeholder="Buscar servicios, facturas..."
              class="w-full bg-[#1c212c] border border-slate-700/50 rounded-xl py-2.5 pl-4 pr-11 text-sm text-slate-300 placeholder:text-slate-500 outline-none focus:border-slate-500 transition-colors shadow-inner"
            />
            <svg
              class="pointer-events-none absolute right-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-500"
              fill="none"
              viewBox="0 0 24 24"
              stroke="currentColor"
              aria-hidden="true"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"
              />
            </svg>
          </form>

          <div class="flex items-center gap-3 sm:gap-5 text-slate-400 shrink-0 sm:border-r sm:border-slate-700/50 sm:pr-5">
            <AdminNotificationBell />
            <button type="button" class="hover:text-white transition-colors hidden sm:block" aria-label="Ajustes">
              <svg class="h-[1.3rem] w-[1.3rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </button>
          </div>

          <button
            type="button"
            class="flex shrink-0 items-center gap-2 rounded-xl border border-slate-700/60 bg-[#1c212c] px-3 py-2 text-[0.85rem] font-semibold text-slate-300 shadow-sm transition-colors hover:border-red-500/30 hover:bg-red-500/10 hover:text-red-400 sm:px-4"
            @click="salir"
          >
            <span>Salir</span>
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
              />
            </svg>
          </button>
        </div>
      </header>

      <!--
        Contenido principal + hueco para panel derecho (drawer/preview).
        min-w-0 / min-h-0 permiten que la zona central se contraiga cuando exista un aside
        (p. ej. w-96 shrink-0 border-l) sin desbordar el flex.
      -->
      <div class="flex min-h-0 min-w-0 flex-1 flex-col lg:flex-row">
        <main
          class="min-h-0 min-w-0 w-full flex-1 overflow-y-auto bg-transparent px-4 pt-5 pb-6 sm:px-6 sm:pt-6 sm:pb-8"
        >
          <RouterView />
        </main>
        <!-- Panel derecho temporal: <aside class="hidden lg:block w-[min(100%,22rem)] shrink-0 border-l ..."> -->
      </div>

    </div>
  </div>
</template>

<style scoped>
/* Scoped limpio. Todo Tailwind */
</style>
