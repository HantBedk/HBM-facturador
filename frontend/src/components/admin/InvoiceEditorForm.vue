<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useClientSortedRows } from '@/composables/useClientSortedRows.js'
import { fetchAdminCompanies } from '@/services/companiesApi.js'
import {
  createInvoice,
  fetchAdminInvoice,
  fetchAvailableServicesForInvoice,
  updateInvoice,
} from '@/services/invoicesApi.js'

const props = defineProps({
  /** null o vacío = nueva factura */
  invoiceId: { type: [String, Number], default: null },
  /** true = panel lateral (sin cabecera de página; emite cancel/saved) */
  embedded: { type: Boolean, default: false },
})

const emit = defineEmits(['cancel', 'saved'])

const router = useRouter()

const resolvedId = computed(() => {
  const v = props.invoiceId
  if (v === null || v === undefined || v === '') return null
  return String(v)
})

const isEdit = computed(() => !!resolvedId.value)

const companies = ref([])
const loading = ref(true)
const loadingServices = ref(false)
const saving = ref(false)
const loadError = ref('')
const saveError = ref('')

const companyId = ref('')
const periodYear = ref(new Date().getFullYear())
const periodMonth = ref(new Date().getMonth() + 1)
/** Opcional: primer mes a incluir cargos fijos no facturados hasta el periodo (misma factura). */
const recurringBacklogStartYear = ref('')
const recurringBacklogStartMonth = ref('')

const available = ref([])
const selectedIds = ref([])
const selectAllCheckboxRef = ref(null)

const {
  sortedRows: sortedAvailable,
  toggleSort: toggleAvailSort,
  sortIndicator: availSortInd,
  ariaSort: availAriaSort,
} = useClientSortedRows(
  available,
  {
    code: (r) => r.code || '',
    service_date: (r) => r.service_date || '',
    description: (r) => r.description || '',
    amount: (r) => Number(r.amount) || 0,
  },
  { initialKey: 'service_date', initialDir: 'desc' }
)

const invoice = ref(null)

const companiesInPickerTab = computed(() => (companies.value || []).filter((c) => !c.es_cliente_puntual))

function companyOptionLabel(c) {
  if (!c) return ''
  return c.nombre
}

const skipWatch = ref(true)

const MONTHS = [
  [1, 'Enero'],
  [2, 'Febrero'],
  [3, 'Marzo'],
  [4, 'Abril'],
  [5, 'Mayo'],
  [6, 'Junio'],
  [7, 'Julio'],
  [8, 'Agosto'],
  [9, 'Septiembre'],
  [10, 'Octubre'],
  [11, 'Noviembre'],
  [12, 'Diciembre'],
]

function isSelected(id) {
  return selectedIds.value.includes(id)
}

function toggleId(row) {
  if (row?.is_recurring) return
  const id = row.id
  const i = selectedIds.value.indexOf(id)
  if (i >= 0) {
    selectedIds.value = selectedIds.value.filter((x) => x !== id)
  } else {
    selectedIds.value = [...selectedIds.value, id]
  }
}

const allAvailableSelected = computed(() => {
  const rows = sortedAvailable.value
  if (!rows.length) return false
  return rows.every((r) => selectedIds.value.includes(r.id))
})

const someAvailableSelected = computed(() => {
  const rows = sortedAvailable.value
  if (!rows.length) return false
  const n = rows.filter((r) => selectedIds.value.includes(r.id)).length
  return n > 0 && n < rows.length
})

function selectAllAvailableServices() {
  selectedIds.value = sortedAvailable.value.map((r) => r.id)
}

function clearServiceSelection() {
  const recurring = (available.value || []).filter((r) => r.is_recurring).map((r) => r.id)
  selectedIds.value = [...recurring]
}

function toggleSelectAllCheckbox() {
  if (allAvailableSelected.value) {
    clearServiceSelection()
  } else {
    selectAllAvailableServices()
  }
}

