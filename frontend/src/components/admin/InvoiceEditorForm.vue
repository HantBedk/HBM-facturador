<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useClientSortedRows } from '@/composables/useClientSortedRows.js'
import { fetchAdminCompanies } from '@/services/companiesApi.js'
import {
  createInvoice,
  createWalkInInvoiceFinal,
  fetchAdminInvoice,
  fetchAvailableServicesForInvoice,
  fetchAvailableWalkInServicesForInvoice,
  fetchPendingWalkInGroups,
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
/** `registered` = empresa en directorio; `counter` = venta sin fila en empresas (solo teléfono en servicios). */
const companyPickerTab = ref('registered')
const loading = ref(true)
const loadingServices = ref(false)
const saving = ref(false)
const loadError = ref('')
const saveError = ref('')
/** Tras emitir venta sin alta (código de verificación puede ir aquí). */
const finalizeNotice = ref('')

const companyId = ref('')
/** Teléfono tal como lo escribe el usuario (se normaliza a dígitos para la API). */
const walkInPhoneInput = ref('')
const periodYear = ref(new Date().getFullYear())
const periodMonth = ref(new Date().getMonth() + 1)

const available = ref([])
const selectedIds = ref([])

/** Grupos con servicios sin empresa aún no facturados (misma API que el listado de facturas). */
const pendingWalkInGroups = ref([])
const pendingWalkInPick = ref('')

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

function digitsOnly(s) {
  return String(s || '').replace(/\D/g, '')
}

const walkInPhoneDigits = computed(() => digitsOnly(walkInPhoneInput.value))

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

function toggleId(id) {
  const i = selectedIds.value.indexOf(id)
  if (i >= 0) {
    selectedIds.value = selectedIds.value.filter((x) => x !== id)
  } else {
    selectedIds.value = [...selectedIds.value, id]
  }
}

const isCounterDirectNew = computed(() => !isEdit.value && companyPickerTab.value === 'counter')

const totalPreview = computed(() => {
  const rows = isCounterDirectNew.value
    ? sortedAvailable.value
    : sortedAvailable.value.filter((row) => isSelected(row.id))
  let t = 0
  for (const row of rows) {
    t += Number(row.amount) || 0
  }
  return t
})

const canSubmitInvoice = computed(() => {
  if (companyPickerTab.value === 'registered') return !!companyId.value
  return walkInPhoneDigits.value.length >= 7
})

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

function walkInPendingKey(g) {
  return `${g.contact_phone_key}|${g.period_year}|${g.period_month}`
}

function walkInPendingOptionLabel(g) {
  const m = MONTHS.find(([n]) => n === g.period_month)
  const mes = m ? m[1] : String(g.period_month)
  return `${g.client_name} · ${g.client_telefono_display} · ${mes} ${g.period_year} · ${g.services_count} serv. · ${money(g.total)}`
}

async function loadAvailable() {
  loadError.value = ''
  if (companyPickerTab.value === 'registered' && !companyId.value) {
    available.value = []
    return
  }
  if (companyPickerTab.value === 'counter' && walkInPhoneDigits.value.length < 7) {
    available.value = []
    return
  }
  loadingServices.value = true
  try {
    let data = []
    if (companyPickerTab.value === 'registered') {
      data = await fetchAvailableServicesForInvoice({
        company_id: companyId.value,
        period_year: Number(periodYear.value),
        period_month: Number(periodMonth.value),
        invoice_id: isEdit.value ? resolvedId.value : undefined,
      })
    } else {
      data = await fetchAvailableWalkInServicesForInvoice({
        contact_phone_key: walkInPhoneDigits.value,
        period_year: Number(periodYear.value),
        period_month: Number(periodMonth.value),
        invoice_id: isEdit.value ? resolvedId.value : undefined,
      })
    }
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

watch([companyId, periodYear, periodMonth, walkInPhoneInput, companyPickerTab], () => {
  if (skipWatch.value) return
  loadAvailable()
})

watch(companyPickerTab, () => {
  if (skipWatch.value) return
  companyId.value = ''
  walkInPhoneInput.value = ''
  available.value = []
  selectedIds.value = []
  pendingWalkInPick.value = ''
})

watch(pendingWalkInPick, (val) => {
  if (skipWatch.value || !val) return
  const g = pendingWalkInGroups.value.find((x) => walkInPendingKey(x) === val)
  if (!g) return
  skipWatch.value = true
  companyPickerTab.value = 'counter'
  walkInPhoneInput.value = g.client_telefono_display || g.contact_phone_key
  periodYear.value = g.period_year
  periodMonth.value = g.period_month
  skipWatch.value = false
  loadAvailable()
})

async function bootstrap() {
  loadError.value = ''
  finalizeNotice.value = ''
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
        companyPickerTab.value = 'registered'
      } else {
        companyPickerTab.value = 'counter'
        const s0 = (invoice.value.services || [])[0]
        walkInPhoneInput.value =
          s0?.contact_phone_key || s0?.client_telefono || invoice.value.bill_to?.telefono || ''
      }
      periodYear.value = invoice.value.period_year
      periodMonth.value = invoice.value.period_month
      selectedIds.value = (invoice.value.services || []).map((s) => s.id)
      await loadAvailable()
    } catch (e) {
      loadError.value = e.data?.message || e.message || 'No se pudo cargar la factura.'
    }
  } else {
    companyPickerTab.value = 'registered'
    companyId.value = ''
    walkInPhoneInput.value = ''
    pendingWalkInPick.value = ''
    periodYear.value = new Date().getFullYear()
    periodMonth.value = new Date().getMonth() + 1
    invoice.value = null
    available.value = []
    selectedIds.value = []
    try {
      pendingWalkInGroups.value = await fetchPendingWalkInGroups()
    } catch {
      pendingWalkInGroups.value = []
    }
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

function goToInvoiceList() {
  finalizeNotice.value = ''
  router.push('/admin/facturas')
}

async function onSubmit() {
  saveError.value = ''
  if (companyPickerTab.value === 'registered' && !companyId.value) {
    saveError.value = 'Seleccione una empresa.'
    return
  }
  if (companyPickerTab.value === 'counter' && walkInPhoneDigits.value.length < 7) {
    saveError.value = 'Indique un teléfono con al menos 7 dígitos (venta sin alta).'
    return
  }

  if (isCounterDirectNew.value) {
    if (available.value.length === 0) {
      saveError.value = 'No hay servicios pendientes de facturar para este teléfono y periodo.'
      return
    }
    saving.value = true
    try {
      const result = await createWalkInInvoiceFinal({
        contact_phone_key: walkInPhoneDigits.value,
        period_year: Number(periodYear.value),
        period_month: Number(periodMonth.value),
      })
      const code = result.public_verification_code
      const notice = code
        ? `Factura ${result.code} emitida y aprobada. Código de verificación (guárdelo; no se repetirá): ${code}`
        : `Factura ${result.code} emitida y aprobada.`
      finalizeNotice.value = notice
      try {
        pendingWalkInGroups.value = await fetchPendingWalkInGroups()
      } catch {
        /* ignore */
      }
      pendingWalkInPick.value = ''
      if (props.embedded) {
        emit('saved', result)
      }
    } catch (e) {
      if (e.data?.errors) {
        const first = Object.values(e.data.errors).flat()[0]
        saveError.value = first || e.message
      } else {
        saveError.value = e.data?.message || e.message || 'No se pudo emitir la factura.'
      }
    } finally {
      saving.value = false
    }
    return
  }

  const service_ids = [...selectedIds.value]
  if (service_ids.length === 0) {
    saveError.value = 'Seleccione al menos un servicio del periodo.'
    return
  }

  saving.value = true
  try {
    const base = {
      period_year: Number(periodYear.value),
      period_month: Number(periodMonth.value),
      service_ids,
    }
    const payload =
      companyPickerTab.value === 'registered'
        ? { ...base, company_id: Number(companyId.value) }
        : { ...base, contact_phone_key: walkInPhoneDigits.value }
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
          <strong>Empresa registrada</strong>: borrador y luego aprobación; código <strong>FAC-YYMMDD-SIGLA</strong>.
          <strong>Venta sin alta</strong>: se facturan todos los servicios del mismo teléfono en el periodo,
          <strong>sin borrador</strong> — factura aprobada al instante (<strong>FAC-YYMMDD-W01…</strong>).
        </p>
      </div>
    </header>

    <p v-else class="lede lede--embedded">
      Empresa (borrador) o venta sin alta (emisión directa aprobada).
    </p>

    <p v-if="finalizeNotice" class="banner ok">
      {{ finalizeNotice }}
      <button
        v-if="!embedded"
        type="button"
        class="btn-inline"
        @click="goToInvoiceList"
      >
        Ir al listado
      </button>
    </p>
    <p v-if="loadError && !loading" class="banner err">{{ loadError }}</p>
    <p v-if="saveError" class="banner err">{{ saveError }}</p>

    <p v-if="loading" class="muted">Cargando…</p>

    <form v-else class="card form" @submit.prevent="onSubmit">
      <div v-if="!isEdit && pendingWalkInGroups.length" class="field field--wide pending-walk-in-pick">
        <label>
          <span>Elegir pendiente sin facturar</span>
          <select v-model="pendingWalkInPick" class="input" :disabled="saving || !!finalizeNotice">
            <option value="">— Manual: pestaña «Venta sin alta» o escriba teléfono —</option>
            <option v-for="g in pendingWalkInGroups" :key="walkInPendingKey(g)" :value="walkInPendingKey(g)">
              {{ walkInPendingOptionLabel(g) }}
            </option>
          </select>
        </label>
        <p class="tab-hint">Rellena teléfono y periodo según servicios ya registrados; no hace falta recordar el número.</p>
      </div>

      <div class="picker-tabs" role="tablist" aria-label="Tipo de facturación">
        <button
          type="button"
          role="tab"
          class="picker-tab"
          :aria-selected="companyPickerTab === 'registered'"
          :class="{ 'picker-tab--on': companyPickerTab === 'registered' }"
          :disabled="saving"
          @click="companyPickerTab = 'registered'"
        >
          Empresas registradas
        </button>
        <button
          type="button"
          role="tab"
          class="picker-tab"
          :aria-selected="companyPickerTab === 'counter'"
          :class="{ 'picker-tab--on': companyPickerTab === 'counter' }"
          :disabled="saving"
          @click="companyPickerTab = 'counter'"
        >
          Venta sin alta
        </button>
      </div>
      <p v-if="companyPickerTab === 'counter'" class="tab-hint">
        Mismo teléfono que al registrar cada servicio sin empresa. Se incluyen <strong>todos</strong> los servicios del periodo
        aún sin facturar (varios técnicos si aplica). No se crea fila en el directorio de empresas.
      </p>

      <div class="grid">
        <label v-if="companyPickerTab === 'registered'" class="field field--wide">
          <span>Empresa <abbr title="obligatorio">*</abbr></span>
          <select v-model="companyId" class="input" required :disabled="saving">
            <option value="" disabled>Seleccione…</option>
            <option v-for="c in companiesInPickerTab" :key="c.id" :value="String(c.id)">
              {{ companyOptionLabel(c) }}
            </option>
          </select>
        </label>
        <label v-else class="field field--wide">
          <span>Teléfono del cliente <abbr title="obligatorio">*</abbr></span>
          <input
            v-model="walkInPhoneInput"
            type="tel"
            class="input"
            autocomplete="tel"
            placeholder="Ej. 300 123 4567"
            :disabled="saving"
          />
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

      <div v-if="canSubmitInvoice" class="services-block">
        <div class="services-head">
          <h2>Servicios del periodo</h2>
          <p class="hint">
            <template v-if="isCounterDirectNew">
              Vista previa: estos servicios se incluirán enteros en la factura aprobada.
            </template>
            <template v-else>
              Solo servicios visibles del periodo que aún no están en otra factura. Al editar un borrador, puede marcar líneas.
            </template>
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
                <th v-if="!isCounterDirectNew" class="chk" />
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
                <td v-if="!isCounterDirectNew" class="chk">
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
        <button
          type="submit"
          class="btn primary"
          :disabled="saving || !canSubmitInvoice || !!finalizeNotice"
        >
          {{
            saving
              ? 'Procesando…'
              : isCounterDirectNew
                ? 'Emitir factura (aprobada)'
                : isEdit
                  ? 'Guardar cambios'
                  : 'Crear borrador'
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

.banner.ok {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(34, 197, 94, 0.12);
  border: 1px solid rgba(34, 197, 94, 0.4);
  color: #bbf7d0;
  margin-bottom: 1rem;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.btn-inline {
  margin-left: auto;
  padding: 0.35rem 0.75rem;
  border-radius: 8px;
  border: 1px solid rgba(34, 197, 94, 0.5);
  background: rgba(34, 197, 94, 0.15);
  color: #ecfdf5;
  font-size: 0.85rem;
  cursor: pointer;
}

.btn-inline:hover {
  background: rgba(34, 197, 94, 0.25);
}

.card {
  padding: 1.25rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
}

.picker-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.picker-tab {
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.45);
  color: #cbd5e1;
  padding: 0.45rem 0.85rem;
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
}

.picker-tab:hover:not(:disabled) {
  border-color: rgba(56, 189, 248, 0.45);
  color: #e2e8f0;
}

.picker-tab--on {
  border-color: rgba(56, 189, 248, 0.55);
  background: rgba(56, 189, 248, 0.12);
  color: #e0f2fe;
}

.picker-tab:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.tab-hint {
  margin: 0 0 0.75rem;
  font-size: 0.8rem;
  color: #94a3b8;
  line-height: 1.4;
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
