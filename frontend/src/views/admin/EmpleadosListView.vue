<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  approveCorreoSolicitud,
  createUser,
  fetchAdminUsers,
  patchUserEstado,
  rejectCorreoSolicitud,
  updateUser,
} from '@/services/usersApi.js'
import { useAuthStore } from '@/stores/auth'
import { useUiDialogStore } from '@/stores/uiDialog'

const route = useRoute()
const auth = useAuthStore()
const uiDialog = useUiDialogStore()
const router = useRouter()

const rows = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref('')
const search = ref('')
const rolFilter = ref('')
const estadoFilter = ref('')

const modalOpen = ref(false)
const modalMode = ref('create')
const editingId = ref(null)
/** Fila en edición (p. ej. solicitud de correo) */
const editingRow = ref(null)
const saving = ref(false)
const modalError = ref('')
const fieldErrors = ref({})

const form = ref({
  nombre: '',
  correo: '',
  password: '',
  rol: 'empleado',
  estado: 'activo',
})

const isAdminNotSuper = computed(() => auth.user?.rol === 'admin')

const ROL_OPTIONS = computed(() => {
  if (isAdminNotSuper.value) {
    return [
      { value: '', label: 'Todos' },
      { value: 'empleado', label: 'Empleado' },
    ]
  }
  return [
    { value: '', label: 'Todos' },
    { value: 'empleado', label: 'Empleado' },
    { value: 'admin', label: 'Admin' },
    { value: 'super_admin', label: 'Super admin' },
  ]
})

const ROL_FORM_OPTIONS = computed(() => {
  if (isAdminNotSuper.value) {
    return [{ value: 'empleado', label: 'Empleado' }]
  }
  return [
    { value: 'empleado', label: 'Empleado' },
    { value: 'admin', label: 'Administrador' },
    { value: 'super_admin', label: 'Super administrador' },
  ]
})

const ROL_LABEL = {
  empleado: 'Empleado',
  admin: 'Admin',
  super_admin: 'Super admin',
}

let searchTimer = null

/** Desde notificación de cambio de correo: ?usuario_id= */
const filterUsuarioId = computed(() => {
  const raw = route.query.usuario_id
  if (raw == null || raw === '') return null
  const n = Number.parseInt(String(raw), 10)
  return Number.isFinite(n) && n > 0 ? n : null
})

async function load() {
  error.value = ''
  loading.value = true
  try {
    const params = { page: filtersPage.value, per_page: 15 }
    if (filterUsuarioId.value != null) {
      params.user_id = filterUsuarioId.value
    } else {
      if (search.value.trim()) params.q = search.value.trim()
      if (rolFilter.value) params.rol = rolFilter.value
      if (estadoFilter.value) params.estado = estadoFilter.value
    }
    const res = await fetchAdminUsers(params)
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar los usuarios.'
    rows.value = []
  } finally {
    loading.value = false
  }
  if (filterUsuarioId.value != null) {
    await nextTick()
    const el = document.querySelector(`tr[data-user-id="${filterUsuarioId.value}"]`)
    el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  }
}

function clearUsuarioNotificationFilter() {
  router.replace({ path: '/admin/configuracion/cuentas' })
}

const filtersPage = ref(1)

watch(
  () => auth.user?.rol,
  () => {
    if (auth.user?.rol === 'admin' && (rolFilter.value === 'admin' || rolFilter.value === 'super_admin')) {
      rolFilter.value = ''
      filtersPage.value = 1
      load()
    }
  }
)

watch([rolFilter, estadoFilter], () => {
  filtersPage.value = 1
  load()
})

watch(
  () => search.value,
  () => {
    if (filterUsuarioId.value != null) return
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filtersPage.value = 1
      load()
    }, 320)
  }
)

watch(
  () => route.query.usuario_id,
  () => {
    filtersPage.value = 1
    load()
  }
)

onMounted(load)
onUnmounted(() => clearTimeout(searchTimer))

function goPage(p) {
  filtersPage.value = p
  load()
}

function openCreate() {
  modalMode.value = 'create'
  editingId.value = null
  editingRow.value = null
  modalError.value = ''
  fieldErrors.value = {}
  form.value = { nombre: '', correo: '', password: '', rol: 'empleado', estado: 'activo' }
  modalOpen.value = true
}

