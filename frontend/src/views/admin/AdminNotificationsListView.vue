<script setup>
import { ref, watch, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import {
  fetchAdminNotificationsPage,
  markNotificationRead,
  markAllNotificationsRead,
} from '@/services/notificationsApi.js'

const router = useRouter()

const TABS = [
  { key: '', label: 'Todas' },
  { key: 'empleados', label: 'Empleados' },
  { key: 'servicios', label: 'Servicios' },
  { key: 'facturas', label: 'Facturas' },
]

const ROW_BAR = { empleados: 'border-l-violet-500', servicios: 'border-l-emerald-500', facturas: 'border-l-amber-500' }
const BADGE_CLASS = {
  empleados: 'bg-violet-500/20 text-violet-200 ring-violet-500/35',
  servicios: 'bg-emerald-500/20 text-emerald-200 ring-emerald-500/35',
  facturas: 'bg-amber-500/20 text-amber-100 ring-amber-500/35',
}

const activeCategory = ref('')
const unreadOnly = ref(false)
const page = ref(1)
const items = ref([])
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 20 })
const loading = ref(false)
const error = ref('')
const markingAll = ref(false)

const totalPages = computed(() => meta.value.last_page ?? 1)

function categoryKey(n) {
  const c = n.category
  return c === 'empleados' || c === 'servicios' ? c : 'facturas'
}

async function load() {
  error.value = ''
  loading.value = true
  try {
    const res = await fetchAdminNotificationsPage({
      page: page.value,
      per_page: 20,
      unread_only: unreadOnly.value || undefined,
      category: activeCategory.value || undefined,
    })
    items.value = Array.isArray(res.data) ? res.data : []
    if (res.meta) meta.value = res.meta
  } catch (e) {
    error.value = e.message || 'No se pudieron cargar las notificaciones.'
  } finally {
    loading.value = false
  }
}

function selectTab(key) {
  activeCategory.value = key
  page.value = 1
}

watch([activeCategory, unreadOnly], () => {
  page.value = 1
  load()
})

async function onRead(n) {
  if (!n.read) {
    try {
      await markNotificationRead(n.id)
      n.read = true
    } catch { /* ignore */ }
  }
  const path = n.link ?? n.meta?.link
  if (path && typeof path === 'string') {
    router.push(path)
  }
}

