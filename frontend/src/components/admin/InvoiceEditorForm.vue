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
/** Incluir cargos fijos mensuales en el listado y marcarlos al cargar (predeterminado: sí). */
const includeRecurringFixed = ref(true)

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

const companiesInPickerTab = computed(() => companies.value || [])

function companyOptionLabel(c) {
  if (!c) return ''
  return c.nombre
}

const skipWatch = ref(true)

function isSelected(id) {
  return selectedIds.value.includes(id)
}

function toggleId(row) {
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
  selectedIds.value = []
}

function toggleSelectAllCheckbox() {
  if (allAvailableSelected.value) {
    clearServiceSelection()
  } else {
    selectAllAvailableServices()
  }
}

function syncSelectionAfterLoad(data) {
  const valid = new Set(data.map((r) => r.id))
  let kept = selectedIds.value.filter((id) => valid.has(id))
  if (!includeRecurringFixed.value) {
    selectedIds.value = kept.filter((id) => {
      const row = data.find((r) => r.id === id)
      return row && !row.is_recurring
    })
    return
  }
  if (!isEdit.value) {
    const recurringIds = data.filter((r) => r.is_recurring).map((r) => r.id)
    kept = [...new Set([...kept, ...recurringIds])]
  }
  selectedIds.value = kept
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
      invoice_id: isEdit.value ? resolvedId.value : undefined,
      include_recurring: includeRecurringFixed.value,
    })
    available.value = data
    syncSelectionAfterLoad(data)
  } catch (e) {
    loadError.value = e.data?.message || e.message || 'No se pudieron cargar los servicios.'
    available.value = []
  } finally {
    loadingServices.value = false
  }
}

watch(companyId, () => {
  if (skipWatch.value) return
  loadAvailable()
})

watch(includeRecurringFixed, () => {
  if (skipWatch.value || !companyId.value) return
  loadAvailable()
})

async function bootstrap() {
  loadError.value = ''
  loading.value = true
  skipWatch.value = true
  try {
    companies.value = await fetchAdminCompanies()
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
      selectedIds.value = (invoice.value.services || []).map((s) => s.id)
      await loadAvailable()
      includeRecurringFixed.value = available.value.some(
        (r) => r.is_recurring && selectedIds.value.includes(r.id)
      )
    } catch (e) {
      loadError.value = e.data?.message || e.message || 'No se pudo cargar la factura.'
    }
  } else {
    companyId.value = ''
    includeRecurringFixed.value = true
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
    saveError.value = 'Seleccione al menos un servicio para la factura.'
    return
  }

  saving.value = true
  try {
    const payload = {
      company_id: Number(companyId.value),
      service_ids,
    }
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
      <label class="field field--wide">
        <span>Empresa <abbr title="obligatorio">*</abbr></span>
        <select v-model="companyId" class="input" required :disabled="saving">
          <option value="" disabled>Seleccione…</option>
          <option v-for="c in companiesInPickerTab" :key="c.id" :value="String(c.id)">
            {{ companyOptionLabel(c) }}
          </option>
        </select>
      </label>

      <div v-if="canSubmitInvoice" class="services-block">
        <div class="services-head">
          <h2>Servicios de la empresa</h2>
          <label class="recurring-check">
            <input v-model="includeRecurringFixed" type="checkbox" :disabled="saving || loadingServices" />
            <span>Incluir cargos fijos mensuales</span>
          </label>
          <p class="hint">
            Se listan todos los servicios disponibles de la empresa (sin filtrar por mes). Solo líneas aún no ligadas a otra
            factura.
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
                  <input type="checkbox" :checked="isSelected(row.id)" @change="toggleId(row)" />
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
        <button type="submit" class="btn primary" :disabled="saving || !canSubmitInvoice">
          {{ saving ? 'Procesando…' : isEdit ? 'Guardar cambios' : 'Crear borrador' }}
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

.field--wide {
  display: block;
  margin-bottom: 1.25rem;
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
  margin: 0 0 0.5rem;
  font-size: 1rem;
  color: #e2e8f0;
}

.recurring-check {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin: 0 0 0.5rem;
  font-size: 0.88rem;
  color: #e2e8f0;
  cursor: pointer;
}

.recurring-check input {
  width: 1rem;
  height: 1rem;
  accent-color: #38bdf8;
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
