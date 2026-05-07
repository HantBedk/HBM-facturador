<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  fetchBillingAutomationSettings,
  updateBillingAutomationSettings,
} from '@/services/adminBillingAutomationApi.js'
import {
  fetchTechnicianCatalogDiscount,
  updateTechnicianCatalogDiscount,
} from '@/services/servicesApi.js'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const toast = ref('')
const help = ref('')

const draftGenerationEnabled = ref(false)
const draftGenerationDay = ref(18)
const draftGenerationPeriod = ref('current')
/** @type {import('vue').Ref<Record<string, boolean>>} */
const storedInDatabase = ref({})
const MARGIN_PERCENT_MAX = 95

const technicianServiceDiscountPercent = ref(10)
const technicianInventorySaleDiscountPercent = ref(10)
const technicianInventoryRentalDiscountPercent = ref(10)

const marginFloorService = ref(5)
const marginFloorSale = ref(10)
const marginFloorRental = ref(8)

const techDiscountSaving = ref(false)
const techDiscountError = ref('')
const techDiscountOk = ref('')

const passwordModalOpen = ref(false)
const modalPassword = ref('')
const modalPasswordError = ref('')
const modalPwInputRef = ref(null)

const dayOptions = computed(() =>
  Array.from({ length: 28 }, (_, i) => ({
    value: i + 1,
    label: String(i + 1),
  })),
)

async function load() {
  error.value = ''
  toast.value = ''
  loading.value = true
  try {
    const r = await fetchBillingAutomationSettings()
    help.value = r.help || ''
    const d = r.data || {}
    draftGenerationEnabled.value = !!d.draft_generation_enabled
    draftGenerationDay.value = Math.min(28, Math.max(1, Number(d.draft_generation_day) || 18))
    draftGenerationPeriod.value = d.draft_generation_period === 'previous' ? 'previous' : 'current'
    storedInDatabase.value = d.stored_in_database || {}
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar la configuración.'
  } finally {
    loading.value = false
  }
}

function clampPct(n, min, max = MARGIN_PERCENT_MAX) {
  const x = Number(n)
  if (!Number.isFinite(x)) return min
  return Math.min(max, Math.max(min, Math.round(x * 100) / 100))
}

async function loadTechnicianDiscount() {
  techDiscountError.value = ''
  try {
    const d = await fetchTechnicianCatalogDiscount()
    const fSvc = Number(d?.technician_service_margin_floor_percent ?? 5)
    const fSale = Number(d?.technician_inventory_sale_margin_floor_percent ?? 10)
    const fRent = Number(d?.technician_inventory_rental_margin_floor_percent ?? 8)
    marginFloorService.value = clampPct(fSvc, 0, MARGIN_PERCENT_MAX)
    marginFloorSale.value = clampPct(fSale, 0, MARGIN_PERCENT_MAX)
    marginFloorRental.value = clampPct(fRent, 0, MARGIN_PERCENT_MAX)

    const svc = Number(d?.technician_service_discount_percent ?? d?.technician_catalog_discount_percent ?? 10)
    const sale = Number(d?.technician_inventory_sale_discount_percent ?? 10)
    const rental = Number(d?.technician_inventory_rental_discount_percent ?? 10)
    technicianServiceDiscountPercent.value = clampPct(svc, marginFloorService.value)
    technicianInventorySaleDiscountPercent.value = clampPct(sale, marginFloorSale.value)
    technicianInventoryRentalDiscountPercent.value = clampPct(rental, marginFloorRental.value)
  } catch (e) {
    techDiscountError.value = e.data?.message || e.message || 'No se pudo cargar la configuración de márgenes.'
  }
}

function clampMarginFieldsToFloors() {
  technicianServiceDiscountPercent.value = clampPct(
    technicianServiceDiscountPercent.value,
    marginFloorService.value,
  )
  technicianInventorySaleDiscountPercent.value = clampPct(
    technicianInventorySaleDiscountPercent.value,
    marginFloorSale.value,
  )
  technicianInventoryRentalDiscountPercent.value = clampPct(
    technicianInventoryRentalDiscountPercent.value,
    marginFloorRental.value,
  )
}

