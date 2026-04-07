<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useClientSortedRows } from '@/composables/useClientSortedRows.js'
import { fetchCompanies } from '@/services/servicesApi.js'
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

const available = ref([])
const selectedIds = ref([])

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

function toggleId(id) {
  const i = selectedIds.value.indexOf(id)
  if (i >= 0) {
    selectedIds.value = selectedIds.value.filter((x) => x !== id)
  } else {
    selectedIds.value = [...selectedIds.value, id]
  }
}

const totalPreview = computed(() => {
  let t = 0
  for (const row of sortedAvailable.value) {
    if (isSelected(row.id)) t += Number(row.amount) || 0
  }
  return t
})

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

async function loadAvailable() {
  if (!companyId.value) {
    available.value = []
    return
  }
  loadError.value = ''
  loadingServices.value = true
  try {
    const data = await fetchAvailableServicesForInvoice({
      company_id: companyId.value,
      period_year: Number(periodYear.value),
      period_month: Number(periodMonth.value),
      invoice_id: isEdit.value ? resolvedId.value : undefined,
    })
    available.value = data
    const valid = new Set(data.map((r) => r.id))
    selectedIds.value = selectedIds.value.filter((id) => valid.has(id))
  } catch (e) {
    loadError.value = e.data?.message || e.message || 'No se pudieron cargar los servicios.'
    available.value = []
  } finally {
    loadingServices.value = false
  }
}

watch([companyId, periodYear, periodMonth], () => {
  if (skipWatch.value) return
  loadAvailable()
})

async function bootstrap() {
  loadError.value = ''
  loading.value = true
  skipWatch.value = true
  try {
    companies.value = await fetchCompanies()
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
      companyId.value = String(invoice.value.company_id)
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
    saveError.value = 'Seleccione al menos un servicio del periodo.'
    return
  }

  saving.value = true
  try {
    const payload = {
      company_id: Number(companyId.value),
      period_year: Number(periodYear.value),
      period_month: Number(periodMonth.value),
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
          Elija empresa y periodo (mes de prestación), luego marque los servicios a incluir. El total se calcula de los
          servicios seleccionados. Al crear el borrador se asigna
          <strong>FAC-YYMMDD-SIGLA</strong> (fecha de creación; máximo 1 factura por empresa y día).
        </p>
      </div>
    </header>

    <p v-else class="lede lede--embedded">
      Elija empresa y periodo, marque los servicios a incluir y cree el borrador
      <strong>FAC-YYMMDD-SIGLA</strong>.
    </p>

    <p v-if="loadError && !loading" class="banner err">{{ loadError }}</p>
    <p v-if="saveError" class="banner err">{{ saveError }}</p>

    <p v-if="loading" class="muted">Cargando…</p>

    <form v-else class="card form" @submit.prevent="onSubmit">
      <div class="grid">
        <label class="field">
          <span>Empresa <abbr title="obligatorio">*</abbr></span>
          <select v-model="companyId" class="input" required :disabled="saving">
            <option value="" disabled>Seleccione…</option>
            <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
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
      </div>

      <div v-if="companyId" class="services-block">
        <div class="services-head">
          <h2>Servicios del periodo</h2>
          <p class="hint">
            Solo aparecen servicios visibles de la empresa en ese mes que no estén en otra factura. Al editar un borrador,
            se incluyen los ya vinculados a esta factura.
          </p>
          <p v-if="loadingServices" class="muted">Cargando servicios…</p>
        </div>

        <div v-if="!loadingServices && available.length === 0" class="muted box-empty">
          No hay servicios disponibles para facturar con estos criterios.
        </div>

        <div v-else class="table-wrap">
          <table class="table">
            <thead>
              <tr>
                <th class="chk" />
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
                  <input type="checkbox" :checked="isSelected(row.id)" @change="toggleId(row.id)" />
                </td>
                <td class="mono">{{ row.code }}</td>
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
        <button type="submit" class="btn primary" :disabled="saving || !companyId">
          {{ saving ? 'Guardando…' : isEdit ? 'Guardar cambios' : 'Crear borrador' }}
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
