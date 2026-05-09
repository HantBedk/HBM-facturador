<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import AdminNotificationBell from '@/components/AdminNotificationBell.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

/** Primer nombre para el saludo tipo "Hola, Javier!" */
const firstName = computed(() => {
  const n = (auth.user?.nombre || '').trim()
  if (!n) return 'administrador'
  return n.split(/\s+/)[0]
})

const menuOpen = ref(false)
const menuWrap = ref(null)
const mobileNavOpen = ref(false)

const initials = computed(() => {
  const n = (auth.user?.nombre || '').trim()
  if (!n) return '?'
  const parts = n.split(/\s+/).filter(Boolean)
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})

/** Mismo criterio que empleado: nombre corto para cabecera del menú. */
const nombreCuentaCorta = computed(() => {
  const n = (auth.user?.nombre || '').trim()
  if (!n) return ''
  const parts = n.split(/\s+/).filter(Boolean)
  if (parts.length === 1) return parts[0]
  if (parts.length === 2) return `${parts[0]} ${parts[1]}`
  return `${parts[0]} ${parts[parts.length - 2]}`
})

const menuAriaLabel = computed(() =>
  nombreCuentaCorta.value ? `Menú de cuenta, ${nombreCuentaCorta.value}` : 'Menú de cuenta'
)

/** Sin ruta /admin/perfil: «Perfil» lleva al panel principal (resumen). */
const profileActive = computed(() => route.path === '/admin')
const configActive = computed(() => route.path.startsWith('/admin/configuracion'))

function itemClass(active) {
  return [
    'flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm transition',
    active
      ? 'border-l-[3px] border-l-sky-400 bg-slate-800/70 text-white'
      : 'border-l-[3px] border-l-transparent text-slate-200 hover:bg-slate-800/55 hover:text-white',
  ]
}

function onDocClick(e) {
  if (!menuWrap.value?.contains(e.target)) {
    menuOpen.value = false
  }
}

function onMenuEscape(e) {
  if (e.key !== 'Escape') return
  menuOpen.value = false
  mobileNavOpen.value = false
}

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
  document.addEventListener('click', onDocClick)
})

watch(menuOpen, (open) => {
  if (open) document.addEventListener('keydown', onMenuEscape)
  else document.removeEventListener('keydown', onMenuEscape)
})

watch(mobileNavOpen, (open) => {
  if (open) {
    document.addEventListener('keydown', onMenuEscape)
    document.body.style.overflow = 'hidden'
  } else {
    document.body.style.overflow = ''
  }
})

watch(
  () => route.fullPath,
  () => {
    mobileNavOpen.value = false
  }
)

onUnmounted(() => {
  if (timeInterval) clearInterval(timeInterval)
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onMenuEscape)
  document.body.style.overflow = ''
})

async function salir() {
  menuOpen.value = false
  await auth.logout()
  await router.replace('/login')
}
</script>

