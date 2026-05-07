<script setup>
import { ref, watch } from 'vue'
import { fetchAdminServiceCatalogItem } from '@/services/servicesApi.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  /** ID del ítem de catálogo */
  catalogId: { type: [Number, String], default: null },
})

const emit = defineEmits(['close', 'edit', 'toggle-status', 'delete'])

function formatDateShort(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { year: 'numeric', month: 'short', day: 'numeric' })
}

const item = ref(null)
const loading = ref(false)
const error = ref('')

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

async function reload() {
  const id = props.catalogId
  if (id == null || id === '') return
  error.value = ''
  loading.value = true
  try {
    item.value = await fetchAdminServiceCatalogItem(id)
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar el ítem.'
    item.value = null
  } finally {
    loading.value = false
  }
}

watch(
  () => [props.open, props.catalogId],
  async ([op, id]) => {
    if (!op) {
      item.value = null
      error.value = ''
      return
    }
    if (id == null || id === '') return
    await reload()
  }
)

defineExpose({ reload })
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="drawer-root" aria-hidden="false">
      <div class="drawer-backdrop" @click.self="emit('close')" />
      <aside
        class="drawer-panel"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="item ? 'drawer-cat-title' : 'drawer-cat-loading'"
      >
        <header class="drawer-header">
          <div class="drawer-header-text">
            <p class="drawer-kicker">Ítem de catálogo</p>
            <h2 :id="item ? 'drawer-cat-title' : 'drawer-cat-loading'" class="drawer-title">
              {{ item?.code || (loading ? 'Cargando…' : '—') }}
            </h2>
            <p v-if="item" class="muted drawer-sub">{{ item.name }}</p>
          </div>
          <div class="drawer-header-actions">
            <button type="button" class="drawer-close" aria-label="Cerrar panel" @click="emit('close')">
              <span aria-hidden="true">×</span>
            </button>
          </div>
        </header>

        <div class="drawer-toolbar" v-if="item">
          <button type="button" class="btn secondary btn-compact" @click="emit('edit', item)">Editar</button>
          <button type="button" class="btn secondary btn-compact" @click="emit('toggle-status', item)">
            {{ item.status === 'activo' ? 'Desactivar' : 'Activar' }}
          </button>
          <button type="button" class="btn secondary btn-compact danger-outline" @click="emit('delete', item)">
            Eliminar
          </button>
        </div>

        <div class="drawer-body">
          <p v-if="loading" class="muted pad">Cargando…</p>
          <p v-else-if="error" class="banner err">{{ error }}</p>

          <template v-else-if="item">
            <div class="card status-row" :data-phase="item.status">
              <span class="pill" :data-st="item.status">{{ item.status === 'activo' ? 'Activo' : 'Inactivo' }}</span>
            </div>

            <div class="card">
              <h2>Datos</h2>
              <p class="strong">{{ item.name }}</p>
              <p v-if="item.description" class="desc">{{ item.description }}</p>
              <p v-else class="muted">Sin descripción.</p>
              <p class="mt"><span class="label">Precio orientativo</span> {{ money(item.base_price) }}</p>
              <p>
                <span class="label">IVA (%)</span>
                {{
                  item.iva_percent != null && String(item.iva_percent).trim() !== ''
                    ? `${item.iva_percent}% (sobre el importe de línea al facturar)`
                    : '0%'
                }}
              </p>
              <p>
                <span class="label">Margen al facturar</span>
                <template
                  v-if="item.technician_discount_percent != null && String(item.technician_discount_percent).trim() !== ''"
                >
                  {{ item.technician_discount_percent }}% de margen técnico → empresa (override por ítem; si no, aplica el global).
                </template>
                <template v-else> Usa el porcentaje global de la pestaña «Importar y precios» del catálogo. </template>
              </p>
              <p class="muted small">
                Creado: {{ formatDateShort(item.created_at) }} · Actualizado: {{ formatDateShort(item.updated_at) }}
              </p>
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
  z-index: 90;
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
  gap: 1rem;
  border-left: 4px solid rgba(148, 163, 184, 0.35);
}

.status-row[data-phase='activo'] {
  border-left-color: rgba(74, 222, 128, 0.55);
  background: rgba(74, 222, 128, 0.06);
}
.status-row[data-phase='inactivo'] {
  border-left-color: rgba(148, 163, 184, 0.45);
  background: rgba(148, 163, 184, 0.06);
}

.strong {
  font-weight: 600;
  color: #f1f5f9;
}

.desc {
  margin: 0.5rem 0 0;
  white-space: pre-wrap;
  word-break: break-word;
  color: #cbd5e1;
  font-size: 0.88rem;
  line-height: 1.45;
}

.label {
  display: inline-block;
  min-width: 5.5rem;
  color: #94a3b8;
  font-size: 0.82rem;
}

.mt {
  margin-top: 0.65rem;
}

.pill {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.8rem;
  font-weight: 600;
}
.pill[data-st='activo'] {
  color: #86efac;
  border: 1px solid rgba(74, 222, 128, 0.4);
}
.pill[data-st='inactivo'] {
  color: #94a3b8;
  border: 1px solid rgba(148, 163, 184, 0.4);
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
  margin-top: 0.75rem;
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

.btn.secondary {
  background: rgba(2, 6, 23, 0.35);
}

.btn.danger-outline {
  border-color: rgba(248, 113, 113, 0.45);
  color: #fca5a5;
}

.btn.danger-outline:hover {
  background: rgba(248, 113, 113, 0.1);
}
</style>