watch([allAvailableSelected, someAvailableSelected, sortedAvailable], () => {
  nextTick(() => {
    const el = selectAllCheckboxRef.value
    if (el && 'indeterminate' in el) {
      el.indeterminate = someAvailableSelected.value
    }
  })
}, { flush: 'post' })

const totalPreview = computed(() => {
  const rows = sortedAvailable.value.filter((row) => isSelected(row.id))
  let t = 0
  for (const row of rows) {
    t += Number(row.amount) || 0
  }
  return t
})

const canSubmitInvoice = computed(() => !!companyId.value)

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

function backlogQueryParams() {
  const y = Number(recurringBacklogStartYear.value)
  const m = Number(recurringBacklogStartMonth.value)
  if (!Number.isFinite(y) || !Number.isFinite(m) || m < 1 || m > 12) return {}
  return { recurring_backlog_start_year: y, recurring_backlog_start_month: m }
}

async function loadAvailable() {
  loadError.value = ''
  if (!companyId.value) {
    available.value = []
    return
  }
  loadingServices.value = true
  try {
    const data = await fetchAvailableServicesForInvoice({
      company_id: companyId.value,
      period_year: Number(periodYear.value),
      period_month: Number(periodMonth.value),
      invoice_id: isEdit.value ? resolvedId.value : undefined,
      ...backlogQueryParams(),
    })
    available.value = data
    const valid = new Set(data.map((r) => r.id))
    const recurringIds = data.filter((r) => r.is_recurring).map((r) => r.id)
    const kept = selectedIds.value.filter((id) => valid.has(id))
    selectedIds.value = [...new Set([...kept, ...recurringIds])]
  } catch (e) {
    loadError.value = e.data?.message || e.message || 'No se pudieron cargar los servicios.'
    available.value = []
  } finally {
    loadingServices.value = false
  }
}

watch([companyId, periodYear, periodMonth, recurringBacklogStartYear, recurringBacklogStartMonth], () => {
  if (skipWatch.value) return
  loadAvailable()
})

async function bootstrap() {
  loadError.value = ''
  loading.value = true
  skipWatch.value = true
  try {
    companies.value = await fetchAdminCompanies({ company_kind: 'registered' })
  } catch {
    companies.value = []
  }

  if (isEdit.value) {
    try {
      invoice.value = await fetchAdminInvoice(resolvedId.value)
      if (invoice.value.status !== 'borrador') {
        loadError.value = 'Solo se pueden editar facturas en estado borrador.'
        loading.value = false
        skipWatch.value = false
        return
      }
      if (invoice.value.company_id != null && invoice.value.company_id !== '') {
        companyId.value = String(invoice.value.company_id)
      } else {
        loadError.value =
          'Esta factura no está asociada a una empresa registrada; ya no se puede editar desde aquí. Elimínela y cree una factura nueva.'
        loading.value = false
        skipWatch.value = false
        return
      }
      periodYear.value = invoice.value.period_year
      periodMonth.value = invoice.value.period_month
      selectedIds.value = (invoice.value.services || []).map((s) => s.id)
      await loadAvailable()
    } catch (e) {
      loadError.value = e.data?.message || e.message || 'No se pudo cargar la factura.'
    }
  } else {
    companyId.value = ''
    periodYear.value = new Date().getFullYear()
    periodMonth.value = new Date().getMonth() + 1
    invoice.value = null
    available.value = []
    selectedIds.value = []
  }

  loading.value = false
  skipWatch.value = false
}

watch(
  () => resolvedId.value,
  () => {
    bootstrap()
  },
  { immediate: true }
)

