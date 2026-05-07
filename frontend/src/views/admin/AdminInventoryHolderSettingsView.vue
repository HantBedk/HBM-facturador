<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { api } from '@/services/api.js'
import {
  fetchInventoryHolderSettings,
  updateInventoryHolderSettings,
} from '@/services/adminInventoryHolderSettingsApi.js'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const toast = ref('')
const help = ref('')
const usesExplicitList = ref(false)
const holders = ref([])

const allUsers = ref([])
const userSearchQ = ref('')

const passwordModalOpen = ref(false)
const modalPassword = ref('')
const modalPasswordError = ref('')
const modalPwInputRef = ref(null)

const pickOptions = computed(() => {
  const ids = new Set(holders.value.map((h) => h.id))
  return allUsers.value.filter((u) => !ids.has(u.id))
})

const filteredPickOptions = computed(() => {
  const q = userSearchQ.value.trim().toLowerCase()
  const rows = pickOptions.value
  if (!q) return rows
  return rows.filter((u) => {
    const blob = `${u.nombre} ${u.correo} ${u.rol}`.toLowerCase()
    return blob.includes(q)
  })
})

async function loadUsers() {
  try {
    const res = await api('/admin/users?estado=activo&per_page=300&sort=nombre')
    allUsers.value = res.data || []
  } catch {
    allUsers.value = []
  }
}

async function load() {
  loading.value = true
  error.value = ''
  toast.value = ''
  try {
    const r = await fetchInventoryHolderSettings()
    help.value = r.help || ''
    usesExplicitList.value = !!r.data?.uses_explicit_list
    holders.value = Array.isArray(r.data?.holders) ? [...r.data.holders] : []
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar los titulares.'
  } finally {
    loading.value = false
  }
}

function addHolderFromUser(u) {
  if (!u || saving.value || holders.value.length >= 30) return
  if (holders.value.some((h) => h.id === u.id)) return
  holders.value.push({
    id: u.id,
    nombre: u.nombre,
    correo: u.correo,
    rol: u.rol,
  })
  userSearchQ.value = ''
}

