<script setup>
import { onMounted, ref, computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { api } from '@/services/api.js'

const auth = useAuthStore()

const loading = ref(true)
const loadError = ref('')
const data = ref(null)

const INVOICE_STATUS_ROWS = [
  { key: 'borrador', label: 'Borrador', hint: 'Pendientes de aprobar / enviar' },
  { key: 'aprobada', label: 'Aprobadas', hint: '' },
  { key: 'enviada', label: 'Enviadas', hint: '' },
  { key: 'parcialmente_pagada', label: 'Pago parcial', hint: 'Cobro incompleto' },
  { key: 'pagada', label: 'Pagadas', hint: '' },
]

async function loadDashboard() {
  loading.value = true
  loadError.value = ''
  try {
    data.value = await api('/admin/dashboard')
  } catch (e) {
    loadError.value =
      e.data?.message || e.message || 'No se pudieron cargar los datos del dashboard.'
    data.value = null
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadDashboard()
})

const metrics = computed(() => data.value?.metrics)
const period = computed(() => data.value?.period)
const statusCounts = computed(() => data.value?.invoice_status_counts || {})
const recent = computed(() => data.value?.recent || { services: [], invoices: [], payments: [] })

function formatMoney(value) {
  if (value === undefined || value === null) return '—'
  const n = Number(value)
  if (Number.isNaN(n)) return String(value)
  return new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(n)
}

function formatDateTime(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleString('es-CO', {
    dateStyle: 'short',
    timeStyle: 'short',
  })
}

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso.length === 10 ? `${iso}T12:00:00` : iso)
  if (Number.isNaN(d.getTime())) return iso
  return d.toLocaleDateString('es-CO', { dateStyle: 'medium' })
}
</script>

