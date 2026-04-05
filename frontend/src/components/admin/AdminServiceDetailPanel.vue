<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { fetchService } from '@/services/servicesApi.js'
import { useClientSortedRows } from '@/composables/useClientSortedRows.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  serviceId: { type: [Number, String], default: null },
  /** z-index cuando el drawer se abre sobre otro overlay (p. ej. panel del dashboard). */
  overlayZIndex: { type: Number, default: 90 },
})

const emit = defineEmits(['close'])

const svc = ref(null)
const loading = ref(false)
const error = ref('')

const svcItemsSource = computed(() => svc.value?.items || [])
const {
  sortedRows: sortedSvcItems,
  toggleSort: toggleSvcItemSort,
  sortIndicator: svcItemSortInd,
  ariaSort: svcItemAriaSort,
} = useClientSortedRows(
  svcItemsSource,
  {
    label: (it) => it.label || '',
    amount: (it) => Number(it.amount) || 0,
  },
  { initialKey: 'label', initialDir: 'asc' }
)

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso + (iso.length === 10 ? 'T12:00:00' : ''))
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { day: '2-digit', month: 'short', year: 'numeric' })
}

function estadoLabel(status) {
  const m = { activo: 'Activo', corregido: 'Corregido', eliminado: 'Eliminado' }
  return m[status] ?? status ?? '—'
}

async function reload() {
  const id = props.serviceId
  if (id == null || id === '') return
  error.value = ''
  loading.value = true
  try {
    svc.value = await fetchService(id)
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar el servicio.'
    svc.value = null
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.open, props.serviceId],
  async ([op, id]) => {
    if (!op) {
      svc.value = null
      error.value = ''
      return
    }
    if (id == null || id === '') return
    await reload()
  }
)

const canEdit = computed(() => svc.value?.status !== 'eliminado' && !svc.value?.invoiced)

