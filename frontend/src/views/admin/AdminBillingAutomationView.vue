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
const technicianDiscountPercent = ref(10)
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

async function loadTechnicianDiscount() {
  techDiscountError.value = ''
  try {
    const d = await fetchTechnicianCatalogDiscount()
    technicianDiscountPercent.value = Number(d?.technician_catalog_discount_percent ?? 10)
  } catch (e) {
    techDiscountError.value = e.data?.message || e.message || 'No se pudo cargar el margen técnico global.'
  }
}

async function saveTechnicianDiscount() {
  techDiscountOk.value = ''
  techDiscountError.value = ''
  techDiscountSaving.value = true
  try {
    await updateTechnicianCatalogDiscount(Number(technicianDiscountPercent.value))
    techDiscountOk.value = 'Porcentaje guardado para facturación.'
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
  <section class="page">
    <header class="head">
      <div>
        <p class="crumb">
          <RouterLink :to="{ name: 'admin-facturas' }">Facturas</RouterLink>
        </p>
        <h1>Facturación automática (borradores)</h1>
        <p class="lede">
          Aquí solo se programa la <strong>creación automática de borradores</strong> (agrupa servicios sin facturar). El
          envío automático de facturas ya aprobadas sigue en el servidor (<code class="inline">AUTOMATION_CUTOFF_*</code>).
          Al pulsar <strong>Guardar en el sistema</strong> se pedirá su contraseña; el cambio queda en
          <strong>Configuración → Historial</strong>.
        </p>
      </div>
    </header>

    <p v-if="help" class="hint">{{ help }}</p>
    <p v-if="error" class="banner err">{{ error }}</p>
    <p v-if="toast" class="banner ok">{{ toast }}</p>

    <div v-if="loading" class="muted pad">Cargando…</div>

    <div v-else class="card">
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

      <div class="field">
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

    <div v-if="!loading" class="card margin-card">
      <h2 class="margin-title">Margen técnico → factura</h2>
      <p class="field-hint margin-hint">
        Este porcentaje global se aplica sobre el importe de referencia que registra el técnico para calcular el valor facturable.
      </p>
      <div class="row-day margin-row">
        <label class="lab margin-label" for="tech-margin">Porcentaje global (%)</label>
        <input
          id="tech-margin"
          v-model.number="technicianDiscountPercent"
          type="number"
          min="0"
          max="95"
          step="0.5"
          class="select margin-input"
        />
        <button type="button" class="btn primary" :disabled="techDiscountSaving" @click="saveTechnicianDiscount">
          {{ techDiscountSaving ? 'Guardando…' : 'Guardar margen' }}
        </button>
      </div>
      <p v-if="techDiscountError" class="banner err">{{ techDiscountError }}</p>
      <p v-else-if="techDiscountOk" class="banner ok">{{ techDiscountOk }}</p>
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
            Introduzca la contraseña de su usuario administrador para aplicar los cambios en la facturación automática.
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
.page {
  max-width: 720px;
  margin: 0 auto;
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
  max-width: 42rem;
  font-size: 0.88rem;
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
  color: #94a3b8;
  margin: 0 0 1rem;
  max-width: 42rem;
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

.card {
  padding: 1rem 1.15rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
}

.toggle-row {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  padding-bottom: 1rem;
  margin-bottom: 1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
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

.field-hint {
  margin: 0.45rem 0 0;
  font-size: 0.78rem;
  color: #64748b;
  max-width: 36rem;
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

.margin-card {
  margin-top: 1rem;
}

.margin-title {
  margin: 0 0 0.4rem;
  font-size: 1.02rem;
  color: #ccfbf1;
}

.margin-hint {
  margin-bottom: 0.8rem;
}

.margin-row {
  align-items: flex-end;
}

.margin-label {
  margin-bottom: 0.25rem;
}

.margin-input {
  width: 8rem;
  min-width: 0;
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