<template>
  <div class="dash">
    <header class="dash-head">
      <div>
        <h1 class="dash-title">Dashboard</h1>
        <p class="dash-sub">
          Hola, <strong>{{ auth.user?.nombre }}</strong
          >. Resumen del negocio
          <template v-if="period">· <span class="period-pill">{{ period.label }}</span></template>
        </p>
      </div>
      <button type="button" class="btn-refresh" :disabled="loading" @click="loadDashboard">
        {{ loading ? 'Actualizando…' : 'Actualizar datos' }}
      </button>
    </header>

    <div v-if="loadError && !data" class="alert alert--error" role="alert">
      <p>{{ loadError }}</p>
      <button type="button" class="btn-retry" @click="loadDashboard">Reintentar</button>
    </div>

    <div v-else-if="loading && !data" class="skeleton-block" aria-busy="true">
      <p class="muted">Cargando indicadores…</p>
    </div>

    <template v-else>
      <div v-if="loadError" class="alert alert--warn" role="status">
        {{ loadError }} · Los datos mostrados pueden estar desactualizados.
      </div>

      <!-- A: Métricas -->
      <section class="section" aria-labelledby="metrics-title">
        <h2 id="metrics-title" class="section-title">Resumen general</h2>
        <div class="metrics-grid">
          <article class="metric-card">
            <h3 class="metric-label">Facturado del mes</h3>
            <p class="metric-value">{{ formatMoney(metrics?.invoiced_month) }}</p>
            <p class="metric-hint">Total facturas del periodo (sin borrador)</p>
          </article>
          <article class="metric-card">
            <h3 class="metric-label">Recibido del mes</h3>
            <p class="metric-value metric-value--ok">{{ formatMoney(metrics?.received_month) }}</p>
            <p class="metric-hint">Suma de pagos por fecha de pago</p>
          </article>
          <article class="metric-card">
            <h3 class="metric-label">Pendiente por cobrar</h3>
            <p class="metric-value metric-value--warn">{{ formatMoney(metrics?.pending_collect) }}</p>
            <p class="metric-hint">Saldo abierto (facturas no borrador)</p>
          </article>
          <article class="metric-card">
            <h3 class="metric-label">Facturas del mes</h3>
            <p class="metric-value metric-value--neutral">{{ metrics?.invoices_count_month ?? 0 }}</p>
            <p class="metric-hint">Cantidad en el periodo actual</p>
          </article>
        </div>
      </section>

      <!-- B: Actividad reciente -->
      <section class="section" aria-labelledby="recent-title">
        <h2 id="recent-title" class="section-title">Actividad reciente</h2>
        <div class="recent-grid">
          <div class="recent-col">
            <h3 class="recent-heading">Últimos servicios</h3>
            <ul v-if="recent.services?.length" class="recent-list">
              <li v-for="s in recent.services" :key="s.id" class="recent-item">
                <RouterLink :to="`/admin/servicios/${s.id}`" class="recent-link">
                  <span class="recent-main">{{ s.code }}</span>
                  <span class="recent-meta">{{ s.company_name || '—' }} · {{ s.user_name || '—' }}</span>
                  <span class="recent-foot">{{ formatDateTime(s.created_at) }}</span>
                </RouterLink>
              </li>
            </ul>
            <p v-else class="empty">Sin registros recientes.</p>
          </div>
          <div class="recent-col">
            <h3 class="recent-heading">Últimas facturas</h3>
            <ul v-if="recent.invoices?.length" class="recent-list">
              <li v-for="inv in recent.invoices" :key="inv.id" class="recent-item">
                <span class="recent-main">{{ inv.code }}</span>
                <span class="recent-meta">{{ inv.status_label }} · {{ formatMoney(inv.total) }}</span>
                <span class="recent-foot">{{ inv.company_name || '—' }} · {{ formatDateTime(inv.created_at) }}</span>
              </li>
            </ul>
            <p v-else class="empty">Sin facturas registradas aún.</p>
          </div>
          <div class="recent-col">
            <h3 class="recent-heading">Últimos pagos</h3>
            <ul v-if="recent.payments?.length" class="recent-list">
              <li v-for="p in recent.payments" :key="p.id" class="recent-item">
                <RouterLink v-if="p.invoice_id" :to="`/admin/facturas/${p.invoice_id}`" class="recent-link">
                  <span class="recent-main">{{ formatMoney(p.amount) }}</span>
                  <span class="recent-meta">{{ p.method }} · {{ p.invoice_code || '—' }}</span>
                  <span class="recent-foot">{{ p.company_name || '—' }} · {{ formatDate(p.payment_date) }}</span>
                </RouterLink>
                <template v-else>
                  <span class="recent-main">{{ formatMoney(p.amount) }}</span>
                  <span class="recent-meta">{{ p.method }} · {{ p.invoice_code || '—' }}</span>
                  <span class="recent-foot">{{ p.company_name || '—' }} · {{ formatDate(p.payment_date) }}</span>
                </template>
              </li>
            </ul>
            <p v-else class="empty">Sin pagos registrados aún.</p>
          </div>
        </div>
      </section>

      <!-- C: Estado facturación -->
      <section class="section" aria-labelledby="status-title">
        <h2 id="status-title" class="section-title">Estado de facturación</h2>
        <p class="section-lead">Conteo global por estado (todas las facturas).</p>
        <div class="status-grid">
          <article v-for="row in INVOICE_STATUS_ROWS" :key="row.key" class="status-card">
            <h3 class="status-label">{{ row.label }}</h3>
            <p class="status-count">{{ statusCounts[row.key] ?? 0 }}</p>
            <p v-if="row.hint" class="status-hint">{{ row.hint }}</p>
          </article>
        </div>
      </section>

      <!-- D: Accesos rápidos -->
      <section class="section" aria-labelledby="quick-title">
        <h2 id="quick-title" class="section-title">Accesos rápidos</h2>
        <div class="quick-grid">
          <RouterLink to="/admin/facturas/nueva" class="quick-card">
            <span class="quick-icon" aria-hidden="true">+</span>
            <span class="quick-text">Crear factura</span>
          </RouterLink>
          <RouterLink to="/admin/servicios" class="quick-card">
            <span class="quick-icon" aria-hidden="true">◇</span>
            <span class="quick-text">Ver servicios</span>
          </RouterLink>
          <RouterLink to="/admin/empresas" class="quick-card">
            <span class="quick-icon" aria-hidden="true">◎</span>
            <span class="quick-text">Registrar / ver empresas</span>
          </RouterLink>
          <RouterLink to="/admin/facturas" class="quick-card">
            <span class="quick-icon" aria-hidden="true">≡</span>
            <span class="quick-text">Ver facturas</span>
          </RouterLink>
          <RouterLink to="/admin/empleados" class="quick-card">
            <span class="quick-icon" aria-hidden="true">⊕</span>
            <span class="quick-text">Usuarios y empleados</span>
          </RouterLink>
          <RouterLink to="/admin/empleados/rendimiento" class="quick-card">
            <span class="quick-icon" aria-hidden="true">▤</span>
            <span class="quick-text">Rendimiento por empleado</span>
          </RouterLink>
        </div>
      </section>
    </template>
  </div>
</template>

<style scoped>
.dash {
  max-width: 1200px;
}

.dash-head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.dash-title {
  margin: 0;
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: -0.02em;
}

.dash-sub {
  margin: 0.35rem 0 0;
  color: #94a3b8;
  font-size: 0.95rem;
}

.dash-sub strong {
  color: #e2e8f0;
}

.period-pill {
  display: inline-block;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  background: rgba(59, 130, 246, 0.15);
  border: 1px solid rgba(59, 130, 246, 0.35);
  color: #93c5fd;
  font-size: 0.8rem;
  font-weight: 600;
}

