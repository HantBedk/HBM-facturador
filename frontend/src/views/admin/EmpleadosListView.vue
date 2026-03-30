<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { createUser, fetchAdminUsers, patchUserEstado, updateUser } from '@/services/usersApi.js'

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

const ROL_OPTIONS = [
  { value: '', label: 'Todos' },
  { value: 'empleado', label: 'Empleado' },
  { value: 'admin', label: 'Admin' },
  { value: 'super_admin', label: 'Super admin' },
]

const ROL_LABEL = {
  empleado: 'Empleado',
  admin: 'Admin',
  super_admin: 'Super admin',
}

let searchTimer = null

async function load() {
  error.value = ''
  loading.value = true
  try {
    const params = { page: filtersPage.value, per_page: 15 }
    if (search.value.trim()) params.q = search.value.trim()
    if (rolFilter.value) params.rol = rolFilter.value
    if (estadoFilter.value) params.estado = estadoFilter.value
    const res = await fetchAdminUsers(params)
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar los usuarios.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

const filtersPage = ref(1)

watch([rolFilter, estadoFilter], () => {
  filtersPage.value = 1
  load()
})

watch(
  () => search.value,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filtersPage.value = 1
      load()
    }, 320)
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
  modalError.value = ''
  fieldErrors.value = {}
  form.value = { nombre: '', correo: '', password: '', rol: 'empleado', estado: 'activo' }
  modalOpen.value = true
}

function openEdit(row) {
  modalMode.value = 'edit'
  editingId.value = row.id
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

async function onToggleEstado(row) {
  const next = row.estado === 'activo' ? 'inactivo' : 'activo'
  const label = next === 'activo' ? 'activar' : 'desactivar'
  if (!window.confirm(`¿${label} a «${row.nombre}»?`)) return
  try {
    const updated = await patchUserEstado(row.id, next)
    const i = rows.value.findIndex((r) => r.id === row.id)
    if (i >= 0) rows.value[i] = { ...rows.value[i], ...updated }
    else await load()
  } catch (e) {
    window.alert(e.data?.message || e.message || 'No se pudo actualizar.')
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
        <p class="lede">Alta y edición de cuentas. El equipo usa el rol «empleado» para registrar servicios.</p>
      </div>
      <button type="button" class="btn primary" @click="openCreate">+ Nuevo usuario</button>
    </header>

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
            <tr v-for="u in rows" :key="u.id">
              <td>
                <span class="name">{{ u.nombre }}</span>
              </td>
              <td class="muted">{{ u.correo }}</td>
              <td>
                <span class="pill" :data-rol="u.rol">{{ ROL_LABEL[u.rol] || u.rol }}</span>
              </td>
              <td>
                <span class="pill" :data-st="u.estado">{{ u.estado === 'activo' ? 'Activo' : 'Inactivo' }}</span>
              </td>
              <td class="actions-col">
                <RouterLink
                  v-if="u.rol === 'empleado'"
                  class="link"
                  :to="`/admin/empleados/rendimiento/${u.id}`"
                >
                  Historial
                </RouterLink>
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
                <option value="empleado">Empleado</option>
                <option value="admin">Administrador</option>
                <option value="super_admin">Super administrador</option>
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
</style>