function openEdit(row) {
  modalMode.value = 'edit'
  editingId.value = row.id
  editingRow.value = row
  modalError.value = ''
  fieldErrors.value = {}
  form.value = {
    nombre: row.nombre || '',
    correo: row.correo || '',
    password: '',
    rol: row.rol,
    estado: row.estado,
  }
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
}

const modalTitle = computed(() => (modalMode.value === 'create' ? 'Nuevo usuario' : 'Editar usuario'))

async function onSubmitModal() {
  modalError.value = ''
  fieldErrors.value = {}
  saving.value = true
  try {
    if (modalMode.value === 'create') {
      await createUser({
        nombre: form.value.nombre.trim(),
        correo: form.value.correo.trim(),
        password: form.value.password,
        rol: form.value.rol,
        estado: form.value.estado,
      })
    } else {
      const payload = {
        nombre: form.value.nombre.trim(),
        correo: form.value.correo.trim(),
        rol: form.value.rol,
        estado: form.value.estado,
      }
      if (form.value.password.trim()) payload.password = form.value.password
      await updateUser(editingId.value, payload)
    }
    modalOpen.value = false
    await load()
  } catch (e) {
    if (e.data?.errors) fieldErrors.value = e.data.errors
    else modalError.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}

async function onApproveCorreo(row) {
  if (!row.correo_solicitado) return false
  const msg = `¿Aplicar como correo de acceso el siguiente?\n${row.correo_solicitado}\n\nEl técnico usará ese correo para iniciar sesión.`
  const ok = await uiDialog.confirm({ title: 'Aprobar correo solicitado', message: msg })
  if (!ok) return false
  try {
    await approveCorreoSolicitud(row.id)
    await load()
    return true
  } catch (e) {
    await uiDialog.alert({
      title: 'Error',
      message: e.data?.message || e.message || 'No se pudo aprobar.',
    })
    return false
  }
}

async function onRejectCorreo(row) {
  if (!row.correo_solicitado) return false
  const ok = await uiDialog.confirm({
    title: 'Rechazar solicitud',
    message: '¿Rechazar la solicitud de cambio de correo? El técnico seguirá con su correo actual.',
    danger: true,
    confirmLabel: 'Rechazar',
  })
  if (!ok) return false
  try {
    await rejectCorreoSolicitud(row.id)
    await load()
    return true
  } catch (e) {
    await uiDialog.alert({
      title: 'Error',
      message: e.data?.message || e.message || 'No se pudo rechazar.',
    })
    return false
  }
}

async function approveFromModal() {
  const row = editingRow.value
  if (!row?.correo_solicitado) return
  const ok = await onApproveCorreo(row)
  if (ok) closeModal()
}

async function rejectFromModal() {
  const row = editingRow.value
  if (!row?.correo_solicitado) return
  const ok = await onRejectCorreo(row)
  if (ok) closeModal()
}

async function onToggleEstado(row) {
  const next = row.estado === 'activo' ? 'inactivo' : 'activo'
  const label = next === 'activo' ? 'activar' : 'desactivar'
  const ok = await uiDialog.confirm({
    title: next === 'activo' ? 'Activar empleado' : 'Desactivar empleado',
    message: `¿${label} a «${row.nombre}»?`,
    danger: next === 'inactivo',
  })
  if (!ok) return
  try {
    const updated = await patchUserEstado(row.id, next)
    const i = rows.value.findIndex((r) => r.id === row.id)
    if (i >= 0) rows.value[i] = { ...rows.value[i], ...updated }
    else await load()
  } catch (e) {
    await uiDialog.alert({
      title: 'Error',
      message: e.data?.message || e.message || 'No se pudo actualizar.',
    })
  }
}

async function applySearch() {
  filtersPage.value = 1
  await load()
}

const pageSummary = computed(() => {
  const m = meta.value
  if (!m || !m.total) return ''
  return `${m.from ?? 0}–${m.to ?? 0} de ${m.total}`
})
</script>

<template>
  <section class="page">
    <header class="head">
      <div>
        <h1>Empleados y usuarios</h1>
        <p class="lede">
          Alta y edición de cuentas. El equipo usa el rol «empleado» para registrar servicios.
          <template v-if="isAdminNotSuper">
            Con su rol solo puede ver y gestionar técnicos; las cuentas administrador las gestiona un super
            administrador.
          </template>
        </p>
      </div>
      <button type="button" class="btn primary" @click="openCreate">+ Nuevo usuario</button>
    </header>

    <p v-if="filterUsuarioId != null" class="banner focus">
      Vista filtrada por la notificación (solicitud de correo). Usa
      <button type="button" class="link-btn" @click="clearUsuarioNotificationFilter">ver todos los usuarios</button>
      para volver al listado completo.
    </p>

    <div class="toolbar card">
      <label class="grow">
        <span class="lbl">Buscar</span>
        <input v-model="search" type="search" class="input" placeholder="Nombre o correo…" @keydown.enter.prevent="applySearch" />
      </label>
      <label>
        <span class="lbl">Rol</span>
        <select v-model="rolFilter" class="input">
          <option v-for="o in ROL_OPTIONS" :key="o.value || 'all'" :value="o.value">{{ o.label }}</option>
        </select>
      </label>
      <label>
        <span class="lbl">Estado</span>
        <select v-model="estadoFilter" class="input">
          <option value="">Todos</option>
          <option value="activo">Activo</option>
          <option value="inactivo">Inactivo</option>
        </select>
      </label>
      <button type="button" class="btn secondary" @click="applySearch">Aplicar</button>
    </div>

    <p v-if="error" class="banner err">{{ error }}</p>

    <div class="card table-wrap">
      <div v-if="loading" class="muted pad">Cargando…</div>
      <template v-else>
        <p v-if="pageSummary" class="meta-line muted">{{ pageSummary }}</p>
        <table class="table">
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Correo</th>
              <th>Rol</th>
              <th>Estado</th>
              <th class="actions-col">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="u in rows"
              :key="u.id"
              :data-user-id="u.id"
              :class="{ 'row--focus': filterUsuarioId != null && filterUsuarioId === u.id }"
            >
              <td>
                <span class="name">{{ u.nombre }}</span>
              </td>
              <td class="muted">
                <div>{{ u.correo }}</div>
                <div
                  v-if="u.rol === 'empleado' && u.correo_solicitado"
                  class="correo-solicitado"
                >
                  <span class="badge">Pendiente</span>
                  {{ u.correo_solicitado }}
                </div>
              </td>
              <td>
                <span class="pill" :data-rol="u.rol">{{ ROL_LABEL[u.rol] || u.rol }}</span>
              </td>
              <td>
                <span class="pill" :data-st="u.estado">{{ u.estado === 'activo' ? 'Activo' : 'Inactivo' }}</span>
              </td>
              <td class="actions-col">
                <RouterLink v-if="u.rol === 'empleado'" class="link" :to="`/admin/empleados/${u.id}/perfil`">
                  Perfil
                </RouterLink>
                <RouterLink
                  v-if="u.rol === 'empleado'"
                  class="link"
                  :to="`/admin/empleados/rendimiento/${u.id}`"
                >
                  Historial
                </RouterLink>
                <template v-if="u.rol === 'empleado' && u.correo_solicitado">
                  <button type="button" class="link ok" @click="onApproveCorreo(u)">Aprobar correo</button>
                  <button type="button" class="link warn" @click="onRejectCorreo(u)">Rechazar</button>
                </template>
                <button type="button" class="link" @click="openEdit(u)">Editar</button>
                <label class="toggle" :title="u.estado === 'activo' ? 'Desactivar' : 'Activar'">
                  <input type="checkbox" :checked="u.estado === 'activo'" @click.prevent="onToggleEstado(u)" />
                  <span class="slider" />
                </label>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="5" class="empty muted">No hay resultados.</td>
            </tr>
          </tbody>
        </table>

        <div v-if="meta && meta.last_page > 1" class="pager">
          <button type="button" class="btn secondary" :disabled="meta.current_page <= 1" @click="goPage(meta.current_page - 1)">
            Anterior
          </button>
          <span class="muted">Página {{ meta.current_page }} / {{ meta.last_page }}</span>
          <button
            type="button"
            class="btn secondary"
            :disabled="meta.current_page >= meta.last_page"
            @click="goPage(meta.current_page + 1)"
          >
            Siguiente
          </button>
        </div>
      </template>
    </div>

    <Teleport to="body">
          <div v-if="modalOpen" class="modal-backdrop" @click.self="closeModal">
        <div class="modal card" role="dialog" aria-modal="true">
          <h2 class="modal-title">{{ modalTitle }}</h2>
          <p v-if="modalError" class="banner err">{{ modalError }}</p>
          <div
            v-if="modalMode === 'edit' && form.rol === 'empleado' && editingRow?.correo_solicitado"
            class="banner info"
          >
            <p class="m-0 mb-2">
              <strong>Solicitud de correo (técnico):</strong>
              {{ editingRow.correo_solicitado }}
            </p>
            <p class="m-0 mb-2 text-sm opacity-90">Acceso actual: {{ form.correo }}</p>
            <div class="correo-actions">
              <button type="button" class="btn primary" @click="approveFromModal">Aprobar este correo</button>
              <button type="button" class="btn secondary" @click="rejectFromModal">Rechazar solicitud</button>
            </div>
          </div>
          <form class="modal-form" @submit.prevent="onSubmitModal">
            <label class="field">
              <span>Nombre <abbr title="obligatorio">*</abbr></span>
              <input v-model="form.nombre" class="input" required maxlength="255" />
              <small v-if="fieldErrors.nombre" class="err">{{ fieldErrors.nombre[0] }}</small>
            </label>
            <label class="field">
              <span>Correo <abbr title="obligatorio">*</abbr></span>
              <input v-model="form.correo" type="email" class="input" required />
              <small v-if="fieldErrors.correo" class="err">{{ fieldErrors.correo[0] }}</small>
            </label>
            <label class="field">
              <span>Contraseña {{ modalMode === 'create' ? '(obligatoria)' : '(opcional, dejar vacío para no cambiar)' }}</span>
              <input v-model="form.password" type="password" class="input" :required="modalMode === 'create'" autocomplete="new-password" />
              <small v-if="fieldErrors.password" class="err">{{ fieldErrors.password[0] }}</small>
            </label>
            <label class="field">
              <span>Rol</span>
              <select v-model="form.rol" class="input" required>
                <option v-for="o in ROL_FORM_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
              </select>
              <small v-if="fieldErrors.rol" class="err">{{ fieldErrors.rol[0] }}</small>
            </label>
            <fieldset class="field">
              <legend>Estado</legend>
              <label class="radio">
                <input v-model="form.estado" type="radio" value="activo" />
                Activo
              </label>
              <label class="radio">
                <input v-model="form.estado" type="radio" value="inactivo" />
                Inactivo
              </label>
            </fieldset>
            <div class="modal-actions">
              <button type="button" class="btn secondary" @click="closeModal">Cancelar</button>
              <button type="submit" class="btn primary" :disabled="saving">{{ saving ? 'Guardando…' : 'Guardar' }}</button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
.page {
  max-width: 1100px;
  margin: 0 auto;
}

.head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  color: #f8fafc;
}

