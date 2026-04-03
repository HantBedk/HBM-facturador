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
const items = ref([])
const error = ref('')

let pollTimer = null

async function refreshCount() {
  try {
    count.value = await fetchUnreadNotificationCount()
  } catch {
    /* silencioso en barra */
  }
}

async function loadList() {
  error.value = ''
  loading.value = true
  try {
    items.value = await fetchAdminNotifications({ limit: 30 })
  } catch (e) {
    error.value = e.message || 'No se pudieron cargar las notificaciones.'
    items.value = []
  } finally {
    loading.value = false
  }
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
    open.value = false
  } catch (e) {
    error.value = e.message || 'No se pudo completar.'
  }
}

function onDocClick(ev) {
  const root = document.getElementById('admin-notif-bell-root')
  if (root && !root.contains(ev.target)) open.value = false
}

onMounted(() => {
  refreshCount()
  pollTimer = setInterval(refreshCount, 60000)
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
      class="absolute right-0 mt-2 w-[min(100vw-2rem,22rem)] rounded-xl border border-slate-700/60 bg-[#1c212c] shadow-2xl z-50 text-left"
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
      <p v-if="error" class="px-3 py-2 text-xs text-red-400">{{ error }}</p>
      <div class="max-h-[min(70vh,320px)] overflow-y-auto">
        <p v-if="loading" class="px-3 py-6 text-center text-sm text-slate-500">Cargando…</p>
        <template v-else>
          <button
            v-for="n in items"
            :key="n.id"
            type="button"
            class="w-full text-left px-3 py-2.5 border-b border-slate-800/80 hover:bg-slate-800/40 transition-colors"
            :class="{ 'bg-slate-800/25': !n.read }"
            @click="onRead(n)"
          >
            <p class="text-[0.8rem] text-slate-200 leading-snug m-0">{{ n.message }}</p>
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