defineExpose({ reload })
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="drawer-root" :style="{ zIndex: overlayZIndex }" aria-hidden="false">
      <div class="drawer-backdrop" @click.self="emit('close')" />
      <aside
        class="drawer-panel"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="svc ? 'drawer-svc-title' : 'drawer-svc-loading'"
      >
        <header class="drawer-header">
          <div class="drawer-header-text">
            <p class="drawer-kicker">Detalle de servicio</p>
            <h2 :id="svc ? 'drawer-svc-title' : 'drawer-svc-loading'" class="drawer-title">
              {{ svc?.code || (loading ? 'Cargando…' : '—') }}
            </h2>
            <p v-if="svc" class="muted drawer-sub">{{ svc.company?.nombre || '—' }}</p>
          </div>
          <div class="drawer-header-actions">
            <RouterLink
              v-if="svc"
              class="btn secondary btn-compact"
              :to="`/admin/servicios/${svc.id}`"
              @click="emit('close')"
            >
              Página completa
            </RouterLink>
            <button type="button" class="drawer-close" aria-label="Cerrar panel" @click="emit('close')">
              <span aria-hidden="true">×</span>
            </button>
          </div>
        </header>

        <div class="drawer-toolbar" v-if="svc">
          <RouterLink
            v-if="canEdit"
            class="btn primary btn-compact"
            :to="`/admin/servicios/${svc.id}/editar`"
            @click="emit('close')"
          >
            Editar
          </RouterLink>
        </div>

        <div class="drawer-body">
          <p v-if="loading" class="muted pad">Cargando…</p>
          <p v-else-if="error" class="banner err">{{ error }}</p>

          <template v-else-if="svc">
            <div class="card status-row" :data-phase="svc.status">
              <span class="pill" :data-st="svc.status">{{ estadoLabel(svc.status) }}</span>
              <span v-if="svc.invoiced" class="muted small">Incluido en factura</span>
            </div>

            <div class="card">
              <h2>Resumen</h2>
              <p><span class="lbl">Cliente / obra</span> {{ svc.client_name || '—' }}</p>
              <p><span class="lbl">Tipo</span> {{ svc.service_type || '—' }}</p>
              <p><span class="lbl">Fecha</span> {{ formatDate(svc.service_date) }}</p>
              <p><span class="lbl">Valor</span> {{ money(svc.amount) }}</p>
              <p v-if="svc.catalog?.name">
                <span class="lbl">Catálogo</span> {{ svc.catalog.name }}
              </p>
              <p><span class="lbl">Técnico</span> {{ svc.empleado?.nombre || '—' }}</p>
              <p v-if="svc.empleado?.correo" class="muted small">{{ svc.empleado.correo }}</p>
            </div>

            <div class="card">
              <h2>Descripción</h2>
              <p class="desc">{{ svc.description || '—' }}</p>
            </div>

            <div v-if="(svc.items || []).length" class="card">
              <h2>Líneas del servicio</h2>
              <div class="table-wrap">
                <table class="table">
                  <thead>
                    <tr>
                      <th scope="col" :aria-sort="svcItemAriaSort('label')">
                        <button type="button" class="th-sort" @click="toggleSvcItemSort('label')">
                          Concepto<span class="sort-ind" aria-hidden="true">{{ svcItemSortInd('label') }}</span>
                        </button>
                      </th>
                      <th class="num" scope="col" :aria-sort="svcItemAriaSort('amount')">
                        <button type="button" class="th-sort th-sort--end" @click="toggleSvcItemSort('amount')">
                          Valor<span class="sort-ind" aria-hidden="true">{{ svcItemSortInd('amount') }}</span>
                        </button>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="it in sortedSvcItems" :key="it.id">
                      <td>
                        <span class="strong">{{ it.label || '—' }}</span>
                        <p v-if="it.line_description" class="muted tiny">{{ it.line_description }}</p>
                      </td>
                      <td class="num">{{ money(it.amount) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <div v-if="(svc.invoices || []).length" class="card">
              <h2>Facturas</h2>
              <ul class="inv-list">
                <li v-for="inv in svc.invoices" :key="inv.id">
                  <RouterLink class="link-inv" :to="`/admin/facturas/${inv.id}`" @click="emit('close')">
                    {{ inv.code }}
                  </RouterLink>
                </li>
              </ul>
            </div>

            <div v-if="(svc.photos || []).length" class="card">
              <h2>Fotos</h2>
              <div class="photo-grid">
                <a
                  v-for="p in svc.photos"
                  :key="p.id"
                  :href="p.url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="photo-thumb"
                >
                  <img :src="p.url" :alt="'Foto ' + p.sort_order" loading="lazy" />
                </a>
              </div>
            </div>
          </template>
        </div>
      </aside>
    </div>
  </Teleport>
</template>

<style scoped>
.drawer-root {
  position: fixed;
  inset: 0;
  pointer-events: none;
}

.drawer-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(2, 6, 23, 0.65);
  backdrop-filter: blur(4px);
  pointer-events: auto;
}

.drawer-panel {
  position: absolute;
  top: 0;
  right: 0;
  height: 100%;
  width: min(560px, 100vw);
  max-width: 100%;
  background: #0f172a;
  border-left: 1px solid rgba(148, 163, 184, 0.25);
  box-shadow: -12px 0 40px rgba(0, 0, 0, 0.45);
  display: flex;
  flex-direction: column;
  pointer-events: auto;
  animation: drawer-in 0.22s ease-out;
}

@keyframes drawer-in {
  from {
    transform: translateX(100%);
    opacity: 0.9;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

.drawer-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1rem 0.75rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.2);
  flex-shrink: 0;
}

.drawer-header-text {
  min-width: 0;
}

.drawer-kicker {
  margin: 0 0 0.2rem;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #94a3b8;
}

.drawer-title {
  margin: 0;
  font-size: 1.15rem;
  color: #f8fafc;
  font-family: ui-monospace, monospace;
  word-break: break-word;
}

.drawer-sub {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
}

.drawer-header-actions {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  flex-shrink: 0;
}

.drawer-close {
  width: 2.25rem;
  height: 2.25rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(148, 163, 184, 0.35);
  border-radius: 10px;
  background: rgba(2, 6, 23, 0.4);
  color: #e2e8f0;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
}

.drawer-close:hover {
  border-color: #38bdf8;
  color: #fff;
}

.drawer-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.65rem 1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.15);
  flex-shrink: 0;
}