.lede {
  margin: 0;
  max-width: 36rem;
  font-size: 0.88rem;
  color: #94a3b8;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: end;
}

.lbl {
  display: block;
  font-size: 0.8rem;
  color: #94a3b8;
  margin-bottom: 0.35rem;
}

.grow {
  flex: 1;
  min-width: 200px;
}

.input {
  width: 100%;
  max-width: 420px;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.35);
  color: #f8fafc;
  padding: 0.5rem 0.65rem;
  font: inherit;
}

.card {
  padding: 1rem 1.15rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 1rem;
}

.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}

.banner.info {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(56, 189, 248, 0.1);
  border: 1px solid rgba(56, 189, 248, 0.35);
  color: #e0f2fe;
  margin-bottom: 1rem;
}

.banner.focus {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(56, 189, 248, 0.08);
  border: 1px solid rgba(56, 189, 248, 0.4);
  color: #bae6fd;
  margin-bottom: 1rem;
  font-size: 0.875rem;
  line-height: 1.45;
}

.link-btn {
  display: inline;
  margin: 0;
  padding: 0;
  border: none;
  background: none;
  color: #7dd3fc;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  text-decoration: underline;
}

.link-btn:hover {
  color: #bae6fd;
}

.row--focus {
  background: rgba(56, 189, 248, 0.08);
  box-shadow: inset 0 0 0 2px rgba(56, 189, 248, 0.45);
}