async function onSubmit() {
  saveError.value = ''
  if (!companyId.value) {
    saveError.value = 'Seleccione una empresa.'
    return
  }

  const service_ids = [...selectedIds.value]
  if (service_ids.length === 0) {
    saveError.value = 'No hay líneas seleccionables. Elija empresa y periodo; los cargos fijos se marcan solos si aplican.'
    return
  }

  saving.value = true
  try {
    const base = {
      period_year: Number(periodYear.value),
      period_month: Number(periodMonth.value),
      service_ids,
      ...backlogQueryParams(),
    }
    const payload = { ...base, company_id: Number(companyId.value) }
    let result
    if (isEdit.value) {
      result = await updateInvoice(resolvedId.value, payload)
    } else {
      result = await createInvoice(payload)
    }
    if (props.embedded) {
      emit('saved', result)
    } else {
      await router.push('/admin/facturas')
    }
  } catch (e) {
    if (e.data?.errors) {
      const first = Object.values(e.data.errors).flat()[0]
      saveError.value = first || e.message
    } else {
      saveError.value = e.data?.message || e.message || 'No se pudo guardar.'
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section :class="embedded ? 'form-root form-root--embedded' : 'form-root'">
    <header v-if="!embedded" class="head">
      <div>
        <RouterLink class="back" to="/admin/facturas">← Volver al listado</RouterLink>
        <h1>
          {{ isEdit ? (invoice?.code ? `Editar factura (${invoice.code})` : 'Editar factura') : 'Nueva factura' }}
        </h1>
        <p class="lede">
          <strong>Nueva factura</strong>: solo empresas dadas de alta en el directorio. Se crea en <strong>borrador</strong>; código
          <strong>FAC-YYMMDD-SIGLA</strong>. Tras aprobarla, sigue el flujo de envío y cobro.
        </p>
      </div>
    </header>

    <p v-else class="lede lede--embedded">Empresa registrada (borrador).</p>

    <p v-if="loadError && !loading" class="banner err">{{ loadError }}</p>
    <p v-if="saveError" class="banner err">{{ saveError }}</p>

    <p v-if="loading" class="muted">Cargando…</p>

    <form v-else class="card form" @submit.prevent="onSubmit">
      <div class="grid">
        <label class="field field--wide">
          <span>Empresa <abbr title="obligatorio">*</abbr></span>
          <select v-model="companyId" class="input" required :disabled="saving">
            <option value="" disabled>Seleccione…</option>
            <option v-for="c in companiesInPickerTab" :key="c.id" :value="String(c.id)">
              {{ companyOptionLabel(c) }}
            </option>
          </select>
        </label>
        <label class="field">
          <span>Año del periodo <abbr title="obligatorio">*</abbr></span>
          <input v-model.number="periodYear" type="number" min="2000" max="2100" class="input" required :disabled="saving" />
        </label>
        <label class="field">
          <span>Mes del periodo <abbr title="obligatorio">*</abbr></span>
          <select v-model.number="periodMonth" class="input" required :disabled="saving">
            <option v-for="[val, label] in MONTHS" :key="val" :value="val">{{ label }}</option>
          </select>
        </label>
        <label class="field field--wide">
          <span>Regularizar cargos fijos desde (opcional)</span>
          <div class="backlog-row">
            <input
              v-model="recurringBacklogStartYear"
              class="input backlog-year"
              type="number"
              min="2000"
              max="2100"
              placeholder="Año"
              :disabled="saving"
            />
            <select v-model="recurringBacklogStartMonth" class="input backlog-month" :disabled="saving">
              <option value="">Mes (no regularizar)</option>
              <option v-for="[val, label] in MONTHS" :key="'bl-' + val" :value="String(val)">{{ label }}</option>
            </select>
          </div>
          <span class="field-hint">
            Si el cliente dejó de facturar varios meses, indique el <strong>primer mes</strong> de cargos fijos a incluir; se
            listarán hasta el mes del periodo. Lo ya facturado no se duplica.
          </span>
        </label>
      </div>

      <div v-if="canSubmitInvoice" class="services-block">
        <div class="services-head">
          <h2>Servicios del periodo</h2>
          <p class="hint">
            Incluye servicios de campo y, si hay plantillas activas, los <strong>cargos fijos mensuales</strong> materializados
            para la ventana (se seleccionan solos y no se pueden quitar aquí; suspenda la plantilla en Empresas si no debe
            facturarse). Solo líneas aún no ligadas a otra factura.
          </p>
          <p v-if="loadingServices" class="muted">Cargando servicios…</p>
          <div
            v-if="available.length && !loadingServices"
            class="bulk-select-row"
            role="group"
            aria-label="Selección masiva de servicios"
          >
            <button type="button" class="bulk-link" @click="selectAllAvailableServices">Seleccionar todos</button>
            <span class="bulk-sep" aria-hidden="true">·</span>
            <button type="button" class="bulk-link" @click="clearServiceSelection">Quitar selección</button>
          </div>
        </div>

        <div v-if="!loadingServices && available.length === 0" class="muted box-empty">
          No hay servicios disponibles para facturar con estos criterios.
        </div>

        <div v-else class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th class="chk">
                  <input
                    ref="selectAllCheckboxRef"
                    type="checkbox"
                    :checked="allAvailableSelected"
                    :disabled="!sortedAvailable.length || loadingServices"
                    :aria-label="
                      allAvailableSelected
                        ? 'Quitar selección de todos los servicios visibles'
                        : 'Seleccionar todos los servicios visibles'
                    "
                    @change="toggleSelectAllCheckbox"
                  />
                </th>
                <th scope="col" :aria-sort="availAriaSort('code')">
                  <button type="button" class="th-sort" @click="toggleAvailSort('code')">
                    Código<span class="sort-ind" aria-hidden="true">{{ availSortInd('code') }}</span>
                  </button>
                </th>
                <th scope="col" :aria-sort="availAriaSort('service_date')">
                  <button type="button" class="th-sort" @click="toggleAvailSort('service_date')">
                    Fecha<span class="sort-ind" aria-hidden="true">{{ availSortInd('service_date') }}</span>
                  </button>
                </th>
                <th scope="col" :aria-sort="availAriaSort('description')">
                  <button type="button" class="th-sort" @click="toggleAvailSort('description')">
                    Descripción<span class="sort-ind" aria-hidden="true">{{ availSortInd('description') }}</span>
                  </button>
                </th>
                <th class="num" scope="col" :aria-sort="availAriaSort('amount')">
                  <button type="button" class="th-sort th-sort--end" @click="toggleAvailSort('amount')">
                    Valor<span class="sort-ind" aria-hidden="true">{{ availSortInd('amount') }}</span>
                  </button>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in sortedAvailable" :key="row.id">
                <td class="chk">
                  <input
                    type="checkbox"
                    :checked="isSelected(row.id)"
                    :disabled="row.is_recurring"
                    :title="row.is_recurring ? 'Cargo fijo: se incluye siempre en el borrador (suspenda la plantilla si no aplica).' : ''"
                    @change="toggleId(row)"
                  />
                </td>
                <td class="mono">
                  {{ row.code }}
                  <span v-if="row.is_recurring" class="tag-recurring">Fijo</span>
                </td>
                <td>{{ row.service_date }}</td>
                <td class="desc">{{ row.description }}</td>
                <td class="num">{{ money(row.amount) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="total-line">
          <span>Total seleccionado</span>
          <strong>{{ money(totalPreview) }}</strong>
        </div>
      </div>

      <div class="actions">
        <button v-if="embedded" type="button" class="btn secondary" @click="emit('cancel')">Cancelar</button>
        <RouterLink v-else class="btn secondary" to="/admin/facturas">Cancelar</RouterLink>
        <button
          type="submit"
          class="btn primary"
          :disabled="saving || !canSubmitInvoice"
        >
          {{
            saving ? 'Procesando…' : isEdit ? 'Guardar cambios' : 'Crear borrador'
          }}
        </button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.form-root {
  max-width: 920px;
  margin: 0 auto;
}

.form-root--embedded {
  max-width: none;
  margin: 0;
}

.head {
  margin-bottom: 1rem;
}

.back {
  display: inline-block;
  color: #94a3b8;
  text-decoration: none;
  font-size: 0.9rem;
  margin-bottom: 0.35rem;
}

h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  color: #f8fafc;
}

.lede {
  margin: 0;
  max-width: 42rem;
  font-size: 0.88rem;
  color: #94a3b8;
  line-height: 1.45;
}

.lede--embedded {
  margin-bottom: 1rem;
  max-width: none;
  font-size: 0.82rem;
}

.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}

.card {
  padding: 1.25rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.field--wide {
  grid-column: 1 / -1;
}

.backlog-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.backlog-year {
  max-width: 8rem;
}

.backlog-month {
  flex: 1;
  min-width: 12rem;
}

.field-hint {
  display: block;
  margin-top: 0.4rem;
  font-size: 0.75rem;
  color: #64748b;
  line-height: 1.35;
}

.tag-recurring {
  display: inline-block;
  margin-left: 0.35rem;
  padding: 0.1rem 0.35rem;
  border-radius: 4px;
  font-size: 0.68rem;
  font-weight: 600;
  vertical-align: middle;
  background: rgba(56, 189, 248, 0.15);
  color: #7dd3fc;
}

.field span {
  display: block;
  font-size: 0.82rem;
  color: #cbd5e1;
  margin-bottom: 0.35rem;
}

.input {
  width: 100%;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.35);
  color: #f8fafc;
  padding: 0.5rem 0.65rem;
  font: inherit;
}

.services-block {
  margin-top: 0.5rem;
}

.services-head h2 {
  margin: 0 0 0.35rem;
  font-size: 1rem;
  color: #e2e8f0;
}

.hint {
  margin: 0 0 0.75rem;
  font-size: 0.82rem;
  color: #64748b;
}

.bulk-select-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem 0.5rem;
  margin: 0 0 0.65rem;
  font-size: 0.8rem;
}

.bulk-link {
  margin: 0;
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  font-weight: 600;
  color: #38bdf8;
  cursor: pointer;
  text-decoration: underline;
  text-underline-offset: 2px;
}

.bulk-link:hover {
  color: #7dd3fc;
}

.bulk-link:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.bulk-sep {
  color: #64748b;
  user-select: none;
}

.box-empty {
  padding: 1rem;
  border-radius: 10px;
  border: 1px dashed rgba(148, 163, 184, 0.25);
}

.table-wrap {
  overflow-x: auto;
  margin-top: 0.5rem;
}

.table {
  width: 100%;
  min-width: 560px;
  border-collapse: collapse;
  font-size: 0.88rem;
}

.table th,
.table td {
  padding: 0.45rem 0.35rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
  text-align: left;
  vertical-align: top;
}

.table th {
  color: #94a3b8;
  font-size: 0.75rem;
  text-transform: uppercase;
}

.chk {
  width: 2rem;
}

.mono {
  font-family: ui-monospace, monospace;
  font-size: 0.8rem;
}

.desc {
  max-width: 280px;
  white-space: pre-wrap;
  word-break: break-word;
}

.num {
  text-align: right;
  white-space: nowrap;
}

.total-line {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 1rem;
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid rgba(148, 163, 184, 0.15);
  font-size: 1rem;
  color: #cbd5e1;
}

.total-line strong {
  color: #38bdf8;
  font-size: 1.15rem;
}

.actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
  margin-top: 1.25rem;
}

.muted {
  color: #94a3b8;
}

.btn {
  display: inline-flex;
  padding: 0.55rem 1rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: transparent;
  color: #e2e8f0;
  text-decoration: none;
  align-items: center;
}

.btn.primary {
  background: linear-gradient(90deg, #2563eb, #7c3aed);
  border: none;
  color: #fff;
}

.btn.primary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.btn.secondary:hover {
  border-color: #38bdf8;
  color: #fff;
}
</style>
