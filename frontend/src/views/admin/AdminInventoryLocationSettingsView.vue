<script setup>
import { nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import {
  fetchInventoryLocationSettings,
  updateInventoryLocationSettings,
} from '@/services/adminInventoryLocationSettingsApi.js'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const toast = ref('')
const help = ref('')
const locations = ref([])
const assetTypes = ref([])
const physicalConditions = ref([])
const empleadoVenta = ref(false)
const empleadoAlquiler = ref(false)

const passwordModalOpen = ref(false)
const modalPassword = ref('')
const modalPasswordError = ref('')
const modalPwInputRef = ref(null)

async function load() {
  loading.value = true
  error.value = ''
  toast.value = ''
  try {
    const r = await fetchInventoryLocationSettings()
    help.value = r.help || ''
    const d = r.data || {}
    locations.value = Array.isArray(d.locations) ? d.locations.slice(0, 5) : []
    assetTypes.value = Array.isArray(d.asset_types) ? d.asset_types.slice(0, 25) : []
    physicalConditions.value = Array.isArray(d.physical_conditions) ? d.physical_conditions.slice(0, 20) : []
    empleadoVenta.value = Boolean(d.empleado_inventory_venta_enabled)
    empleadoAlquiler.value = Boolean(d.empleado_inventory_alquiler_enabled)
    if (!locations.value.length) locations.value = ['']
    if (!assetTypes.value.length) assetTypes.value = ['']
    if (!physicalConditions.value.length) physicalConditions.value = ['']
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar los datos.'
  } finally {
    loading.value = false
  }
}

function addLocationRow() {
  if (locations.value.length >= 5) return
  locations.value.push('')
}

function removeLocationRow(index) {
  locations.value.splice(index, 1)
}

function addAssetTypeRow() {
  if (assetTypes.value.length >= 25) return
  assetTypes.value.push('')
}

function removeAssetTypeRow(index) {
  assetTypes.value.splice(index, 1)
}

function addPhysicalRow() {
  if (physicalConditions.value.length >= 20) return
  physicalConditions.value.push('')
}

function removePhysicalRow(index) {
  physicalConditions.value.splice(index, 1)
}

function openPasswordModal() {
  error.value = ''
  modalPassword.value = ''
  modalPasswordError.value = ''
  passwordModalOpen.value = true
  nextTick(() => modalPwInputRef.value?.focus?.())
}

function closePasswordModal() {
  if (saving.value) return
  passwordModalOpen.value = false
  modalPassword.value = ''
  modalPasswordError.value = ''
}

async function confirmSave() {
  modalPasswordError.value = ''
  const pw = String(modalPassword.value || '').trim()
  if (!pw) {
    modalPasswordError.value = 'Indique su contraseña para confirmar.'
    return
  }
  const locClean = locations.value.map((x) => String(x || '').trim()).filter(Boolean)
  const typeClean = assetTypes.value.map((x) => String(x || '').trim()).filter(Boolean)
  const physClean = physicalConditions.value.map((x) => String(x || '').trim()).filter(Boolean)
  if (!locClean.length) {
    modalPasswordError.value = 'Debe ingresar al menos una ubicación.'
    return
  }
  if (!typeClean.length) {
    modalPasswordError.value = 'Debe ingresar al menos un tipo de activo.'
    return
  }
  if (!physClean.length) {
    modalPasswordError.value = 'Debe ingresar al menos un estado físico.'
    return
  }
  saving.value = true
  try {
    const r = await updateInventoryLocationSettings({
      current_password: pw,
      locations: locClean.slice(0, 5),
      asset_types: [...new Set(typeClean)].slice(0, 25),
      physical_conditions: [...new Set(physClean)].slice(0, 20),
      empleado_inventory_venta_enabled: empleadoVenta.value,
      empleado_inventory_alquiler_enabled: empleadoAlquiler.value,
    })
    toast.value = r.message || 'Configuración guardada.'
    const rd = r.data || {}
    locations.value = Array.isArray(rd.locations) ? rd.locations.slice(0, 5) : locClean.slice(0, 5)
    assetTypes.value = Array.isArray(rd.asset_types) ? rd.asset_types.slice(0, 25) : typeClean.slice(0, 25)
    physicalConditions.value = Array.isArray(rd.physical_conditions)
      ? rd.physical_conditions.slice(0, 20)
      : physClean.slice(0, 20)
    if (typeof rd.empleado_inventory_venta_enabled === 'boolean') {
      empleadoVenta.value = rd.empleado_inventory_venta_enabled
    }
    if (typeof rd.empleado_inventory_alquiler_enabled === 'boolean') {
      empleadoAlquiler.value = rd.empleado_inventory_alquiler_enabled
    }
    closePasswordModal()
  } catch (e) {
    const pwe = e.data?.errors?.current_password
    if (Array.isArray(pwe) && pwe[0]) modalPasswordError.value = pwe[0]
    error.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}

function onDocumentEscape(ev) {
  if (ev.key !== 'Escape' || !passwordModalOpen.value || saving.value) return
  ev.preventDefault()
  closePasswordModal()
}

watch(passwordModalOpen, (open) => {
  if (open) document.addEventListener('keydown', onDocumentEscape)
  else document.removeEventListener('keydown', onDocumentEscape)
})

onMounted(load)
onUnmounted(() => document.removeEventListener('keydown', onDocumentEscape))
</script>

<template>
  <section class="page flex h-full min-h-0 flex-col">
    <header class="head">
      <h2>Ubicación y clasificación</h2>
      <p class="lede">
        Ubicaciones, tipos de activo y estados físicos usados en el registro de inventario. Un solo guardado con
        contraseña.
      </p>
    </header>

    <p v-if="help" class="hint">{{ help }}</p>
    <p v-if="error" class="banner err">{{ error }}</p>
    <p v-if="toast" class="banner ok">{{ toast }}</p>

    <div v-if="loading" class="muted pad">Cargando…</div>
    <div v-else class="card">
      <h3 class="block-title">Ubicaciones permitidas</h3>
      <p class="block-hint">Máximo 5. Aparecen como opciones al registrar un activo.</p>
      <div class="list">
        <div v-for="(loc, idx) in locations" :key="'loc-' + idx" class="row">
          <input
            v-model="locations[idx]"
            class="input"
            type="text"
            maxlength="120"
            :placeholder="`Ubicación ${idx + 1}`"
          />
          <button
            type="button"
            class="btn secondary"
            :disabled="saving || locations.length <= 1"
            @click="removeLocationRow(idx)"
          >
            Quitar
          </button>
        </div>
      </div>
      <div class="mini-actions">
        <button type="button" class="btn secondary" :disabled="saving || locations.length >= 5" @click="addLocationRow">
          Añadir ubicación
        </button>
      </div>

      <h3 class="block-title spaced">Tipos de activo</h3>
      <p class="block-hint">Máximo 25 (p. ej. Equipo, Herramienta). Menú desplegable con búsqueda en el alta de activo.</p>
      <div class="list">
        <div v-for="(t, idx) in assetTypes" :key="'at-' + idx" class="row">
          <input
            v-model="assetTypes[idx]"
            class="input"
            type="text"
            maxlength="80"
            :placeholder="`Tipo ${idx + 1}`"
          />
          <button
            type="button"
            class="btn secondary"
            :disabled="saving || assetTypes.length <= 1"
            @click="removeAssetTypeRow(idx)"
          >
            Quitar
          </button>
        </div>
      </div>
      <div class="mini-actions">
        <button type="button" class="btn secondary" :disabled="saving || assetTypes.length >= 25" @click="addAssetTypeRow">
          Añadir tipo
        </button>
      </div>

      <h3 class="block-title spaced">Técnicos: venta y alquiler</h3>
      <p class="block-hint">
        Si activa estas opciones, los técnicos verán rutas para registrar venta o alquiler de su inventario asignado (además del
        servicio en obra). Se guarda junto con el resto al confirmar contraseña.
      </p>
      <label class="perm-row">
        <input v-model="empleadoVenta" type="checkbox" class="perm-check" :disabled="saving" />
        <span>Pueden registrar venta de equipo desde el panel</span>
      </label>
      <label class="perm-row">
        <input v-model="empleadoAlquiler" type="checkbox" class="perm-check" :disabled="saving" />
        <span>Pueden registrar alquiler de equipo desde el panel</span>
      </label>

      <h3 class="block-title spaced">Estados físicos</h3>
      <p class="block-hint">Máximo 20. Campo obligatorio al registrar; mismo desplegable con búsqueda.</p>
      <div class="list">
        <div v-for="(p, idx) in physicalConditions" :key="'ph-' + idx" class="row">
          <input
            v-model="physicalConditions[idx]"
            class="input"
            type="text"
            maxlength="80"
            :placeholder="`Estado ${idx + 1}`"
          />
          <button
            type="button"
            class="btn secondary"
            :disabled="saving || physicalConditions.length <= 1"
            @click="removePhysicalRow(idx)"
          >
            Quitar
          </button>
        </div>
      </div>
      <div class="mini-actions">
        <button
          type="button"
          class="btn secondary"
          :disabled="saving || physicalConditions.length >= 20"
          @click="addPhysicalRow"
        >
          Añadir estado
        </button>
      </div>

      <div class="actions">
        <button type="button" class="btn primary" :disabled="saving" @click="openPasswordModal">
          Guardar todo con contraseña
        </button>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="passwordModalOpen"
        class="modal-backdrop"
        role="presentation"
        @click.self="closePasswordModal"
      >
        <div class="modal-card" role="dialog" aria-modal="true">
          <h2 class="modal-title">Confirmar cambios</h2>
          <p class="modal-lede">
            Introduzca su contraseña de administrador para guardar ubicaciones, permisos de técnicos, tipos de activo y estados
            físicos.
          </p>
          <input
            ref="modalPwInputRef"
            v-model="modalPassword"
            type="password"
            class="input"
            autocomplete="current-password"
            placeholder="Contraseña"
            @keydown.enter.prevent="confirmSave"
          />
          <p v-if="modalPasswordError" class="pw-err">{{ modalPasswordError }}</p>
          <div class="actions modal-actions">
            <button type="button" class="btn secondary" :disabled="saving" @click="closePasswordModal">Cancelar</button>
            <button type="button" class="btn primary" :disabled="saving" @click="confirmSave">
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
  width: 100%;
  max-width: none;
  margin: 0;
}
.head {
  margin-bottom: 1rem;
  flex-shrink: 0;
}
.head h2 {
  margin: 0 0 0.35rem;
  font-size: 1.25rem;
  font-weight: 700;
  color: #f8fafc;
}
.lede {
  margin: 0;
  font-size: 0.875rem;
  line-height: 1.5;
  color: #94a3b8;
}
.hint,
.muted {
  color: #94a3b8;
  font-size: 0.8125rem;
}
.hint {
  margin-bottom: 0.75rem;
  flex-shrink: 0;
}
.card {
  padding: 1rem 1.1rem;
  border-radius: 1rem;
  border: 1px solid rgba(51, 65, 85, 0.6);
  background: rgba(19, 22, 31, 0.9);
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  min-height: 0;
}
.block-title {
  margin: 0 0 0.25rem;
  font-size: 0.95rem;
  font-weight: 700;
  color: #e2e8f0;
}
.block-title.spaced {
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px solid rgba(51, 65, 85, 0.35);
}
.block-hint {
  margin: 0 0 0.6rem;
  font-size: 0.78rem;
  line-height: 1.45;
  color: #64748b;
}
.list {
  display: flex;
  flex-direction: column;
  gap: 0.6rem;
  flex: 0 0 auto;
}
.row {
  display: flex;
  align-items: center;
  gap: 0.6rem;
}
.row .input {
  flex: 1 1 auto;
  min-width: 0;
}
.perm-row {
  display: flex;
  align-items: flex-start;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
  font-size: 0.875rem;
  color: #cbd5e1;
  line-height: 1.4;
}
.perm-check {
  margin-top: 0.2rem;
  flex-shrink: 0;
}
.input {
  width: 100%;
  padding: 0.5rem 0.65rem;
  border-radius: 0.5rem;
  border: 1px solid rgba(71, 85, 105, 0.8);
  background: #13161f;
  color: #f1f5f9;
  font-size: 0.875rem;
  transition: border-color 0.15s ease;
}
.input:focus {
  outline: none;
  border-color: rgba(56, 189, 248, 0.55);
}
.mini-actions {
  margin-top: 0.5rem;
  margin-bottom: 0.25rem;
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
.actions {
  margin-top: auto;
  padding-top: 0.85rem;
  border-top: 1px solid rgba(51, 65, 85, 0.35);
  display: flex;
  gap: 0.6rem;
  justify-content: flex-end;
  flex-wrap: wrap;
}
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem 0.85rem;
  border-radius: 0.5rem;
  font-size: 0.875rem;
  font-weight: 600;
  cursor: pointer;
  border: none;
  transition: opacity 0.15s ease, transform 0.12s ease;
}
.btn:active:not(:disabled) {
  transform: scale(0.98);
}
.btn.primary {
  background: #2563eb;
  color: #fff;
}
.btn.secondary {
  background: rgba(51, 65, 85, 0.7);
  color: #e2e8f0;
}
.btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 0.75rem;
  background: rgba(244, 63, 94, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fda4af;
  margin-bottom: 0.75rem;
  flex-shrink: 0;
}
.banner.ok {
  padding: 0.65rem 0.85rem;
  border-radius: 0.75rem;
  background: rgba(16, 185, 129, 0.12);
  color: #6ee7b7;
  margin-bottom: 0.75rem;
  flex-shrink: 0;
}
.pad {
  padding: 1rem 0;
  flex-shrink: 0;
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
.pw-err {
  margin: 0.35rem 0 0;
  font-size: 0.8rem;
  color: #fca5a5;
}
.modal-actions {
  border-top: none;
  padding-top: 1rem;
  margin-top: 0;
}
</style>
