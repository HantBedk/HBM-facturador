<script setup>
import { computed, ref, watch } from 'vue'
import { fetchAdminActivityLogs } from '@/services/adminActivityLogsApi.js'

const scope = ref('all')
const loading = ref(true)
const error = ref('')
const groups = ref([])
/** YYYY-MM-DD; el servidor interpreta el día en APP_TIMEZONE y devuelve la fecha canónica en `date`. */
const selectedDate = ref('')
const timezoneLabel = ref('')

/** Mes mostrado en el calendario (puede diferir del mes de `selectedDate` si el usuario navega con ‹ ›). */
const viewMonth = ref({ year: new Date().getFullYear(), month: new Date().getMonth() + 1 })

/** Metadatos del calendario devueltos por el servidor (hoy en TZ app, días con al menos un movimiento). */
const calendarMeta = ref({
  month: '',
  today: '',
  dates_with_activity: [],
})

const scopeLabel = computed(() => (scope.value === 'facturas' ? 'facturas y pagos' : 'toda la actividad registrada'))

const activityDatesSet = computed(() => new Set(calendarMeta.value.dates_with_activity || []))

const weekdayLabels = ['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom']

function formatYmdParts(year, month, day) {
  return `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

function calendarMonthParam() {
  const { year, month } = viewMonth.value
  return `${year}-${String(month).padStart(2, '0')}`
}

function syncViewMonthFromYmd(ymd) {
  const parts = String(ymd || '')
    .split('-')
    .map(Number)
  if (parts.length >= 2 && !parts.some(Number.isNaN)) {
    viewMonth.value = { year: parts[0], month: parts[1] }
  }
}

function monthTitleEs() {
  const { year, month } = viewMonth.value
  try {
    return new Date(year, month - 1, 1).toLocaleDateString('es-CO', { month: 'long', year: 'numeric' })
  } catch {
    return `${month}/${year}`
  }
}

const calendarCells = computed(() => {
  const { year: y, month: m } = viewMonth.value
  const todayStr = calendarMeta.value.today || ''
  const act = activityDatesSet.value

  const first = new Date(y, m - 1, 1)
  const lastDay = new Date(y, m, 0).getDate()
  const jsDow = first.getDay()
  const mondayFirst = (jsDow + 6) % 7

  const cells = []
  const prevMonthLast = new Date(y, m - 1, 0).getDate()
  const py = m === 1 ? y - 1 : y
  const pm = m === 1 ? 12 : m - 1
  for (let i = 0; i < mondayFirst; i++) {
    const dayNum = prevMonthLast - mondayFirst + i + 1
    cells.push({
      kind: 'outside',
      day: dayNum,
      ymd: formatYmdParts(py, pm, dayNum),
      disabled: true,
    })
  }

  for (let d = 1; d <= lastDay; d++) {
    const ymd = formatYmdParts(y, m, d)
    const isFuture = todayStr && ymd > todayStr
    const isToday = todayStr && ymd === todayStr
    const hasActivity = act.has(ymd)
    cells.push({
      kind: 'in-month',
      day: d,
      ymd,
      disabled: !!isFuture,
      isToday: !!isToday,
      hasActivity,
      isSelected: selectedDate.value === ymd,
    })
  }

  let ny = m === 12 ? y + 1 : y
  let nm = m === 12 ? 1 : m + 1
  let nextDay = 1
  while (cells.length % 7 !== 0) {
    const ymd = formatYmdParts(ny, nm, nextDay)
    cells.push({
      kind: 'outside',
      day: nextDay,
      ymd,
      disabled: true,
    })
    nextDay += 1
  }

  return cells
})

async function shiftViewMonth(delta) {
  if (loading.value) return
  let { year, month } = viewMonth.value
  month += delta
  if (month < 1) {
    month = 12
    year -= 1
  }
  if (month > 12) {
    month = 1
    year += 1
  }
  viewMonth.value = { year, month }
  await load()
}

function cellClass(cell) {
  if (cell.kind === 'outside' || cell.disabled) {
    return 'cal-cell cal-cell--muted'
  }
  if (cell.isToday) {
    return 'cal-cell cal-cell--today'
  }
  if (cell.hasActivity) {
    return 'cal-cell cal-cell--has'
  }
  return 'cal-cell cal-cell--empty'
}

function selectCalendarDay(cell) {
  if (!cell || cell.disabled || cell.kind !== 'in-month') return
  selectedDate.value = cell.ymd
}

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
  const map = {
    factura_creada: 'Factura creada',
    factura_editada: 'Factura editada',
    factura_aprobada: 'Factura aprobada',
    factura_enviada: 'Factura enviada',
    factura_eliminada: 'Factura eliminada',
    factura_token_publico_regenerado: 'Código consulta pública',
    factura_auto_borrador: 'Borradores automáticos (sistema)',
    automation_facturacion_actualizada: 'Facturación automática (borradores)',
    pago_registrado: 'Pago registrado',
    pago_eliminado: 'Pago eliminado',
    servicio_creado: 'Servicio creado',
    servicio_editado: 'Servicio editado',
    servicio_archivado: 'Servicio archivado / eliminado',
    servicio_pago_tecnico: 'Pago / abono referencia técnico',
    servicio_asignado_tecnico: 'Servicio asignado a técnico',
    servicio_asignacion_completada: 'Asignación completada (técnico)',
    servicio_asignacion_rechazada: 'Asignación rechazada (técnico)',
  }
  return map[code] || code
}

/** Evita un segundo GET al rellenar el calendario con el día canónico del servidor (primera carga sin `date`). */
let ignoreNextDateWatch = false

async function load() {
  error.value = ''
  loading.value = true
  try {
    const params = {
      scope: scope.value,
      calendar_month: calendarMonthParam(),
    }
    const dateStr = String(selectedDate.value || '').trim()
    if (dateStr) {
      params.date = dateStr
    }
    const r = await fetchAdminActivityLogs(params)
    timezoneLabel.value = r.timezone || ''
    groups.value = r.groups || []
    if (r.calendar) {
      calendarMeta.value = {
        month: r.calendar.month || '',
        today: r.calendar.today || '',
        dates_with_activity: Array.isArray(r.calendar.dates_with_activity)
          ? r.calendar.dates_with_activity
          : [],
      }
    }
    if (!dateStr && r.date) {
      ignoreNextDateWatch = true
      selectedDate.value = r.date
      syncViewMonthFromYmd(r.date)
    } else if (r.date && r.date !== dateStr) {
      selectedDate.value = r.date
      syncViewMonthFromYmd(r.date)
    }
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar el historial.'
    groups.value = []
  } finally {
    loading.value = false
  }
}

watch([scope, selectedDate], async () => {
  if (ignoreNextDateWatch) {
    ignoreNextDateWatch = false
    return
  }
  await load()
}, { immediate: true })
</script>

<template>
  <section class="historial-page">
    <header class="head">
      <h1>Historial de movimientos</h1>
      <p class="lede">
        Elija un día en el calendario para ver los movimientos de ese día (agrupación por persona, fecha y hora). El día se
        interpreta según la zona horaria del servidor
        <template v-if="timezoneLabel"> (<span class="tz-hint">{{ timezoneLabel }}</span>) </template>.
        Puede acotar a <strong>facturas y pagos</strong> con el filtro de tipo.
      </p>
    </header>

    <div class="toolbar">
      <div class="cal-wrap">
        <div class="cal-head">
          <button
            type="button"
            class="cal-nav"
            :disabled="loading"
            title="Mes anterior"
            aria-label="Mes anterior"
            @click="shiftViewMonth(-1)"
          >
            ‹
          </button>
          <h2 class="cal-title">{{ monthTitleEs() }}</h2>
          <button
            type="button"
            class="cal-nav"
            :disabled="loading"
            title="Mes siguiente"
            aria-label="Mes siguiente"
            @click="shiftViewMonth(1)"
          >
            ›
          </button>
        </div>
        <div class="cal-legend" role="list">
          <span class="leg leg--has" role="listitem">Con movimiento</span>
          <span class="leg leg--empty" role="listitem">Sin movimiento</span>
          <span class="leg leg--today" role="listitem">Hoy</span>
          <span class="leg leg--muted" role="listitem">No seleccionable</span>
        </div>
        <div class="cal-grid" role="grid" :aria-busy="loading">
          <div v-for="(w, wi) in weekdayLabels" :key="'w' + wi" class="cal-dow" role="columnheader">
            {{ w }}
          </div>
          <button
            v-for="(cell, ci) in calendarCells"
            :key="ci"
            type="button"
            role="gridcell"
            :disabled="cell.disabled || cell.kind !== 'in-month'"
            :aria-selected="cell.kind === 'in-month' ? cell.isSelected : undefined"
            :aria-current="cell.isToday ? 'date' : undefined"
            :class="[cellClass(cell), cell.isSelected ? 'cal-cell--sel' : '']"
            @click="selectCalendarDay(cell)"
          >
            {{ cell.day }}
          </button>
        </div>
      </div>

      <label class="field">
        <span class="lbl">Tipo de registro</span>
        <select v-model="scope" class="sel">
          <option value="all">Todo (servicios y facturas)</option>
          <option value="facturas">Solo facturas y pagos</option>
        </select>
      </label>
    </div>

    <p v-if="error" class="banner err">{{ error }}</p>
    <p v-if="loading" class="muted pad">Cargando…</p>

    <template v-else>
      <p v-if="!groups.length" class="muted pad">No hay movimientos para mostrar con este filtro.</p>

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
      Movimientos del día seleccionado ({{ scopeLabel }}). Solo aparecen acciones que el sistema registra automáticamente.
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

.tz-hint {
  font-weight: 600;
  color: #cbd5e1;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 1.25rem;
  margin-bottom: 1rem;
  align-items: flex-start;
}

.cal-wrap {
  flex: 1 1 18rem;
  min-width: min(100%, 20rem);
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.45);
  padding: 0.85rem 1rem 1rem;
}

.cal-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-bottom: 0.65rem;
}

.cal-title {
  margin: 0;
  flex: 1;
  text-align: center;
  font-size: 1rem;
  font-weight: 700;
  color: #e2e8f0;
  text-transform: capitalize;
}

.cal-nav {
  flex: 0 0 auto;
  width: 2.25rem;
  height: 2.25rem;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(30, 41, 59, 0.65);
  color: #e2e8f0;
  font-size: 1.15rem;
  line-height: 1;
  cursor: pointer;
  transition: background 0.15s ease;
}

.cal-nav:hover:not(:disabled) {
  background: rgba(51, 65, 85, 0.85);
}

.cal-nav:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.cal-legend {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 0.85rem;
  margin-bottom: 0.65rem;
  font-size: 0.68rem;
  color: #94a3b8;
}

.leg {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}
.leg::before {
  content: '';
  width: 0.55rem;
  height: 0.55rem;
  border-radius: 3px;
}
.leg--has::before {
  background: rgba(52, 211, 153, 0.85);
}
.leg--empty::before {
  background: rgba(248, 113, 113, 0.85);
}
.leg--today::before {
  background: rgba(59, 130, 246, 0.95);
}
.leg--muted::before {
  background: rgba(71, 85, 105, 0.65);
}

.cal-grid {
  display: grid;
  grid-template-columns: repeat(7, minmax(0, 1fr));
  gap: 0.35rem;
}

.cal-dow {
  text-align: center;
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #64748b;
  padding: 0.2rem 0;
}

.cal-cell {
  aspect-ratio: 1;
  min-height: 2.35rem;
  border-radius: 10px;
  border: 1px solid transparent;
  font-size: 0.82rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  cursor: pointer;
  transition:
    background 0.15s ease,
    border-color 0.15s ease,
    transform 0.12s ease;
}

.cal-cell:active:not(:disabled) {
  transform: scale(0.96);
}

.cal-cell:focus-visible:not(:disabled) {
  outline: 2px solid rgba(56, 189, 248, 0.85);
  outline-offset: 1px;
}

.cal-cell:disabled {
  cursor: not-allowed;
}

.cal-cell--muted {
  background: rgba(30, 41, 59, 0.35);
  color: #475569;
  border-color: rgba(51, 65, 85, 0.35);
  opacity: 0.72;
}

.cal-cell--has {
  background: rgba(16, 185, 129, 0.22);
  color: #a7f3d0;
  border-color: rgba(52, 211, 153, 0.45);
}

.cal-cell--has:hover:not(:disabled) {
  background: rgba(16, 185, 129, 0.32);
}

.cal-cell--empty {
  background: rgba(248, 113, 113, 0.14);
  color: #fecaca;
  border-color: rgba(248, 113, 113, 0.35);
}

.cal-cell--empty:hover:not(:disabled) {
  background: rgba(248, 113, 113, 0.22);
}

.cal-cell--today {
  background: rgba(37, 99, 235, 0.35);
  color: #dbeafe;
  border-color: rgba(96, 165, 250, 0.75);
  box-shadow: inset 0 0 0 1px rgba(59, 130, 246, 0.35);
}

.cal-cell--today:hover:not(:disabled) {
  background: rgba(37, 99, 235, 0.48);
}

.cal-cell--sel {
  box-shadow:
    0 0 0 2px rgba(251, 191, 36, 0.95),
    inset 0 0 0 1px rgba(251, 191, 36, 0.35);
  z-index: 1;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  flex: 0 1 14rem;
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