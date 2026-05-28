<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchEmpleadoNotifications,
  fetchEmpleadoUnreadCount,
  markAllEmpleadoNotificationsRead,
  markEmpleadoNotificationRead,
  requestEmpleadoNotifStreamTicket,
} from '@/services/empleadoNotifApi.js'
import { apiBaseUrl } from '@/services/api.js'

const router = useRouter()
const open = ref(false)
const loading = ref(false)
const count = ref(0)
const items = ref([])
const error = ref('')

const prevUnreadCount = ref(null)
const toastOpen = ref(false)
const toastMessage = ref('')
const toastLink = ref(null)
let toastHideTimer = null
let pollTimer = null
let sseSource = null
let sseReconnectTimer = null
let baseTitle = ''

function startPolling() {
  if (pollTimer) return
  pollTimer = setInterval(refreshCount, 60000)
}

function stopPolling() {
  if (pollTimer) { clearInterval(pollTimer); pollTimer = null }
}

function dismissToast() {
  toastOpen.value = false
  toastLink.value = null
  if (toastHideTimer) { clearTimeout(toastHideTimer); toastHideTimer = null }
}

async function triggerToast() {
  try {
    const latest = await fetchEmpleadoNotifications({ limit: 1, unread_only: true })
    const n = latest[0]
    toastMessage.value = n?.message ? String(n.message).trim().slice(0, 200) : 'Tienes nuevos avisos.'
    toastLink.value = n?.meta?.link ?? null
  } catch {
    toastMessage.value = 'Tienes nuevos avisos.'
    toastLink.value = null
  }
  toastOpen.value = true
  if (toastHideTimer) clearTimeout(toastHideTimer)
  toastHideTimer = setTimeout(dismissToast, 9000)
}

async function startSse() {
  stopSse()
  stopPolling()
  try {
    const res = await requestEmpleadoNotifStreamTicket()
    const ticket = res?.ticket
    if (!ticket) throw new Error('no ticket')

    const url = `${apiBaseUrl()}/api/empleado/notifications/stream?ticket=${encodeURIComponent(ticket)}`
    sseSource = new EventSource(url)

    sseSource.onmessage = (ev) => {
      try {
        const d = JSON.parse(ev.data)
        if (typeof d.count === 'number') {
          if (prevUnreadCount.value !== null && d.count > prevUnreadCount.value) {
            triggerToast()
          }
          prevUnreadCount.value = d.count
          count.value = d.count
        }
      } catch { /* ignore */ }
    }

    sseSource.addEventListener('close', () => {
      stopSse()
      if (document.visibilityState === 'visible') {
        sseReconnectTimer = setTimeout(startSse, 2000)
      }
    })

    sseSource.onerror = () => { stopSse(); startPolling() }
  } catch {
    startPolling()
  }
}

function stopSse() {
  if (sseSource) { sseSource.close(); sseSource = null }
  if (sseReconnectTimer) { clearTimeout(sseReconnectTimer); sseReconnectTimer = null }
}

function handleVisibility() {
  if (document.visibilityState === 'visible') { refreshCount(); startSse() }
  else { stopSse(); stopPolling() }
}

async function refreshCount() {
  try {
    const newCount = await fetchEmpleadoUnreadCount()
    if (prevUnreadCount.value !== null && newCount > prevUnreadCount.value) {
      triggerToast()
    }
    prevUnreadCount.value = newCount
    count.value = newCount
  } catch { /* silencioso */ }
}

async function loadList() {
  error.value = ''
  loading.value = true
  try {
    items.value = await fetchEmpleadoNotifications({ limit: 25 })
  } catch (e) {
    error.value = e.message || 'No se pudieron cargar las notificaciones.'
    items.value = []
  } finally {
    loading.value = false
  }
}

async function toggle() {
  open.value = !open.value
  if (open.value) { await loadList(); await refreshCount() }
}

async function onRead(n) {
  try {
    if (!n.read) { await markEmpleadoNotificationRead(n.id); n.read = true; await refreshCount() }
    const path = n.meta?.link
    if (path && typeof path === 'string') { open.value = false; await router.push(path) }
  } catch (e) {
    error.value = e.message || 'Error al marcar como leído.'
  }
}

async function onReadAll() {
  try {
    await markAllEmpleadoNotificationsRead()
    items.value = items.value.map((x) => ({ ...x, read: true }))
    count.value = 0
    open.value = false
  } catch (e) {
    error.value = e.message || 'No se pudo completar.'
  }
}

async function onToastActivate() {
  const path = toastLink.value
  dismissToast()
  if (path && typeof path === 'string') { open.value = false; await router.push(path); return }
  open.value = true
  await loadList()
  await refreshCount()
}

function onDocClick(ev) {
  const root = document.getElementById('emp-notif-bell-root')
  if (root && !root.contains(ev.target)) open.value = false
}