.correo-solicitado {
  margin-top: 0.35rem;
  font-size: 0.78rem;
  color: #fde68a;
}

.correo-solicitado .badge {
  display: inline-block;
  margin-right: 0.35rem;
  padding: 0.05rem 0.35rem;
  border-radius: 4px;
  background: rgba(251, 191, 36, 0.2);
  color: #fde68a;
  font-size: 0.7rem;
  font-weight: 700;
}

.link.ok {
  color: #86efac;
}

.link.warn {
  color: #fbbf24;
}

.meta-line {
  font-size: 0.8rem;
  margin: 0 0 0.65rem;
}

.table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}

.table th,
.table td {
  padding: 0.65rem 0.5rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
  text-align: left;
  vertical-align: top;
}

.table th {
  color: #94a3b8;
  font-weight: 600;
  font-size: 0.78rem;
  text-transform: uppercase;
}

.name {
  font-weight: 600;
  color: #f1f5f9;
}

.pill {
  display: inline-block;
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}

.pill[data-rol='empleado'] {
  color: #7dd3fc;
  border: 1px solid rgba(56, 189, 248, 0.35);
}
.pill[data-rol='admin'],
.pill[data-rol='super_admin'] {
  color: #fde68a;
  border: 1px solid rgba(251, 191, 36, 0.35);
}

