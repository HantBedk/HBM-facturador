<script setup>
import { ref, watch, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchEmpleadoNotificationsPage,
  markEmpleadoNotificationRead,
  markAllEmpleadoNotificationsRead,
} from '@/services/empleadoNotifApi.js'

const router = useRouter()

const unreadOnly = ref(false)
const page = ref(1)
const items = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 })
const loading = ref(false)
const error = ref('')
const markingAll = ref(false)

const totalPages = computed(() => meta.value.last_page ?? 1)

async function load() {
  error.value = ''
  loading.value = true
  try {
    const res = await fetchEmpleadoNotificationsPage({
      page: page.value,
      per_page: 20,
      unread_only: unreadOnly.value || undefined,
    })
    items.value = Array.isArray(res.data) ? res.data : []
    if (res.meta) meta.value = res.meta
  } catch (e) {
    error.value = e.message || 'No se pudieron cargar los avisos.'
  } finally {
    loading.value = false
  }
}

watch(unreadOnly, () => {
  page.value = 1
  load()
})

async function onRead(n) {
  if (!n.read) {
    try {
      await markEmpleadoNotificationRead(n.id)
      n.read = true
    } catch { /* ignore */ }
  }
  const path = n.meta?.link
  if (path && typeof path === 'string') {
    router.push(path)
  }
}

async function onMarkAll() {
  if (markingAll.value) return
  markingAll.value = true
  try {
    await markAllEmpleadoNotificationsRead()
    items.value = items.value.map((x) => ({ ...x, read: true }))
  } catch (e) {
    error.value = e.message || 'No se pudo completar.'
  } finally {
    markingAll.value = false
  }
}

function prevPage() {
  if (page.value > 1) { page.value--; load() }
}

function nextPage() {
  if (page.value < totalPages.value) { page.value++; load() }
}

onMounted(load)
</script>

<template>
  <div class="notif-page">
    <div class="notif-header">
      <h1 class="notif-title">Mis avisos</h1>
      <button
        type="button"
        class="btn-action"
        :disabled="markingAll"
        @click="onMarkAll"
      >
        {{ markingAll ? 'Marcando…' : 'Marcar todos leídos' }}
      </button>
    </div>

    <div class="filter-bar">
      <label class="filter-label">
        <input v-model="unreadOnly" type="checkbox" class="mr-1.5 accent-sky-500" />
        Solo no leídos
      </label>
      <span class="total-info">{{ meta.total }} aviso{{ meta.total !== 1 ? 's' : '' }}</span>
    </div>

    <p v-if="error" class="banner-error" role="alert">{{ error }}</p>

    <div class="notif-list">
      <p v-if="loading" class="empty-msg">Cargando…</p>

      <template v-else-if="items.length">
        <button
          v-for="n in items"
          :key="n.id"
          type="button"
          class="notif-row"
          :class="[
            !n.read ? 'notif-row--unread' : '',
            n.meta?.link ? 'cursor-pointer' : 'cursor-default',
          ]"
          @click="onRead(n)"
        >
          <div class="notif-dot-wrap">
            <span v-if="!n.read" class="unread-dot" aria-label="No leído" />
          </div>
          <div class="notif-body">
            <p class="notif-msg">{{ n.message }}</p>
            <p class="notif-time">
              {{ new Date(n.created_at).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' }) }}
            </p>
          </div>
          <svg
            v-if="n.meta?.link"
            class="notif-arrow"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
        </button>
      </template>

      <p v-else class="empty-msg">{{ unreadOnly ? 'No tienes avisos sin leer.' : 'No tienes avisos todavía.' }}</p>
    </div>

    <!-- Paginación -->
    <div v-if="totalPages > 1" class="pagination">
      <button type="button" class="page-btn" :disabled="page === 1" @click="prevPage">← Anterior</button>
      <span class="page-info">{{ page }} / {{ totalPages }}</span>
      <button type="button" class="page-btn" :disabled="page === totalPages" @click="nextPage">Siguiente →</button>
    </div>
  </div>
</template>

<style scoped>
.notif-page { width: 100%; max-width: 680px; margin: 0 auto; padding: 1.5rem 1rem; }

.notif-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1rem;
  gap: 1rem;
}
.notif-title { margin: 0; font-size: 1.35rem; font-weight: 700; color: #f1f5f9; }

.btn-action {
  padding: 0.4rem 0.85rem;
  border-radius: 0.5rem;
  border: 1px solid rgba(100,116,139,.4);
  background: rgba(2,6,23,.35);
  color: #94a3b8;
  font-size: 0.82rem;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.btn-action:disabled { opacity: .5; cursor: not-allowed; }
.btn-action:not(:disabled):hover { color: #e2e8f0; background: rgba(51,65,85,.5); }

.filter-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.85rem;
}
.filter-label {
  display: flex;
  align-items: center;
  font-size: 0.82rem;
  color: #64748b;
  cursor: pointer;
  user-select: none;
}
.total-info { font-size: 0.78rem; color: #475569; }

.banner-error {
  margin-bottom: 0.85rem;
  padding: 0.6rem 0.85rem;
  border-radius: 0.5rem;
  background: rgba(248,113,113,.1);
  border: 1px solid rgba(248,113,113,.4);
  color: #fecaca;
  font-size: 0.85rem;
}

.notif-list {
  border-radius: 0.85rem;
  border: 1px solid rgba(100,116,139,.2);
  background: rgba(15,23,42,.5);
  overflow: hidden;
  margin-bottom: 1rem;
}
.empty-msg { padding: 2.5rem; text-align: center; color: #475569; font-size: 0.88rem; margin: 0; }

.notif-row {
  width: 100%;
  display: flex;
  align-items: flex-start;
  text-align: left;
  padding: 0.85rem 1rem;
  border-bottom: 1px solid rgba(30,41,59,.7);
  background: transparent;
  transition: background 0.12s;
  gap: 0.75rem;
}
.notif-row:last-child { border-bottom: none; }
.notif-row:hover { background: rgba(51,65,85,.25); }
.notif-row--unread { background: rgba(30,41,59,.45); }

.notif-dot-wrap { padding-top: 0.3rem; flex-shrink: 0; width: 0.85rem; display: flex; justify-content: center; }
.unread-dot { display: block; width: 0.5rem; height: 0.5rem; border-radius: 50%; background: #38bdf8; }

.notif-body { flex: 1; min-width: 0; }
.notif-msg { margin: 0 0 0.25rem; font-size: 0.875rem; line-height: 1.45; color: #e2e8f0; }
.notif-time { margin: 0; font-size: 0.7rem; color: #475569; }

.notif-arrow { width: 1rem; height: 1rem; color: #475569; flex-shrink: 0; margin-top: 0.25rem; }

.pagination { display: flex; align-items: center; justify-content: center; gap: 1rem; padding: 0.25rem; }
.page-btn {
  padding: 0.4rem 0.85rem;
  border-radius: 0.5rem;
  border: 1px solid rgba(100,116,139,.4);
  background: rgba(15,23,42,.4);
  color: #94a3b8;
  font-size: 0.82rem;
  cursor: pointer;
}
.page-btn:disabled { opacity: .35; cursor: not-allowed; }
.page-btn:not(:disabled):hover { color: #e2e8f0; }
.page-info { font-size: 0.82rem; color: #64748b; min-width: 4rem; text-align: center; }
</style>