<template>
  <div class="min-h-screen bg-[#13161f] text-slate-300 flex overflow-x-hidden font-sans">
    
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
          to="/admin/mantenimientos"
          :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.includes('mantenimiento') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']"
        >
          <div :class="['flex items-center justify-center p-1', route.path.includes('mantenimiento') ? 'text-white' : 'text-teal-400 group-hover:text-teal-300']">
            <svg class="h-[1.15rem] w-[1.15rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.341A8 8 0 118.659 4.572M12 8v4l3 3" />
            </svg>
          </div>
          <span>Mantenimientos</span>
        </RouterLink>

        <RouterLink
          to="/admin/inventario"
          :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.startsWith('/admin/inventario') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']"
        >
          <div :class="['flex items-center justify-center p-1', route.path.startsWith('/admin/inventario') ? 'text-white' : 'text-violet-400 group-hover:text-violet-300']">
            <svg class="h-[1.15rem] w-[1.15rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
            </svg>
          </div>
          <span>Inventario interno</span>
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

      </nav>
    </aside>
    <Teleport to="body">
      <div
        v-if="mobileNavOpen"
        class="fixed inset-0 z-[70] bg-slate-950/65 lg:hidden"
        @click="mobileNavOpen = false"
      />
      <aside
        class="fixed inset-y-0 left-0 z-[80] w-[86vw] max-w-[18rem] bg-[#1c212c] border-r border-slate-700/50 flex flex-col shadow-2xl transition-transform duration-200 ease-out lg:hidden"
        :class="mobileNavOpen ? 'translate-x-0' : '-translate-x-full'"
        aria-label="Navegación móvil de administrador"
      >
        <div class="h-[76px] px-5 flex items-center justify-between gap-3 border-b border-slate-700/30">
          <div class="flex items-center gap-3 min-w-0">
            <div class="h-8 w-8 rounded-lg bg-gradient-to-br from-blue-400 to-indigo-600 flex items-center justify-center flex-shrink-0 shadow-lg shadow-blue-500/20">
              <svg class="h-5 w-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              </svg>
            </div>
            <span class="text-[1.05rem] font-bold text-white tracking-wide truncate">HBM Admin</span>
          </div>
          <button
            type="button"
            class="rounded-md p-1.5 text-slate-300 hover:bg-slate-800/60 hover:text-white"
            aria-label="Cerrar menú"
            @click="mobileNavOpen = false"
          >
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <nav class="flex-1 px-4 py-3 space-y-1.5 overflow-y-auto">
          <RouterLink to="/admin" :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path === '/admin' ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']">
            <span>Dashboard</span>
          </RouterLink>
          <RouterLink to="/admin/servicios" :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.startsWith('/admin/servicios') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']">
            <span>Servicios</span>
          </RouterLink>
          <RouterLink to="/admin/mantenimientos" :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.includes('mantenimiento') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']">
            <span>Mantenimientos</span>
          </RouterLink>
          <RouterLink to="/admin/inventario" :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.startsWith('/admin/inventario') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']">
            <span>Inventario interno</span>
          </RouterLink>
          <RouterLink to="/admin/facturas" :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.startsWith('/admin/facturas') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']">
            <span>Facturas</span>
          </RouterLink>
          <RouterLink to="/admin/empresas" :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.startsWith('/admin/empresas') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']">
            <span>Empresas</span>
          </RouterLink>
          <RouterLink to="/admin/empleados/rendimiento" :class="['group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors', route.path.startsWith('/admin/empleados/rendimiento') ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20' : 'text-slate-300 hover:bg-slate-800/50 hover:text-white']">
            <span>Empleados</span>
          </RouterLink>
        </nav>
      </aside>
    </Teleport>

    <!-- ÁREA PRINCIPAL CONTENT -->
    <div class="flex-1 flex flex-col min-w-0 bg-[#13161f]">
      
      <!-- TOPBAR: saludo izquierda · búsqueda + acciones derecha (referencia mockup) -->
      <header
        class="min-h-[76px] px-4 sm:px-6 py-2.5 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-slate-700/30 bg-[#13161f] z-30 sticky top-0"
      >
        <!-- Izquierda: móvil + saludo -->
        <div class="flex items-center gap-3 sm:gap-4 min-w-0 flex-1 lg:flex-none lg:max-w-[min(100%,28rem)]">
          <div class="flex items-center gap-3 shrink-0 lg:hidden">
            <button type="button" class="text-slate-400 hover:text-white transition-colors" aria-label="Abrir menú" @click="mobileNavOpen = true">
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

        <!-- Derecha: búsqueda (crece con el viewport) + notificaciones + menú de cuenta -->
        <div
          class="flex min-w-0 flex-1 flex-wrap items-center justify-end gap-3 sm:flex-nowrap sm:gap-4 lg:min-w-[12rem]"
        >
          <form
            role="search"
            class="relative hidden min-w-0 w-full sm:block sm:flex-1 sm:max-w-[min(100%,22rem)] md:max-w-[min(100%,28rem)] lg:max-w-[min(100%,36rem)] xl:max-w-[min(100%,44rem)] 2xl:max-w-[min(100%,52rem)]"
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
              class="w-full min-h-[44px] bg-[#1c212c] border border-slate-700/50 rounded-xl py-2.5 pl-4 pr-11 text-sm text-slate-300 placeholder:text-slate-500 outline-none transition-[border-color,box-shadow] focus:border-slate-500 sm:min-h-[46px] sm:py-3 lg:min-h-[48px] shadow-inner"
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

          <div
            ref="menuWrap"
            class="flex items-center gap-3 sm:gap-5 text-slate-400 shrink-0 sm:border-r sm:border-slate-700/50 sm:pr-5"
          >
            <AdminNotificationBell />

            <div class="relative">
              <button
                type="button"
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-sky-600 to-blue-700 text-xs font-bold uppercase tracking-tight text-white shadow-md ring-2 ring-[#13161f] transition hover:ring-sky-500/50 focus:outline-none focus:ring-2 focus:ring-sky-500/50"
                :aria-expanded="menuOpen"
                aria-haspopup="true"
                :aria-label="menuAriaLabel"
                @click.stop="menuOpen = !menuOpen"
              >
                {{ initials }}
              </button>

              <div
                v-show="menuOpen"
                class="admin-account-menu absolute right-0 z-[60] mt-2 w-[min(100vw-2rem,15rem)] overflow-hidden rounded-xl border border-slate-700/80 bg-[#1a222d] py-1 shadow-2xl shadow-black/50"
                role="menu"
              >
                <div
                  v-if="nombreCuentaCorta"
                  class="border-b border-slate-700/80 px-4 py-3"
                  role="presentation"
                >
                  <p class="truncate text-sm font-semibold text-white">{{ nombreCuentaCorta }}</p>
                  <p class="mt-0.5 truncate text-[0.7rem] font-medium text-slate-500">Administrador</p>
                </div>
                <RouterLink
                  to="/admin"
                  :class="itemClass(profileActive)"
                  role="menuitem"
                  title="Panel principal"
                  @click="menuOpen = false"
                >
                  <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                    />
                  </svg>
                  Perfil
                </RouterLink>
                <RouterLink
                  to="/admin/configuracion"
                  :class="itemClass(configActive)"
                  role="menuitem"
                  @click="menuOpen = false"
                >
                  <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                    />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                  Configuración
                </RouterLink>
                <button type="button" :class="itemClass(false)" role="menuitem" @click="salir">
                  <svg class="h-4 w-4 shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"
                    />
                  </svg>
                  Cerrar sesión
                </button>
              </div>
            </div>
          </div>
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
.admin-account-menu::before {
  content: '';
  position: absolute;
  top: -6px;
  right: 14px;
  border-width: 0 6px 6px 6px;
  border-style: solid;
  border-color: transparent transparent #1a222d transparent;
}
</style>