.pill[data-st='activo'] {
  color: #86efac;
  border: 1px solid rgba(74, 222, 128, 0.35);
}
.pill[data-st='inactivo'] {
  color: #94a3b8;
  border: 1px solid rgba(148, 163, 184, 0.3);
}

.actions-col {
  white-space: nowrap;
}

.link {
  margin-right: 0.65rem;
  padding: 0;
  border: none;
  background: none;
  color: #7dd3fc;
  font-weight: 600;
  font-size: 0.85rem;
  cursor: pointer;
  text-decoration: underline;
}

.toggle {
  position: relative;
  display: inline-block;
  width: 2.5rem;
  height: 1.35rem;
  vertical-align: middle;
  cursor: pointer;
}

.toggle input {
  opacity: 0;
  width: 0;
  height: 0;
}

.slider {
  position: absolute;
  inset: 0;
  border-radius: 999px;
  background: #475569;
  transition: background 0.15s ease;
}

.slider::before {
  content: '';
  position: absolute;
  width: 1.05rem;
  height: 1.05rem;
  left: 0.15rem;
  top: 0.15rem;
  border-radius: 50%;
  background: #fff;
  transition: transform 0.15s ease;
}

.toggle input:checked + .slider {
  background: linear-gradient(90deg, #2563eb, #0ea5e9);
}

.toggle input:checked + .slider::before {
  transform: translateX(1.15rem);
}

.empty {
  padding: 2rem;
  text-align: center;
}

.muted {
  color: #94a3b8;
}

.pad {
  padding: 1rem;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin-top: 1rem;
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

.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(2, 6, 23, 0.72);
  backdrop-filter: blur(6px);
}

.modal {
  width: 100%;
  max-width: 440px;
  max-height: 90vh;
  overflow-y: auto;
}

.modal-title {
  margin: 0 0 1rem;
  font-size: 1.15rem;
  color: #f8fafc;
}

.modal-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.field span,
.field legend {
  display: block;
  font-size: 0.82rem;
  color: #cbd5e1;
  margin-bottom: 0.35rem;
}

.field fieldset {
  border: none;
  padding: 0;
  margin: 0;
}

.radio {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  margin-right: 1rem;
  font-size: 0.9rem;
  color: #e2e8f0;
  cursor: pointer;
}

.err {
  color: #fecaca;
  font-size: 0.78rem;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.65rem;
  margin-top: 0.5rem;
  padding-top: 0.75rem;
  border-top: 1px solid rgba(148, 163, 184, 0.15);
}

.correo-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}
</style>