function removeHolder(index) {
  holders.value.splice(index, 1)
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
  const ids = holders.value.map((h) => h.id)
  if (!ids.length) {
    modalPasswordError.value = 'Debe mantener al menos un titular.'
    return
  }
  saving.value = true
  try {
    const r = await updateInventoryHolderSettings({
      current_password: pw,
      holder_user_ids: ids,
    })
    toast.value = r.message || 'Titulares guardados.'
    usesExplicitList.value = !!r.data?.uses_explicit_list
    holders.value = Array.isArray(r.data?.holders) ? [...r.data.holders] : holders.value
    closePasswordModal()
  } catch (e) {
    const pwe = e.data?.errors?.current_password
    if (Array.isArray(pwe) && pwe[0]) modalPasswordError.value = pwe[0]
    const he = e.data?.errors?.holder_user_ids
    if (Array.isArray(he) && he[0]) modalPasswordError.value = he[0]
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

onMounted(async () => {
  await Promise.all([loadUsers(), load()])
})
onUnmounted(() => document.removeEventListener('keydown', onDocumentEscape))
</script>

<template>
  <section class="page flex h-full min-h-0 flex-col">
    <header class="head">
      <h2>Titulares de inventario</h2>
      <p class="lede">
        Usuarios que pueden figurar como titular al registrar activos del inventario interno. Los cambios requieren
        contraseña.
      </p>
    </header>

    <p v-if="help" class="hint">{{ help }}</p>
    <p v-if="!usesExplicitList && !loading" class="hint">
      Aún no hay una lista guardada: se están usando todos los administradores activos. Guarde una lista explícita
      para acotar o ampliar titulares (por ejemplo, técnicos u otros roles).
    </p>
    <p v-if="error" class="banner err">{{ error }}</p>
    <p v-if="toast" class="banner ok">{{ toast }}</p>

    <div v-if="loading" class="muted pad">Cargando…</div>
    <div v-else class="card">
      <div class="add-block">
        <input
          v-model="userSearchQ"
          type="search"
          autocomplete="off"
          placeholder="Buscar usuario por nombre, correo o rol…"
          class="input"
          :disabled="saving || holders.length >= 30"
        />
        <ul class="pick-list">
          <li
            v-for="u in filteredPickOptions"
            :key="u.id"
            class="pick-item"
            @click="addHolderFromUser(u)"
          >
            <span class="pick-name">{{ u.nombre }}</span>
            <span class="pick-meta">{{ u.correo }} · {{ u.rol }}</span>
          </li>
        </ul>
        <p v-if="userSearchQ.trim() && !filteredPickOptions.length" class="pick-empty">Sin coincidencias.</p>
        <p v-if="!pickOptions.length && !userSearchQ.trim()" class="pick-empty">No quedan usuarios por añadir.</p>
      </div>

      <ul class="holder-list">
        <li v-for="(h, idx) in holders" :key="h.id" class="holder-item">
          <div>
            <span class="name">{{ h.nombre }}</span>
            <span class="meta">{{ h.correo }} · {{ h.rol }}</span>
          </div>
          <button
            type="button"
            class="btn secondary"
            :disabled="saving || holders.length <= 1"
            @click="removeHolder(idx)"
          >
            Quitar
          </button>
        </li>
      </ul>

      <div class="actions">
        <button type="button" class="btn primary" :disabled="saving || holders.length < 1" @click="openPasswordModal">
          Guardar con contraseña
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
          <p class="modal-lede">Introduzca su contraseña de administrador para guardar la lista de titulares.</p>
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
.hint {
  font-size: 0.8125rem;
  color: #94a3b8;
  margin-bottom: 0.75rem;
  flex-shrink: 0;
}
.banner {
  border-radius: 0.75rem;
  padding: 0.65rem 0.85rem;
  font-size: 0.875rem;
  margin-bottom: 0.75rem;
}
.banner.err {
  background: rgba(244, 63, 94, 0.12);
  color: #fda4af;
}
.banner.ok {
  background: rgba(16, 185, 129, 0.12);
  color: #6ee7b7;
}
.muted {
  color: #64748b;
}
.pad {
  padding: 1rem 0;
}
.card {
  border-radius: 1rem;
  border: 1px solid rgba(51, 65, 85, 0.6);
  background: rgba(19, 22, 31, 0.9);
  padding: 1rem 1.1rem;
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  min-height: 0;
}
.add-block {
  margin-bottom: 1rem;
  flex-shrink: 0;
}
.pick-list {
  list-style: none;
  margin: 0.5rem 0 0;
  padding: 0;
  max-height: 11rem;
  overflow-y: auto;
  border-radius: 0.5rem;
  border: 1px solid rgba(71, 85, 105, 0.7);
  background: #13161f;
}
.pick-item {
  cursor: pointer;
  padding: 0.55rem 0.75rem;
  border-bottom: 1px solid rgba(51, 65, 85, 0.45);
  transition: background 0.15s ease;
}
.pick-item:last-child {
  border-bottom: none;
}
.pick-item:hover {
  background: rgba(30, 41, 59, 0.65);
}
.pick-item:active {
  background: rgba(30, 41, 59, 0.85);
}
.pick-name {
  display: block;
  font-weight: 600;
  color: #f1f5f9;
  font-size: 0.875rem;
}
.pick-meta {
  font-size: 0.75rem;
  color: #94a3b8;
}
.pick-empty {
  margin-top: 0.35rem;
  font-size: 0.8125rem;
  color: #64748b;
}
.holder-list {
  list-style: none;
  margin: 0 0 1rem;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  flex: 1 1 auto;
  min-height: 6rem;
  overflow-y: auto;
}
.holder-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.6rem 0.75rem;
  border-radius: 0.75rem;
  background: rgba(15, 23, 42, 0.6);
  border: 1px solid rgba(51, 65, 85, 0.45);
}
.name {
  display: block;
  font-weight: 600;
  color: #f1f5f9;
}
.meta {
  font-size: 0.75rem;
  color: #94a3b8;
}
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: auto;
  padding-top: 0.75rem;
  border-top: 1px solid rgba(51, 65, 85, 0.35);
}
.input {
  width: 100%;
  border-radius: 0.5rem;
  border: 1px solid rgba(71, 85, 105, 0.8);
  background: #13161f;
  color: #f1f5f9;
  padding: 0.5rem 0.65rem;
  font-size: 0.875rem;
}
.btn {
  border-radius: 0.5rem;
  padding: 0.5rem 0.85rem;
  font-size: 0.875rem;
  font-weight: 500;
  border: none;
  cursor: pointer;
}
.btn:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.btn.primary {
  background: #2563eb;
  color: #fff;
}
.btn.secondary {
  background: rgba(51, 65, 85, 0.7);
  color: #e2e8f0;
}
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.55);
  padding: 1rem;
}
.modal-card {
  width: 100%;
  max-width: 400px;
  border-radius: 1rem;
  border: 1px solid rgba(71, 85, 105, 0.7);
  background: #1c212c;
  padding: 1.25rem;
}
.modal-title {
  font-size: 1.1rem;
  font-weight: 700;
  color: #f8fafc;
}
.modal-lede {
  margin: 0.5rem 0 1rem;
  font-size: 0.8125rem;
  color: #94a3b8;
}
.modal-actions {
  margin-top: 1rem;
}
.pw-err {
  margin-top: 0.5rem;
  font-size: 0.8125rem;
  color: #fb7185;
}
</style>