async function saveTechnicianDiscount() {
  techDiscountOk.value = ''
  techDiscountError.value = ''
  clampMarginFieldsToFloors()
  techDiscountSaving.value = true
  try {
    await updateTechnicianCatalogDiscount({
      technician_service_discount_percent: clampPct(
        technicianServiceDiscountPercent.value,
        marginFloorService.value,
      ),
      technician_inventory_sale_discount_percent: clampPct(
        technicianInventorySaleDiscountPercent.value,
        marginFloorSale.value,
      ),
      technician_inventory_rental_discount_percent: clampPct(
        technicianInventoryRentalDiscountPercent.value,
        marginFloorRental.value,
      ),
      technician_service_margin_floor_percent: clampPct(marginFloorService.value, 0, MARGIN_PERCENT_MAX),
      technician_inventory_sale_margin_floor_percent: clampPct(marginFloorSale.value, 0, MARGIN_PERCENT_MAX),
      technician_inventory_rental_margin_floor_percent: clampPct(marginFloorRental.value, 0, MARGIN_PERCENT_MAX),
    })
    await loadTechnicianDiscount()
    techDiscountOk.value = 'Pisos y márgenes guardados. Quedan aplicados al facturar.'
  } catch (e) {
    techDiscountError.value = e.data?.message || e.message || 'No se pudo guardar.'
    if (e.data?.errors) {
      const first = Object.values(e.data.errors).flat()[0]
      if (first) techDiscountError.value = first
    }
  } finally {
    techDiscountSaving.value = false
  }
}

watch(marginFloorService, (f) => {
  const fl = clampPct(f, 0, MARGIN_PERCENT_MAX)
  if (Number(technicianServiceDiscountPercent.value) < fl) technicianServiceDiscountPercent.value = fl
})
watch(marginFloorSale, (f) => {
  const fl = clampPct(f, 0, MARGIN_PERCENT_MAX)
  if (Number(technicianInventorySaleDiscountPercent.value) < fl) technicianInventorySaleDiscountPercent.value = fl
})
watch(marginFloorRental, (f) => {
  const fl = clampPct(f, 0, MARGIN_PERCENT_MAX)
  if (Number(technicianInventoryRentalDiscountPercent.value) < fl) technicianInventoryRentalDiscountPercent.value = fl
})

function onFloorBlur() {
  marginFloorService.value = clampPct(marginFloorService.value, 0, MARGIN_PERCENT_MAX)
  marginFloorSale.value = clampPct(marginFloorSale.value, 0, MARGIN_PERCENT_MAX)
  marginFloorRental.value = clampPct(marginFloorRental.value, 0, MARGIN_PERCENT_MAX)
  clampMarginFieldsToFloors()
}

function onMarginBlur() {
  clampMarginFieldsToFloors()
}

function openSavePasswordModal() {
  error.value = ''
  modalPassword.value = ''
  modalPasswordError.value = ''
  passwordModalOpen.value = true
  nextTick(() => {
    modalPwInputRef.value?.focus?.()
  })
}

function closeSavePasswordModal() {
  if (saving.value) return
  passwordModalOpen.value = false
  modalPassword.value = ''
  modalPasswordError.value = ''
}