async function onMarkAll() {
  if (markingAll.value) return
  markingAll.value = true
  try {
    await markAllNotificationsRead()
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

function goPage(n) {
  page.value = n
  load()
}

onMounted(load)
</script>

<template>
  <div class="notif-page w-full max-w-[860px]">
    <div class="notif-header">
      <h1 class="notif-title">Notificaciones</h1>
      <button
        type="button"
        class="btn-secondary text-sm"
        :disabled="markingAll"
        @click="onMarkAll"
      >
        {{ markingAll ? 'Marcando…' : 'Marcar todas leídas' }}
      </button>
    </div>

    <!-- Tabs de categoría -->
    <div class="tabs-bar" role="tablist">
      <button
        v-for="t in TABS"
        :key="t.key"
        type="button"
        role="tab"
        :aria-selected="activeCategory === t.key"
        class="tab-btn"
        :class="{ 'tab-active': activeCategory === t.key }"
        @click="selectTab(t.key)"
      >
        {{ t.label }}
      </button>
      <label class="unread-filter">
        <input v-model="unreadOnly" type="checkbox" class="mr-1.5 accent-sky-500" />
        Solo no leídas
      </label>
    </div>

    <p v-if="error" class="banner-error" role="alert">{{ error }}</p>

    <!-- Lista -->
    <div class="notif-list">
      <p v-if="loading" class="empty-msg">Cargando…</p>

      <template v-else-if="items.length">
        <button
          v-for="n in items"
          :key="n.id"
          type="button"
          class="notif-row border-l-4"
          :class="[
            !n.read ? 'notif-row--unread' : '',
            ROW_BAR[categoryKey(n)] ?? 'border-l-amber-500',
            (n.link || n.meta?.link) ? 'cursor-pointer' : 'cursor-default',
          ]"
          @click="onRead(n)"
        >
          <div class="notif-row-inner">
            <div class="notif-dot-wrap">
              <span v-if="!n.read" class="unread-dot" aria-label="No leída" />
            </div>
            <div class="notif-body">
              <div class="notif-top">
                <p class="notif-msg">{{ n.message }}</p>
                <span
                  class="notif-badge ring-1"
                  :class="BADGE_CLASS[categoryKey(n)] ?? BADGE_CLASS.facturas"
                >
                  {{ n.category_label || 'Facturas' }}
                </span>
              </div>
              <p class="notif-time">
                {{ new Date(n.created_at).toLocaleString('es-CO', { dateStyle: 'short', timeStyle: 'short' }) }}
              </p>
            </div>
          </div>
        </button>
      </template>

      <p v-else class="empty-msg">No hay notificaciones con estos filtros.</p>
    </div>

    <!-- Paginación -->
    <div v-if="totalPages > 1" class="pagination">
      <button type="button" class="page-btn" :disabled="page === 1" @click="prevPage">← Anterior</button>
      <span class="page-info">Página {{ page }} de {{ totalPages }}</span>
      <button type="button" class="page-btn" :disabled="page === totalPages" @click="nextPage">Siguiente →</button>
    </div>
  </div>
</template>

<style scoped>
.notif-page { margin: 0 auto; padding: 1.5rem 1rem; }

.notif-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.25rem;
  gap: 1rem;
}
.notif-title { margin: 0; font-size: 1.45rem; font-weight: 700; color: #f1f5f9; }

.btn-secondary {
  padding: 0.4rem 0.85rem;
  border-radius: 0.5rem;
  border: 1px solid rgba(100,116,139,.45);
  background: rgba(2,6,23,.35);
  color: #cbd5e1;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
}
.btn-secondary:disabled { opacity: .5; cursor: not-allowed; }
.btn-secondary:not(:disabled):hover { background: rgba(51,65,85,.5); }

.tabs-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.4rem;
  margin-bottom: 1rem;
  padding-bottom: 0.75rem;
  border-bottom: 1px solid rgba(100,116,139,.25);
}
.tab-btn {
  padding: 0.35rem 0.8rem;
  border-radius: 0.5rem;
  border: 1px solid transparent;
  font-size: 0.82rem;
  font-weight: 500;
  color: #64748b;
  cursor: pointer;
  background: transparent;
  transition: all 0.15s;
}
.tab-btn:hover { color: #e2e8f0; background: rgba(51,65,85,.4); }
.tab-active { background: rgba(51,65,85,.6) !important; color: #f1f5f9 !important; border-color: rgba(100,116,139,.45) !important; }
.unread-filter {
  margin-left: auto;
  display: flex;
  align-items: center;
  font-size: 0.82rem;
  color: #64748b;
  cursor: pointer;
  user-select: none;
}

.banner-error {
  margin-bottom: 1rem;
  padding: 0.6rem 0.85rem;
  border-radius: 0.5rem;
  background: rgba(248,113,113,.1);
  border: 1px solid rgba(248,113,113,.4);
  color: #fecaca;
  font-size: 0.88rem;
}

.notif-list {
  border-radius: 0.85rem;
  border: 1px solid rgba(100,116,139,.2);
  background: rgba(15,23,42,.5);
  overflow: hidden;
  margin-bottom: 1rem;
}
.empty-msg { padding: 2.5rem; text-align: center; color: #475569; font-size: 0.9rem; margin: 0; }

.notif-row {
  width: 100%;
  text-align: left;
  padding: 0;
  border-bottom: 1px solid rgba(30,41,59,.8);
  background: transparent;
  transition: background 0.12s;
}
.notif-row:last-child { border-bottom: none; }
.notif-row:hover { background: rgba(51,65,85,.25); }
.notif-row--unread { background: rgba(30,41,59,.4); }
.notif-row-inner { display: flex; align-items: flex-start; padding: 0.85rem 1rem 0.85rem 0; }

.notif-dot-wrap { width: 2rem; display: flex; justify-content: center; padding-top: 0.35rem; flex-shrink: 0; }
.unread-dot { display: block; width: 0.55rem; height: 0.55rem; border-radius: 50%; background: #38bdf8; }

.notif-body { flex: 1; min-width: 0; }
.notif-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.3rem; }
.notif-msg { flex: 1; min-width: 0; margin: 0; font-size: 0.875rem; line-height: 1.45; color: #e2e8f0; }
.notif-badge { flex-shrink: 0; font-size: 0.62rem; font-weight: 600; padding: 0.15rem 0.5rem; border-radius: 0.35rem; text-transform: uppercase; letter-spacing: .04em; }
.notif-time { margin: 0; font-size: 0.7rem; color: #475569; }

.pagination {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  padding: 0.5rem;
}
.page-btn {
  padding: 0.4rem 0.9rem;
  border-radius: 0.5rem;
  border: 1px solid rgba(100,116,139,.4);
  background: rgba(15,23,42,.4);
  color: #94a3b8;
  font-size: 0.82rem;
  font-weight: 500;
  cursor: pointer;
}
.page-btn:disabled { opacity: .35; cursor: not-allowed; }
.page-btn:not(:disabled):hover { color: #e2e8f0; background: rgba(51,65,85,.5); }
.page-info { font-size: 0.82rem; color: #64748b; }
</style>
