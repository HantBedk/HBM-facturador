<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchAdminNotifications,
  fetchUnreadNotificationCount,
  markAllNotificationsRead,
  markNotificationRead,
} from '@/services/notificationsApi.js'

const router = useRouter()
const open = ref(false)
const loading = ref(false)
const count = ref(0)
const byCategory = ref({ empleados: 0, servicios: 0, facturas: 0 })
const items = ref([])
const error = ref('')
/** @type {import('vue').Ref<'todas'|'empleados'|'servicios'|'facturas'>} */
const activeTab = ref('todas')

const TABS = [
  { key: 'todas', label: 'Todas', color: 'slate' },
  { key: 'empleados', label: 'Empleados', color: 'violet' },
  { key: 'servicios', label: 'Servicios', color: 'emerald' },
  { key: 'facturas', label: 'Facturas', color: 'amber' },
]

const rowBarClass = {
  empleados: 'border-l-violet-500',
  servicios: 'border-l-emerald-500',
  facturas: 'border-l-amber-500',
}

const badgeClass = {
  empleados: 'bg-violet-500/25 text-violet-200 ring-violet-500/40',
  servicios: 'bg-emerald-500/25 text-emerald-200 ring-emerald-500/40',
  facturas: 'bg-amber-500/25 text-amber-100 ring-amber-500/40',
}

let pollTimer = null

async function refreshCount() {
  try {
    const o = await fetchUnreadNotificationCount()
    count.value = o.count
    byCategory.value = o.byCategory
  } catch {
    /* silencioso en barra */
  }
}

async function loadList() {
  error.value = ''
  loading.value = true
  try {
    const params = { limit: 30 }
    if (activeTab.value !== 'todas') {
      params.category = activeTab.value
    }
    items.value = await fetchAdminNotifications(params)
  } catch (e) {
    error.value = e.message || 'No se pudieron cargar las notificaciones.'
    items.value = []
  } finally {
    loading.value = false
  }
}

function tabCount(key) {
  if (key === 'todas') return count.value
  return byCategory.value[key] ?? 0
}

function selectTab(key) {
  activeTab.value = key
  if (open.value) loadList()
}

async function toggle() {
  open.value = !open.value
  if (open.value) {
    await loadList()
    await refreshCount()
  }
}

async function onRead(n) {
  try {
    if (!n.read) {
      await markNotificationRead(n.id)
      n.read = true
      await refreshCount()
    }
    const path = n.meta?.link
    if (path && typeof path === 'string') {
      open.value = false
      await router.push(path)
    }
  } catch (e) {
    error.value = e.message || 'Error al marcar como leída.'
  }
}

async function onReadAll() {
  try {
    await markAllNotificationsRead()
    items.value = items.value.map((x) => ({ ...x, read: true }))
    count.value = 0
    byCategory.value = { empleados: 0, servicios: 0, facturas: 0 }
    open.value = false
  } catch (e) {
    error.value = e.message || 'No se pudo completar.'
  }
}

function categoryKey(n) {
  const c = n.category
  if (c === 'empleados' || c === 'servicios' || c === 'facturas') return c
  return 'facturas'
}

function onDocClick(ev) {
  const root = document.getElementById('admin-notif-bell-root')
  if (root && !root.contains(ev.target)) open.value = false
}

onMounted(() => {
  refreshCount()
  pollTimer = setInterval(refreshCount, 45000)
  document.addEventListener('click', onDocClick)
})

onUnmounted(() => {
  if (pollTimer) clearInterval(pollTimer)
  document.removeEventListener('click', onDocClick)
})
</script>

<template>
  <div id="admin-notif-bell-root" class="relative">
    <button
      type="button"
      class="relative text-slate-400 hover:text-white transition-colors p-1"
      aria-label="Notificaciones"
      @click.stop="toggle"
    >
      <svg class="h-[1.3rem] w-[1.3rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
        />
      </svg>
      <span
        v-if="count > 0"
        class="absolute -top-1 -right-1 min-w-[1rem] h-4 px-1 flex items-center justify-center rounded-full bg-red-500 ring-2 ring-[#13161f] text-[0.65rem] font-bold text-white"
      >
        {{ count > 99 ? '99+' : count }}
      </span>
    </button>

    <div
      v-if="open"
      class="absolute right-0 top-full z-[100] mt-2 w-[min(100vw-2rem,22rem)] origin-top-right rounded-xl border border-slate-700/60 bg-[#1c212c] text-left shadow-2xl"
      @click.stop
    >
      <div class="flex items-center justify-between px-3 py-2 border-b border-slate-700/50">
        <span class="text-sm font-semibold text-slate-200">Notificaciones</span>
        <button
          v-if="count > 0"
          type="button"
          class="text-xs text-sky-400 hover:text-sky-300"
          @click="onReadAll"
        >
          Marcar todas leídas
        </button>
      </div>

      <div
        class="flex flex-wrap gap-1 px-2 py-2 border-b border-slate-700/40"
        role="tablist"
        aria-label="Filtrar por categoría"
      >
        <button
          v-for="t in TABS"
          :key="t.key"
          type="button"
          role="tab"
          :aria-selected="activeTab === t.key"
          class="rounded-lg px-2 py-1 text-[0.7rem] font-medium transition-colors"
          :class="
            activeTab === t.key
              ? t.color === 'violet'
                ? 'bg-violet-600/35 text-violet-100 ring-1 ring-violet-500/50'
                : t.color === 'emerald'
                  ? 'bg-emerald-600/35 text-emerald-100 ring-1 ring-emerald-500/50'
                  : t.color === 'amber'
                    ? 'bg-amber-600/35 text-amber-100 ring-1 ring-amber-500/50'
                    : 'bg-slate-600/40 text-slate-100 ring-1 ring-slate-500/40'
              : 'text-slate-400 hover:bg-slate-800/60 hover:text-slate-200'
          "
          @click="selectTab(t.key)"
        >
          {{ t.label }}
          <span v-if="tabCount(t.key) > 0" class="opacity-90"> ({{ tabCount(t.key) > 99 ? '99+' : tabCount(t.key) }})</span>
        </button>
      </div>

      <p v-if="error" class="px-3 py-2 text-xs text-red-400">{{ error }}</p>
      <div class="max-h-[min(55vh,300px)] overflow-y-auto overscroll-contain">
        <p v-if="loading" class="px-3 py-6 text-center text-sm text-slate-500">Cargando…</p>
        <template v-else>
          <button
            v-for="n in items"
            :key="n.id"
            type="button"
            class="w-full text-left px-3 py-2.5 border-b border-slate-800/80 hover:bg-slate-800/40 transition-colors border-l-4 pl-2.5"
            :class="[
              { 'bg-slate-800/25': !n.read },
              rowBarClass[categoryKey(n)] || 'border-l-amber-500',
            ]"
            @click="onRead(n)"
          >
            <div class="flex items-start justify-between gap-2">
              <p class="text-[0.8rem] text-slate-200 leading-snug m-0 flex-1 min-w-0">{{ n.message }}</p>
              <span
                class="shrink-0 text-[0.6rem] px-1.5 py-0.5 rounded-md ring-1"
                :class="badgeClass[categoryKey(n)] || badgeClass.facturas"
              >
                {{ n.category_label || 'Facturas' }}
              </span>
            </div>
            <p class="text-[0.65rem] text-slate-500 mt-1 m-0">
              {{ new Date(n.created_at).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' }) }}
            </p>
          </button>
          <p v-if="!items.length" class="px-3 py-8 text-center text-sm text-slate-500">Sin notificaciones.</p>
        </template>
      </div>
    </div>
  </div>
</template>
