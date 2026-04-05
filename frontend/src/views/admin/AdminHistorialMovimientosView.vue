<script setup>
import { computed, ref, watch } from 'vue'
import { fetchAdminActivityLogs } from '@/services/adminActivityLogsApi.js'

const scope = ref('all')
const limit = ref(150)
const loading = ref(true)
const error = ref('')
const groups = ref([])

const scopeLabel = computed(() => (scope.value === 'facturas' ? 'facturas y pagos' : 'toda la actividad registrada'))

function rolEtiqueta(rol) {
  if (rol === 'super_admin') return 'Super administrador'
  if (rol === 'admin') return 'Administrador'
  if (rol === 'empleado') return 'Técnico'
  return rol || '—'
}

function fechaHora(iso) {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString('es-CO', {
      dateStyle: 'short',
      timeStyle: 'short',
    })
  } catch {
    return iso
  }
}

function accionEtiqueta(code) {
  const m = {
    factura_creada: 'Factura creada',
    factura_editada: 'Factura editada',
    factura_aprobada: 'Factura aprobada',
    factura_enviada: 'Factura enviada',
    factura_eliminada: 'Factura eliminada',
    factura_token_publico_regenerado: 'Código consulta pública',
    automation_facturacion_actualizada: 'Facturación automática (borradores)',
    pago_registrado: 'Pago registrado',
    pago_eliminado: 'Pago eliminado',
    servicio_creado: 'Servicio creado',
    servicio_editado: 'Servicio editado',
    servicio_archivado: 'Servicio archivado / eliminado',
  }
  return m[code] || code
}

async function load() {
  error.value = ''
  loading.value = true
  try {
    const r = await fetchAdminActivityLogs({ scope: scope.value, limit: limit.value })
    groups.value = r.groups || []
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar el historial.'
    groups.value = []
  } finally {
    loading.value = false
  }
}

watch([scope, limit], load, { immediate: true })
</script>

<template>
  <section class="historial-page">
    <header class="head">
      <h1>Historial de movimientos</h1>
      <p class="lede">
        Registro de acciones registradas en el sistema (administradores, super administradores y técnicos). Las relativas a
        <strong>facturas y pagos</strong> puedes filtrarlas abajo. Cada bloque agrupa lo hecho por la misma persona, con fecha
        y hora.
      </p>
    </header>

    <div class="toolbar">
      <label class="field">
        <span class="lbl">Ámbito</span>
        <select v-model="scope" class="sel">
          <option value="all">Todo (servicios y facturas)</option>
          <option value="facturas">Solo facturas y pagos</option>
        </select>
      </label>
      <label class="field">
        <span class="lbl">Cantidad máx.</span>
        <select v-model.number="limit" class="sel">
          <option :value="50">50</option>
          <option :value="100">100</option>
          <option :value="150">150</option>
          <option :value="200">200</option>
          <option :value="300">300</option>
        </select>
      </label>
    </div>

    <p v-if="error" class="banner err">{{ error }}</p>
    <p v-if="loading" class="muted pad">Cargando…</p>

    <template v-else>
      <p v-if="!groups.length" class="muted pad">No hay movimientos para mostrar en este ámbito.</p>

      <div v-for="(g, idx) in groups" :key="idx" class="group">
        <div class="group-head">
          <span class="who">{{
            g.user ? g.user.nombre : 'Usuario no disponible o eliminado'
          }}</span>
          <span v-if="g.user" class="rol">{{ rolEtiqueta(g.user.rol) }}</span>
          <span v-if="g.user?.correo" class="mail">{{ g.user.correo }}</span>
        </div>
        <ul class="mov-list">
          <li v-for="m in g.movimientos" :key="m.id" class="mov-row">
            <time class="when" :datetime="m.created_at">{{ fechaHora(m.created_at) }}</time>
            <span class="act">{{ accionEtiqueta(m.action) }}</span>
            <span class="desc">{{ m.description }}</span>
          </li>
        </ul>
      </div>
    </template>

    <p class="foot-note">
      Mostrando hasta {{ limit }} movimientos recientes ({{ scopeLabel }}). Solo aparecen acciones que el sistema ya
      registra automáticamente.
    </p>
  </section>
</template>

<style scoped>
.historial-page {
  max-width: 960px;
  margin: 0 auto;
}

.head {
  margin-bottom: 1.25rem;
}

h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  color: #f8fafc;
}

.lede {
  margin: 0;
  max-width: 46rem;
  font-size: 0.88rem;
  color: #94a3b8;
  line-height: 1.45;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  margin-bottom: 1rem;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.lbl {
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #64748b;
}

.sel {
  min-width: 12rem;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(15, 23, 42, 0.75);
  color: #e2e8f0;
  padding: 0.45rem 0.65rem;
  font-size: 0.88rem;
}

.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}

.muted {
  color: #94a3b8;
}

.pad {
  padding: 0.75rem 0;
}

.group {
  margin-bottom: 1.5rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.45);
  overflow: hidden;
}

.group-head {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.5rem 1rem;
  padding: 0.75rem 1rem;
  background: rgba(30, 41, 59, 0.5);
  border-bottom: 1px solid rgba(148, 163, 184, 0.15);
}

.who {
  font-weight: 700;
  color: #f1f5f9;
  font-size: 0.95rem;
}

.rol {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #7dd3fc;
  padding: 0.15rem 0.45rem;
  border-radius: 6px;
  background: rgba(56, 189, 248, 0.12);
}

.mail {
  font-size: 0.78rem;
  color: #94a3b8;
}

.mov-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.mov-row {
  display: grid;
  grid-template-columns: 9.5rem minmax(7rem, 10rem) 1fr;
  gap: 0.65rem 1rem;
  padding: 0.65rem 1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
  font-size: 0.85rem;
  align-items: start;
}

.mov-row:last-child {
  border-bottom: none;
}

@media (max-width: 640px) {
  .mov-row {
    grid-template-columns: 1fr;
  }
}

.when {
  color: #cbd5e1;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.act {
  color: #a5b4fc;
  font-weight: 600;
  font-size: 0.8rem;
}

.desc {
  color: #e2e8f0;
  line-height: 1.4;
}

.foot-note {
  margin-top: 1.5rem;
  font-size: 0.78rem;
  color: #64748b;
  max-width: 42rem;
}
</style>
