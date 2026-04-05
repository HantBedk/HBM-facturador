<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  fetchBillingAutomationSettings,
  updateBillingAutomationSettings,
} from '@/services/adminBillingAutomationApi.js'

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

async function save() {
  error.value = ''
  toast.value = ''
  saving.value = true
  try {
    const r = await updateBillingAutomationSettings({
      draft_generation_enabled: draftGenerationEnabled.value,
      draft_generation_day: draftGenerationDay.value,
      draft_generation_period: draftGenerationPeriod.value,
    })
    toast.value = r.message || 'Guardado.'
    const d = r.data || {}
    draftGenerationEnabled.value = !!d.draft_generation_enabled
    draftGenerationDay.value = Math.min(28, Math.max(1, Number(d.draft_generation_day) || 18))
    draftGenerationPeriod.value = d.draft_generation_period === 'previous' ? 'previous' : 'current'
    storedInDatabase.value = d.stored_in_database || {}
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}

function usingDb(key) {
  return !!storedInDatabase.value[key]
}

onMounted(load)
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
          Defina el día del mes en que el sistema intentará crear los borradores de factura por empresa (servicios del
          periodo aún sin facturar). El envío automático de facturas aprobadas sigue configurándose en el servidor
          (<code class="inline">AUTOMATION_CUTOFF_*</code>).
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
        <label class="lab" for="draft-period">Periodo a incluir en el borrador</label>
        <select id="draft-period" v-model="draftGenerationPeriod" class="select wide">
          <option value="current">Mes en curso (calendario)</option>
          <option value="previous">Mes calendario anterior</option>
        </select>
      </div>

      <div class="actions">
        <button type="button" class="btn primary" :disabled="saving" @click="save">
          {{ saving ? 'Guardando…' : 'Guardar en el sistema' }}
        </button>
      </div>
    </div>
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
</style>