.btn-refresh {
  border: 1px solid rgba(59, 130, 246, 0.45);
  background: rgba(37, 99, 235, 0.2);
  color: #e0f2fe;
  border-radius: 10px;
  padding: 0.5rem 1rem;
  font: inherit;
  font-weight: 600;
  font-size: 0.875rem;
  cursor: pointer;
}

.btn-refresh:hover:not(:disabled) {
  background: rgba(37, 99, 235, 0.35);
}

.btn-refresh:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.section {
  margin-bottom: 2rem;
}

.section-title {
  margin: 0 0 0.35rem;
  font-size: 1.1rem;
  font-weight: 600;
  color: #f1f5f9;
}

.section-lead {
  margin: 0 0 1rem;
  font-size: 0.875rem;
  color: #94a3b8;
}

.metrics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
}

.metric-card {
  padding: 1.1rem 1.15rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.65);
}

.metric-label {
  margin: 0;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #94a3b8;
}

.metric-value {
  margin: 0.5rem 0 0;
  font-size: 1.35rem;
  font-weight: 700;
  color: #f8fafc;
  font-variant-numeric: tabular-nums;
}

.metric-value--ok {
  color: #6ee7b7;
}

.metric-value--warn {
  color: #fcd34d;
}

.metric-value--neutral {
  color: #93c5fd;
}

.metric-hint {
  margin: 0.4rem 0 0;
  font-size: 0.78rem;
  color: #64748b;
  line-height: 1.35;
}

.recent-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 1.25rem;
}

.recent-col {
  padding: 1rem 1.1rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.18);
  background: rgba(15, 23, 42, 0.5);
  min-height: 180px;
}

.recent-heading {
  margin: 0 0 0.75rem;
  font-size: 0.85rem;
  font-weight: 600;
  color: #cbd5e1;
}

.recent-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.recent-item {
  padding-bottom: 0.65rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
}

.recent-link {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  text-decoration: none;
  color: inherit;
  border-radius: 8px;
  margin: -0.25rem;
  padding: 0.25rem;
  transition: background 0.12s ease;
}

.recent-link:hover {
  background: rgba(56, 189, 248, 0.08);
}

.recent-link:hover .recent-main {
  color: #7dd3fc;
}

.recent-item:last-child {
  border-bottom: none;
  padding-bottom: 0;
}

.recent-main {
  font-weight: 600;
  color: #e2e8f0;
  font-size: 0.9rem;
}

.recent-meta {
  font-size: 0.8rem;
  color: #94a3b8;
}

.recent-foot {
  font-size: 0.72rem;
  color: #64748b;
}

.empty {
  margin: 0;
  font-size: 0.875rem;
  color: #64748b;
}

.status-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 0.75rem;
}

.status-card {
  padding: 0.85rem 1rem;
  border-radius: 12px;
  border: 1px solid rgba(148, 163, 184, 0.15);
  background: rgba(30, 41, 59, 0.45);
}

.status-label {
  margin: 0;
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #94a3b8;
}

.status-count {
  margin: 0.35rem 0 0;
  font-size: 1.5rem;
  font-weight: 700;
  color: #f1f5f9;
  font-variant-numeric: tabular-nums;
}

.status-hint {
  margin: 0.25rem 0 0;
  font-size: 0.7rem;
  color: #64748b;
  line-height: 1.3;
}

.quick-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 0.85rem;
}

.quick-card {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 1rem 1.15rem;
  border-radius: 12px;
  border: 1px solid rgba(59, 130, 246, 0.35);
  background: rgba(37, 99, 235, 0.12);
  text-decoration: none;
  color: #e0f2fe;
  font-weight: 600;
  font-size: 0.95rem;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.quick-card:hover {
  background: rgba(37, 99, 235, 0.22);
  border-color: rgba(59, 130, 246, 0.55);
}

.quick-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: rgba(59, 130, 246, 0.25);
  font-size: 1.1rem;
  color: #93c5fd;
}

.quick-text {
  flex: 1;
}

.alert {
  padding: 1rem 1.15rem;
  border-radius: 12px;
  margin-bottom: 1.25rem;
  font-size: 0.9rem;
}

.alert--error {
  background: rgba(127, 29, 29, 0.35);
  border: 1px solid rgba(248, 113, 113, 0.35);
  color: #fecaca;
}

.alert--warn {
  background: rgba(120, 53, 15, 0.35);
  border: 1px solid rgba(251, 191, 36, 0.35);
  color: #fde68a;
}

.btn-retry {
  margin-top: 0.75rem;
  border: 1px solid rgba(248, 113, 113, 0.5);
  background: transparent;
  color: #fecaca;
  border-radius: 8px;
  padding: 0.4rem 0.85rem;
  font: inherit;
  cursor: pointer;
}

.skeleton-block {
  padding: 2rem;
  border-radius: 14px;
  border: 1px dashed rgba(148, 163, 184, 0.25);
}

.muted {
  margin: 0;
  color: #94a3b8;
}
</style>