.drawer-body {
  flex: 1;
  overflow-y: auto;
  padding: 0.85rem 1rem 1.5rem;
  -webkit-overflow-scrolling: touch;
}

h2 {
  margin: 0 0 0.75rem;
  font-size: 1rem;
  color: #e2e8f0;
}

.card {
  padding: 1rem 1.1rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 0.85rem;
}

.status-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  border-left: 4px solid rgba(148, 163, 184, 0.35);
}

.status-row[data-phase='activo'] {
  border-left-color: rgba(74, 222, 128, 0.55);
  background: rgba(74, 222, 128, 0.06);
}
.status-row[data-phase='corregido'] {
  border-left-color: rgba(56, 189, 248, 0.55);
  background: rgba(56, 189, 248, 0.06);
}
.status-row[data-phase='eliminado'] {
  border-left-color: rgba(248, 113, 113, 0.45);
  background: rgba(248, 113, 113, 0.06);
}

.lbl {
  display: inline-block;
  min-width: 6.5rem;
  color: #94a3b8;
  font-size: 0.82rem;
}

.strong {
  font-weight: 600;
  color: #f1f5f9;
}

.desc {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
  color: #cbd5e1;
  font-size: 0.88rem;
  line-height: 1.45;
}

.pill {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 600;
  text-transform: capitalize;
}
.pill[data-st='activo'] {
  color: #86efac;
  border: 1px solid rgba(74, 222, 128, 0.4);
}
.pill[data-st='corregido'] {
  color: #7dd3fc;
  border: 1px solid rgba(56, 189, 248, 0.4);
}
.pill[data-st='eliminado'] {
  color: #fca5a5;
  border: 1px solid rgba(248, 113, 113, 0.4);
}

.table-wrap {
  overflow-x: auto;
}

.table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.85rem;
}
.table th,
.table td {
  padding: 0.4rem 0.3rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
  text-align: left;
  vertical-align: top;
}
.table th {
  color: #94a3b8;
  font-size: 0.72rem;
  text-transform: uppercase;
}
.num {
  text-align: right;
  white-space: nowrap;
}

.inv-list {
  margin: 0;
  padding-left: 1.1rem;
  color: #e2e8f0;
}

.link-inv {
  color: #7dd3fc;
  font-weight: 600;
  text-decoration: none;
}

.link-inv:hover {
  text-decoration: underline;
}

.photo-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.photo-thumb {
  display: block;
  width: 88px;
  height: 88px;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid rgba(148, 163, 184, 0.25);
}

.photo-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 0.85rem;
}

.muted {
  color: #94a3b8;
}

.small {
  font-size: 0.82rem;
}

.tiny {
  font-size: 0.78rem;
  margin: 0.2rem 0 0;
}

.pad {
  padding: 0.5rem 0;
}

.btn {
  display: inline-flex;
  padding: 0.5rem 0.85rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: transparent;
  color: #e2e8f0;
  text-decoration: none;
  align-items: center;
  font-size: 0.88rem;
}

.btn-compact {
  padding: 0.4rem 0.7rem;
  font-size: 0.82rem;
}

.btn.primary {
  background: linear-gradient(90deg, #2563eb, #7c3aed);
  border: none;
  color: #fff;
}

.btn.secondary {
  background: rgba(2, 6, 23, 0.35);
}
</style>