async function confirmSaveWithPassword() {
  error.value = ''
  toast.value = ''
  modalPasswordError.value = ''
  const pw = String(modalPassword.value).trim()
  if (!pw) {
    modalPasswordError.value = 'Indique su contraseña para confirmar.'
    return
  }
  saving.value = true
  try {
    const r = await updateBillingAutomationSettings({
      current_password: pw,
      draft_generation_enabled: draftGenerationEnabled.value,
      draft_generation_day: draftGenerationDay.value,
      draft_generation_period: draftGenerationPeriod.value,
    })
    modalPassword.value = ''
    passwordModalOpen.value = false
    toast.value = r.message || 'Guardado.'
    const d = r.data || {}
    draftGenerationEnabled.value = !!d.draft_generation_enabled
    draftGenerationDay.value = Math.min(28, Math.max(1, Number(d.draft_generation_day) || 18))
    draftGenerationPeriod.value = d.draft_generation_period === 'previous' ? 'previous' : 'current'
    storedInDatabase.value = d.stored_in_database || {}
  } catch (e) {
    const pwe = e.data?.errors?.current_password
    if (Array.isArray(pwe) && pwe[0]) {
      modalPasswordError.value = pwe[0]
    }
    error.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}

function usingDb(key) {
  return !!storedInDatabase.value[key]
}

function onDocumentEscape(ev) {
  if (ev.key !== 'Escape' || !passwordModalOpen.value || saving.value) return
  ev.preventDefault()
  closeSavePasswordModal()
}

watch(passwordModalOpen, (open) => {
  if (open) document.addEventListener('keydown', onDocumentEscape)
  else document.removeEventListener('keydown', onDocumentEscape)
})

onMounted(async () => {
  await Promise.all([load(), loadTechnicianDiscount()])
})
onUnmounted(() => document.removeEventListener('keydown', onDocumentEscape))
</script>

<template>
  <section class="billing-page">
    <header class="head">
      <div>
        <p class="crumb">
          <RouterLink :to="{ name: 'admin-facturas' }">Facturas</RouterLink>
        </p>
        <h1>Facturación y Margen</h1>
        <p class="lede">
          Configure la <strong>programación de borradores</strong> (agrupa servicios sin facturar) y los
          <strong>márgenes globales</strong> referencia → factura. El envío automático de facturas ya aprobadas sigue en el
          servidor (<code class="inline">AUTOMATION_CUTOFF_*</code>). Al pulsar <strong>Guardar en el sistema</strong> (solo
          borradores) se pedirá su contraseña; el cambio queda en <strong>Configuración → Historial</strong>.
        </p>
      </div>
    </header>

    <p v-if="help" class="hint">{{ help }}</p>
    <p v-if="error" class="banner err">{{ error }}</p>
    <p v-if="toast" class="banner ok">{{ toast }}</p>

    <div v-if="loading" class="muted pad">Cargando…</div>

    <div v-else class="billing-stack">
      <section class="card margin-board" aria-labelledby="margins-heading">
        <header class="margin-board-head">
          <h2 id="margins-heading" class="margin-title">Márgenes globales</h2>
          <p class="margin-board-lede field-hint">
            El <strong>piso mínimo</strong> es el porcentaje que no se puede rebajar al guardar (cada tipo es
            independiente). El <strong>margen aplicado</strong> debe estar entre ese piso y
            {{ MARGIN_PERCENT_MAX }}&nbsp;%. Al facturar: facturable = referencia ÷ ((100 − <em>p</em>) / 100).
          </p>
        </header>

        <div class="margin-table-panel">
          <div class="margin-table-wrap">
            <div class="margin-table" role="table" aria-label="Márgenes por tipo; porcentaje máximo 95">
            <div class="margin-row margin-row-head" role="row">
              <div class="margin-cell margin-cell-type" role="columnheader">Tipo</div>
              <div class="margin-cell margin-cell-num" role="columnheader">Piso mínimo (%)</div>
              <div class="margin-cell margin-cell-num" role="columnheader">Margen aplicado (%)</div>
            </div>

            <div class="margin-row margin-data" role="row">
              <div class="margin-cell margin-cell-type" role="cell">
                <span class="mg-name">Servicio</span>
                <span class="mg-desc">Catálogo sin override, «Otro», trabajos estándar.</span>
              </div>
              <div class="margin-cell margin-cell-num" role="cell">
                <label class="mg-label" for="floor-svc">
                  <span class="mg-label-text">Piso</span>
                  <input
                    id="floor-svc"
                    v-model.number="marginFloorService"
                    type="number"
                    min="0"
                    :max="MARGIN_PERCENT_MAX"
                    step="0.5"
                    class="select mg-input"
                    @blur="onFloorBlur"
                  />
                </label>
              </div>
              <div class="margin-cell margin-cell-num" role="cell">
                <label class="mg-label" for="pct-svc">
                  <span class="mg-label-text">Margen</span>
                  <input
                    id="pct-svc"
                    v-model.number="technicianServiceDiscountPercent"
                    type="number"
                    :min="marginFloorService"
                    :max="MARGIN_PERCENT_MAX"
                    step="0.5"
                    class="select mg-input"
                    @blur="onMarginBlur"
                  />
                </label>
              </div>
            </div>

            <div class="margin-row margin-data" role="row">
              <div class="margin-cell margin-cell-type" role="cell">
                <span class="mg-name">Venta inventario</span>
                <span class="mg-desc">Registros y líneas de venta de equipo.</span>
              </div>
              <div class="margin-cell margin-cell-num" role="cell">
                <label class="mg-label" for="floor-sale">
                  <span class="mg-label-text">Piso</span>
                  <input
                    id="floor-sale"
                    v-model.number="marginFloorSale"
                    type="number"
                    min="0"
                    :max="MARGIN_PERCENT_MAX"
                    step="0.5"
                    class="select mg-input"
                    @blur="onFloorBlur"
                  />
                </label>
              </div>
              <div class="margin-cell margin-cell-num" role="cell">
                <label class="mg-label" for="pct-sale">
                  <span class="mg-label-text">Margen</span>
                  <input
                    id="pct-sale"
                    v-model.number="technicianInventorySaleDiscountPercent"
                    type="number"
                    :min="marginFloorSale"
                    :max="MARGIN_PERCENT_MAX"
                    step="0.5"
                    class="select mg-input"
                    @blur="onMarginBlur"
                  />
                </label>
              </div>
            </div>

            <div class="margin-row margin-data" role="row">
              <div class="margin-cell margin-cell-type" role="cell">
                <span class="mg-name">Alquiler inventario</span>
                <span class="mg-desc">Registros y líneas de alquiler de equipo.</span>
              </div>
              <div class="margin-cell margin-cell-num" role="cell">
                <label class="mg-label" for="floor-rent">
                  <span class="mg-label-text">Piso</span>
                  <input
                    id="floor-rent"
                    v-model.number="marginFloorRental"
                    type="number"
                    min="0"
                    :max="MARGIN_PERCENT_MAX"
                    step="0.5"
                    class="select mg-input"
                    @blur="onFloorBlur"
                  />
                </label>
              </div>
              <div class="margin-cell margin-cell-num" role="cell">
                <label class="mg-label" for="pct-rent">
                  <span class="mg-label-text">Margen</span>
                  <input
                    id="pct-rent"
                    v-model.number="technicianInventoryRentalDiscountPercent"
                    type="number"
                    :min="marginFloorRental"
                    :max="MARGIN_PERCENT_MAX"
                    step="0.5"
                    class="select mg-input"
                    @blur="onMarginBlur"
                  />
                </label>
              </div>
            </div>
            </div>
          </div>
        </div>

        <div class="margin-footer-actions">
          <button type="button" class="btn primary" :disabled="techDiscountSaving" @click="saveTechnicianDiscount">
            {{ techDiscountSaving ? 'Guardando…' : 'Guardar pisos y márgenes' }}
          </button>
        </div>
        <p v-if="techDiscountError" class="banner err banner-tight">{{ techDiscountError }}</p>
        <p v-else-if="techDiscountOk" class="banner ok banner-tight">{{ techDiscountOk }}</p>
      </section>

      <div class="card billing-primary billing-side-card">
        <h2 class="card-section-title">Programación de borradores</h2>
        <label class="toggle-row">
          <input v-model="draftGenerationEnabled" type="checkbox" class="chk" />
          <span class="toggle-txt">
            <strong>Generar borradores automáticamente</strong>
            <span class="sub">Si está desactivado, no se crearán borradores por el programador diario.</span>
          </span>
        </label>

        <div class="field">
          <label class="lab" for="draft-day">Día del mes para crear borradores</label>
          <div class="row-day">
            <select id="draft-day" v-model.number="draftGenerationDay" class="select">
              <option v-for="opt in dayOptions" :key="opt.value" :value="opt.value">
                Día {{ opt.label }}
              </option>
            </select>
            <span v-if="!usingDb('draft_generation_day')" class="badge badge-muted">valor por defecto (.env) hasta guardar</span>
            <span v-else class="badge badge-ok">guardado en panel</span>
          </div>
          <p class="field-hint">Ejemplo: día 18 → cada mes, en la fecha 18 (o el último día del mes si fuera menor), a las ~05:00.</p>
        </div>

        <div class="field field-last">
          <label class="lab" for="draft-period">¿Qué servicios entran en el borrador automático?</label>
          <select id="draft-period" v-model="draftGenerationPeriod" class="select wide">
            <option value="current">
              Recomendado: fecha de servicio en el mes calendario en curso (mismo mes que «hoy»)
            </option>
            <option value="previous">Fecha de servicio en el mes calendario anterior (cierre del mes pasado)</option>
          </select>
          <p class="field-hint">
            El sistema usa la <strong>fecha del servicio</strong> (no la fecha de hoy al crear el borrador) para decidir si
            entra en ese mes de facturación. Lo habitual es dejar
            <strong>mes en curso</strong> para facturar lo trabajado en el mes que corre.
          </p>
        </div>

        <div class="actions">
          <button type="button" class="btn primary" :disabled="saving" @click="openSavePasswordModal">
            Guardar en el sistema
          </button>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="passwordModalOpen"
        class="modal-backdrop"
        role="presentation"
        @click.self="closeSavePasswordModal"
      >
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="billing-save-pw-title">
          <h2 id="billing-save-pw-title" class="modal-title">Confirmar guardado</h2>
          <p class="modal-lede">
            Introduzca la contraseña de su usuario administrador para guardar la programación de borradores automáticos.
          </p>
          <label class="lab" for="billing-modal-pw">Contraseña</label>
          <input
            id="billing-modal-pw"
            ref="modalPwInputRef"
            v-model="modalPassword"
            type="password"
            class="input-pw"
            autocomplete="current-password"
            placeholder="Contraseña de su usuario"
            @keydown.enter.prevent="confirmSaveWithPassword"
          />
          <p v-if="modalPasswordError" class="pw-err">{{ modalPasswordError }}</p>
          <div class="modal-actions">
            <button type="button" class="btn secondary" :disabled="saving" @click="closeSavePasswordModal">
              Cancelar
            </button>
            <button type="button" class="btn primary" :disabled="saving" @click="confirmSaveWithPassword">
              {{ saving ? 'Guardando…' : 'Confirmar y guardar' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.billing-page {
  width: 100%;
  max-width: 96rem;
  margin: 0 auto;
  padding: 0 0 2rem;
}

.head {
  margin-bottom: 1rem;
}

.crumb {
  margin: 0 0 0.35rem;
  font-size: 0.8rem;
}

.crumb a {
  color: #7dd3fc;
  text-decoration: none;
}

.crumb a:hover {
  text-decoration: underline;
}

h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  color: #f8fafc;
}

.lede {
  margin: 0;
  width: 100%;
  max-width: none;
  font-size: 0.88rem;
  line-height: 1.55;
  color: #94a3b8;
}

.inline {
  font-size: 0.8rem;
  color: #cbd5e1;
  background: rgba(15, 23, 42, 0.9);
  padding: 0.1rem 0.35rem;
  border-radius: 4px;
}

.hint {
  font-size: 0.82rem;
  line-height: 1.55;
  color: #94a3b8;
  margin: 0 0 1rem;
  width: 100%;
  max-width: none;
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
  background: rgba(52, 211, 153, 0.12);
  border: 1px solid rgba(52, 211, 153, 0.35);
  color: #d1fae5;
  margin-bottom: 1rem;
}

.banner.warn {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(251, 191, 36, 0.1);
  border: 1px solid rgba(251, 191, 36, 0.4);
  color: #fde68a;
  margin-bottom: 0.85rem;
  font-size: 0.82rem;
  line-height: 1.45;
}

.banner-tight {
  margin-bottom: 0;
  margin-top: 0.75rem;
}

.card {
  padding: 1.35rem 1.4rem 1.4rem;
  border-radius: 16px;
  border: 1px solid rgba(148, 163, 184, 0.16);
  background: linear-gradient(160deg, rgba(24, 32, 48, 0.92) 0%, rgba(15, 23, 42, 0.72) 100%);
  box-shadow:
    0 1px 0 rgba(255, 255, 255, 0.04) inset,
    0 8px 32px rgba(0, 0, 0, 0.22);
}

.card.margin-board {
  padding: 1.1rem 1.15rem 1.2rem;
}

.billing-stack {
  display: grid;
  grid-template-columns: 1fr;
  gap: 1.35rem;
  align-items: start;
}

/*
  Dos columnas solo cuando cabe sidebar (260px) + márgenes (≥36rem) + borradores + paddings.
  Por debajo: una columna → la tabla de márgenes recupera todo el ancho y no se comprime.
*/
@media (min-width: 1280px) {
  .billing-stack {
    grid-template-columns: auto minmax(20rem, 32rem);
    /* row-gap si el grid pasa a dos filas; column-gap mínimo entre márgenes y borradores */
    gap: 1.35rem 0.3rem;
    align-items: start;
  }
}

.card-section-title {
  margin: 0 0 1rem;
  padding-bottom: 0.85rem;
  font-size: 1.05rem;
  font-weight: 600;
  color: #ccfbf1;
  letter-spacing: 0.02em;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
}

.field-last {
  margin-bottom: 0;
}

.billing-side-card {
  display: flex;
  flex-direction: column;
}

.billing-side-card .actions {
  margin-top: auto;
}

.toggle-row {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding-bottom: 1rem;
  margin-bottom: 1.1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
  cursor: pointer;
}

.chk {
  margin-top: 0.25rem;
  flex-shrink: 0;
}

.toggle-txt {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  color: #e2e8f0;
  font-size: 0.9rem;
}

.toggle-txt .sub {
  font-size: 0.82rem;
  color: #94a3b8;
  font-weight: normal;
}

.field {
  margin-bottom: 1.15rem;
}

.lab {
  display: block;
  font-size: 0.82rem;
  font-weight: 600;
  color: #cbd5e1;
  margin-bottom: 0.4rem;
}

.row-day {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.75rem;
}

.select {
  padding: 0.5rem 0.65rem;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(15, 23, 42, 0.9);
  color: #f1f5f9;
  font-size: 0.9rem;
  min-width: 7rem;
}

.select.wide {
  width: 100%;
  max-width: 22rem;
}

.billing-primary .select.wide {
  max-width: none;
}

.field-hint {
  margin: 0.45rem 0 0;
  font-size: 0.78rem;
  color: #64748b;
  max-width: none;
  line-height: 1.5;
}

.billing-primary .field-hint {
  max-width: 100%;
}

.input-pw {
  width: 100%;
  max-width: 22rem;
  padding: 0.5rem 0.65rem;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(15, 23, 42, 0.9);
  color: #f1f5f9;
  font-size: 0.9rem;
}

.pw-err {
  margin: 0.35rem 0 0;
  font-size: 0.8rem;
  color: #fca5a5;
}

.badge {
  font-size: 0.7rem;
  padding: 0.2rem 0.45rem;
  border-radius: 6px;
}

.badge-muted {
  color: #94a3b8;
  border: 1px solid rgba(148, 163, 184, 0.25);
}

.badge-ok {
  color: #6ee7b7;
  border: 1px solid rgba(52, 211, 153, 0.35);
}

.actions {
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px solid rgba(148, 163, 184, 0.15);
}

.margin-board {
  display: flex;
  flex-direction: column;
  min-height: 0;
  width: 100%;
  max-width: min(44rem, 100%);
  justify-self: center;
}

@media (min-width: 1280px) {
  .margin-board {
    justify-self: start;
    width: auto;
    max-width: min(44rem, 100%);
  }
}

.margin-board-head {
  margin-bottom: 1rem;
  padding: 0 0.05rem;
}

.margin-title {
  margin: 0 0 0.5rem;
  padding-bottom: 0.65rem;
  font-size: 1rem;
  font-weight: 600;
  color: #ccfbf1;
  letter-spacing: 0.02em;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
}

.margin-board-lede {
  margin: 0;
  max-width: none;
  line-height: 1.5;
  font-size: 0.78rem;
}

.margin-table-panel {
  border-radius: 12px;
  border: 1px solid rgba(100, 116, 139, 0.2);
  background: rgba(2, 6, 23, 0.45);
  padding: 0.65rem 0.85rem 0.85rem;
  flex: 0 0 auto;
}

.margin-table-wrap {
  overflow-x: auto;
  margin: 0;
  padding: 0;
  -webkit-overflow-scrolling: touch;
}

.margin-table {
  display: table;
  width: 100%;
  min-width: 0;
  table-layout: fixed;
  border-collapse: separate;
  border-spacing: 0;
}

.margin-row {
  display: table-row;
}

.margin-row-head .margin-cell {
  font-size: 0.68rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #94a3b8;
  padding: 0.4rem 0.45rem 0.55rem 0;
  border-bottom: 1px solid rgba(148, 163, 184, 0.22);
  background: rgba(15, 23, 42, 0.65);
}

.margin-row-head .margin-cell:first-child {
  border-radius: 8px 0 0 0;
  padding-left: 0.65rem;
}

.margin-row-head .margin-cell:last-child {
  border-radius: 0 8px 0 0;
  padding-right: 0.35rem;
}

.margin-row.margin-data .margin-cell {
  padding: 0.75rem 0.45rem 0.75rem 0;
  vertical-align: middle;
  border-bottom: 1px solid rgba(148, 163, 184, 0.08);
  transition: background 0.15s ease;
}

.margin-row.margin-data .margin-cell:first-child {
  padding-left: 0.65rem;
}

.margin-row.margin-data:last-child .margin-cell {
  border-bottom: none;
}

.margin-table .margin-data:nth-child(2) .margin-cell,
.margin-table .margin-data:nth-child(4) .margin-cell {
  background: rgba(30, 41, 59, 0.32);
}

.margin-table .margin-data:nth-child(3) .margin-cell {
  background: rgba(15, 23, 42, 0.35);
}

.margin-row.margin-data:hover .margin-cell {
  background: rgba(51, 65, 85, 0.35);
}

.margin-cell {
  display: table-cell;
}

.margin-cell-type {
  width: 46%;
  min-width: 0;
  padding-right: 0.85rem;
}

.margin-cell-num {
  width: 27%;
  min-width: 0;
  padding-right: 0.35rem;
  text-align: right;
}

.margin-row-head .margin-cell-num {
  text-align: right;
}

.mg-name {
  display: block;
  font-size: 0.84rem;
  font-weight: 600;
  color: #e2e8f0;
  margin-bottom: 0.2rem;
}

.mg-desc {
  display: block;
  font-size: 0.72rem;
  line-height: 1.4;
  color: #64748b;
  max-width: none;
}

.mg-label {
  display: flex;
  flex-direction: column;
  gap: 0.3rem;
  align-items: flex-end;
  margin: 0 0 0 auto;
  width: 100%;
  max-width: 5.75rem;
}

.mg-label-text {
  font-size: 0.68rem;
  font-weight: 600;
  color: #94a3b8;
  width: 100%;
  text-align: right;
}

.mg-input {
  width: 100%;
  margin: 0;
  font-variant-numeric: tabular-nums;
  text-align: right;
  min-height: 2.1rem;
}

.margin-footer-actions {
  margin-top: 1.1rem;
  padding-top: 0.95rem;
  border-top: 1px solid rgba(148, 163, 184, 0.12);
  padding-left: 0.05rem;
  padding-right: 0.05rem;
}

.btn {
  display: inline-flex;
  padding: 0.55rem 1rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
}

.btn.primary {
  background: linear-gradient(90deg, #2563eb, #7c3aed);
  color: #fff;
}

.btn.secondary {
  border-color: rgba(148, 163, 184, 0.35);
  color: #e2e8f0;
  background: transparent;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.muted {
  color: #94a3b8;
}

.pad {
  padding: 1rem;
}

.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 120;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(2, 6, 23, 0.72);
  backdrop-filter: blur(6px);
}

.modal-card {
  width: 100%;
  max-width: 420px;
  padding: 1.25rem 1.35rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.25);
  background: rgba(15, 23, 42, 0.98);
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.45);
}

.modal-title {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
  color: #f8fafc;
}

.modal-lede {
  margin: 0 0 1rem;
  font-size: 0.85rem;
  color: #94a3b8;
  line-height: 1.45;
}

.modal-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.65rem;
  margin-top: 1.15rem;
  padding-top: 1rem;
  border-top: 1px solid rgba(148, 163, 184, 0.15);
}
</style>