function onToastKeydown(ev) {
  if (ev.key === 'Escape') dismissToast()
}

watch(count, (n) => {
  if (!baseTitle) return
  document.title = n > 0 ? `(${n > 99 ? '99+' : n}) ${baseTitle}` : baseTitle
})

onMounted(() => {
  baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '')
  refreshCount()
  startSse()
  document.addEventListener('visibilitychange', handleVisibility)
  document.addEventListener('click', onDocClick)
  document.addEventListener('keydown', onToastKeydown)
})

onUnmounted(() => {
  stopSse()
  stopPolling()
  if (baseTitle) document.title = baseTitle
  dismissToast()
  document.removeEventListener('visibilitychange', handleVisibility)
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onToastKeydown)
})
</script>

<template>
  <div id="emp-notif-bell-root" class="relative shrink-0">
    <button
      type="button"
      class="relative rounded-lg p-2 text-slate-300 transition hover:bg-slate-800/90 hover:text-sky-300 focus:outline-none focus:ring-2 focus:ring-sky-500/40"
      aria-label="Avisos y notificaciones"
      title="Avisos"
      @click.stop="toggle"
    >
      <svg class="h-[1.25rem] w-[1.25rem]" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"
        />
      </svg>
      <span
        v-if="count > 0"
        class="absolute -top-0.5 -right-0.5 min-w-[1rem] h-4 px-1 flex items-center justify-center rounded-full bg-red-500 ring-2 ring-[#0f1419] text-[0.65rem] font-bold text-white"
      >
        {{ count > 99 ? '99+' : count }}
      </span>
    </button>

    <div
      v-if="open"
      class="absolute right-0 top-full z-[100] mt-2 w-[min(100vw-2rem,22rem)] origin-top-right rounded-xl border border-slate-700/60 bg-[#1a222d] text-left shadow-2xl"
      @click.stop
    >
      <div class="flex items-center justify-between px-3 py-2 border-b border-slate-700/50">
        <span class="text-sm font-semibold text-slate-200">Avisos</span>
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
      <div class="max-h-[min(55vh,280px)] overflow-y-auto overscroll-contain">
        <p v-if="loading" class="px-3 py-6 text-center text-sm text-slate-500">Cargando…</p>
        <template v-else>
          <button
            v-for="n in items"
            :key="n.id"
            type="button"
            class="w-full text-left px-3 py-2.5 border-b border-slate-800/80 hover:bg-slate-800/40 transition-colors border-l-[3px] border-l-sky-500/40"
            :class="{ 'bg-slate-800/25': !n.read }"
            @click="onRead(n)"
          >
            <p class="text-[0.8rem] text-slate-200 leading-snug m-0">{{ n.message }}</p>
            <p class="text-[0.65rem] text-slate-500 mt-1 m-0">
              {{ new Date(n.created_at).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' }) }}
            </p>
          </button>
          <p v-if="!items.length" class="px-3 py-8 text-center text-sm text-slate-500">Sin avisos.</p>
        </template>
      </div>
      <div class="border-t border-slate-700/50 px-3 py-2 text-right">
        <router-link
          :to="{ name: 'empleado-notificaciones' }"
          class="text-xs text-sky-400 hover:text-sky-300 transition-colors"
          @click="open = false"
        >
          Ver todos →
        </router-link>
      </div>
    </div>
  </div>

  <Teleport to="body">
    <Transition name="emp-notif-toast">
      <div
        v-if="toastOpen"
        class="emp-notif-toast fixed bottom-4 right-4 z-[220] flex max-w-[min(100vw-1.5rem,20rem)] flex-col gap-2 rounded-xl border border-slate-600/80 bg-[#1a222d] p-3 shadow-2xl shadow-black/50"
        role="status"
        aria-live="polite"
      >
        <div class="flex items-start justify-between gap-2">
          <p class="m-0 flex-1 min-w-0 text-[0.8rem] leading-snug text-slate-100">{{ toastMessage }}</p>
          <button
            type="button"
            class="shrink-0 rounded-md p-1 text-slate-500 hover:bg-slate-800 hover:text-slate-200"
            aria-label="Cerrar aviso"
            @click.stop="dismissToast"
          >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <div class="flex justify-end">
          <button
            type="button"
            class="rounded-lg border border-slate-600 px-2.5 py-1 text-[0.7rem] font-semibold text-slate-300 hover:bg-slate-800"
            @click.stop="onToastActivate"
          >
            {{ toastLink ? 'Abrir destino' : 'Ver avisos' }}
          </button>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.emp-notif-toast-enter-active,
.emp-notif-toast-leave-active {
  transition: opacity 0.22s ease, transform 0.22s ease;
}
.emp-notif-toast-enter-from,
.emp-notif-toast-leave-to {
  opacity: 0;
  transform: translateY(10px);
}
</style>
