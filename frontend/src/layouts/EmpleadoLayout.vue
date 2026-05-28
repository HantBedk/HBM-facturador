<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import EmpleadoNotificationBell from '@/components/EmpleadoNotificationBell.vue'
import { useAuthStore } from '@/stores/auth'
import { isEmpleadoPerfilIncomplete } from '@/utils/empleadoPerfil.js'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const menuOpen = ref(false)
const menuWrap = ref(null)

const initials = computed(() => {
  const n = (auth.user?.nombre || '').trim()
  if (!n) return '?'
  const parts = n.split(/\s+/).filter(Boolean)
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
})

/**
 * Primer nombre + primer apellido desde el nombre completo registrado.
 * Heurística: 2 palabras → ambas; 3+ → primera + penúltima (apellido paterno típ. antes del materno).
 */
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

const isMandatoryProfileGate = computed(
  () => route.path === '/empleado/perfil' && isEmpleadoPerfilIncomplete(auth.user)
)

const showPanelLink = computed(() => route.path !== '/empleado' && !isMandatoryProfileGate.value)

const profileActive = computed(() => route.path === '/empleado/perfil')
const configActive = computed(() => route.path.startsWith('/empleado/configuracion'))

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

onMounted(async () => {
  document.addEventListener('click', onDocClick)
})
onUnmounted(() => document.removeEventListener('click', onDocClick))

async function salir() {
  menuOpen.value = false
  await auth.logout()
  await router.replace('/login')
}
</script>

<template>
  <div class="min-h-screen bg-[#0b0f14] text-slate-200">
    <header
      class="sticky top-0 z-20 flex items-center justify-between gap-3 overflow-visible border-b border-slate-800/80 bg-[#0f1419]/95 px-4 py-3 shadow-sm shadow-black/20 backdrop-blur-md sm:px-6"
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
          <span class="text-sm font-bold tracking-tight text-white sm:text-base"></span>
        </div>
        <div
          v-if="isMandatoryProfileGate"
          class="flex min-w-0 flex-1 flex-wrap items-center gap-x-3 gap-y-1 text-sm md:max-w-md"
        >
          <p class="font-medium text-sky-400/95">Datos de contacto y pago</p>
          <RouterLink
            to="/empleado/configuracion"
            class="rounded-lg px-2 py-1 text-[0.7rem] font-semibold text-sky-400 transition hover:bg-slate-800/80 hover:text-sky-300 sm:text-xs"
            :class="{ 'bg-slate-800/60 text-sky-300': configActive }"
          >
            Configuración
          </RouterLink>
        </div>
        <nav
          v-if="!isMandatoryProfileGate"
          class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[0.7rem] font-semibold sm:gap-x-3 sm:text-xs"
        >
          <RouterLink
            to="/empleado"
            class="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-800/80 hover:text-sky-300"
            :class="{ 'bg-slate-800/60 text-sky-300': route.path === '/empleado' }"
          >
            Panel
          </RouterLink>
          <RouterLink
            to="/empleado/listado-servicios"
            class="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-800/80 hover:text-sky-300"
            :class="{ 'bg-slate-800/60 text-sky-300': route.path.startsWith('/empleado/listado-servicios') }"
          >
            Historial
          </RouterLink>
          <RouterLink
            to="/empleado/inventario"
            class="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-800/80 hover:text-sky-300"
            :class="{ 'bg-slate-800/60 text-sky-300': route.path.startsWith('/empleado/inventario') }"
          >
            Mi inventario
          </RouterLink>
          <RouterLink
            to="/empleado/registro-servicio"
            class="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-800/80 hover:text-sky-300"
            :class="{ 'bg-slate-800/60 text-sky-300': route.path === '/empleado/registro-servicio' }"
          >
            Servicio
          </RouterLink>
          <RouterLink
            to="/empleado/mantenimientos"
            class="rounded-lg px-2 py-1 text-slate-400 transition hover:bg-slate-800/80 hover:text-sky-300"
            :class="{ 'bg-slate-800/60 text-sky-300': route.path.startsWith('/empleado/mantenimientos') || route.path === '/empleado/registro-mantenimiento' }"
          >
            Mantenimiento
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

      <div ref="menuWrap" class="flex shrink-0 items-center gap-1.5 sm:gap-2">
        <RouterLink
          v-if="!isMandatoryProfileGate"
          to="/empleado/listado-servicios"
          class="relative rounded-lg p-2 text-slate-300 transition hover:bg-slate-800/90 hover:text-sky-300 focus:outline-none focus:ring-2 focus:ring-sky-500/40"
          title="Listado de servicios"
          aria-label="Listado de servicios"
        >
          <svg class="h-[1.25rem] w-[1.25rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z"
            />
          </svg>
        </RouterLink>

        <EmpleadoNotificationBell />

        <div class="relative">
          <button
            type="button"
            class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-sky-600 to-blue-700 text-xs font-bold uppercase tracking-tight text-white shadow-md ring-2 ring-slate-800 transition hover:ring-sky-500/50 focus:outline-none focus:ring-2 focus:ring-sky-500/50"
            :aria-expanded="menuOpen"
            aria-haspopup="true"
            :aria-label="menuAriaLabel"
            @click.stop="menuOpen = !menuOpen"
          >
            {{ initials }}
          </button>

          <div
            v-show="menuOpen"
            class="empleado-user-menu absolute right-0 z-40 mt-2 w-[min(100vw-2rem,15rem)] overflow-hidden rounded-xl border border-slate-700/80 bg-[#1a222d] py-1 shadow-2xl shadow-black/50"
            role="menu"
          >
            <div
              v-if="nombreCuentaCorta"
              class="border-b border-slate-700/80 px-4 py-3"
              role="presentation"
            >
              <p class="truncate text-sm font-semibold text-white">{{ nombreCuentaCorta }}</p>
            </div>
            <RouterLink
              to="/empleado/perfil"
              :class="itemClass(profileActive)"
              role="menuitem"
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
              to="/empleado/configuracion"
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
            <button
              type="button"
              :class="itemClass(false)"
              role="menuitem"
              @click="salir"
            >
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
    </header>

    <main class="mx-auto max-w-[1200px] px-4 py-6 sm:px-6 sm:py-8">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.empleado-user-menu::before {
  content: '';
  position: absolute;
  top: -6px;
  right: 14px;
  border-width: 0 6px 6px 6px;
  border-style: solid;
  border-color: transparent transparent #1a222d transparent;
}
</style>
