<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import AdminNotificationBell from '@/components/AdminNotificationBell.vue'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const displayName = computed(() => auth.user?.nombre || 'Administrador')

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
          to="/admin/empleados"
          :class="[
            'group flex items-center gap-3 px-3 py-2.5 rounded-xl font-medium text-[0.95rem] transition-colors',
            route.path === '/admin/empleados'
              ? 'bg-blue-600 font-bold shadow-md shadow-blue-600/20'
              : 'text-slate-300 hover:bg-slate-800/50 hover:text-white',
          ]"
        >
          <div
            :class="[
              'flex items-center justify-center p-1',
              route.path === '/admin/empleados' ? 'text-white' : 'text-purple-400 group-hover:text-purple-300',
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
      
      <!-- TOPBAR -->
      <header class="h-[76px] px-6 flex items-center justify-between border-b border-slate-700/30 bg-[#13161f] z-10 sticky top-0">
        
        <!-- Móvil (Hamburger) -->
        <div class="flex items-center gap-4 lg:hidden">
           <button class="text-slate-400 hover:text-white transition-colors">
             <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" /></svg>
           </button>
           <span class="text-lg font-bold text-white tracking-wide">HBM</span>
        </div>
        
        <!-- Spacer cuando es Desktop -->
        <div class="hidden lg:block flex-1">
          <!-- Campo Buscar o Biga Invisible -->
          <div class="relative w-[340px]">
            <input type="text" placeholder="Buscar servicios, facturas..." class="w-full bg-[#1c212c] border border-slate-700/50 rounded-xl px-4 py-2.5 text-sm text-slate-300 outline-none focus:border-slate-500 transition-colors pl-11 shadow-inner">
            <svg class="absolute left-4 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
          </div>
        </div>

        <!-- Acciones Derecha -->
        <div class="flex items-center gap-4">
          <!-- Íconos de Control -->
          <div class="hidden sm:flex items-center gap-5 text-slate-400 border-r border-slate-700/50 pr-5">
            <AdminNotificationBell />
            <button class="hover:text-white transition-colors">
              <svg class="h-[1.3rem] w-[1.3rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" /><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            </button>
          </div>

          <!-- Botón Salir -->
          <button 
            type="button" 
            @click="salir" 
            class="flex items-center gap-2 px-4 py-2 rounded-xl border border-slate-700/60 bg-[#1c212c] text-[0.85rem] font-semibold text-slate-300 hover:bg-red-500/10 hover:text-red-400 hover:border-red-500/30 transition-colors shadow-sm"
          >
            <span>Salir</span>
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" /></svg>
          </button>
        </div>
      </header>

      <!-- VISTA DINÁMICA DE LA APLICACIÓN -->
      <main class="flex-1 w-full bg-transparent">
        <RouterView />
      </main>

    </div>
  </div>
</template>

<style scoped>
/* Scoped limpio. Todo Tailwind */
</style>
