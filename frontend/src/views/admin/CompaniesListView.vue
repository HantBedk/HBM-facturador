<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import {
  createCompany,
  fetchAdminCompanies,
  patchCompanyEstado,
  updateCompany,
} from '@/services/companiesApi.js'

const rows = ref([])
const loading = ref(false)
const error = ref('')
const search = ref('')
const estadoFilter = ref('')

const modalOpen = ref(false)
const modalMode = ref('create')
const editingId = ref(null)
const saving = ref(false)
const modalError = ref('')
const fieldErrors = ref({})

const form = ref({
  nombre: '',
  factura_sigla: '',
  nit: '',
  telefono: '',
  correo: '',
  estado: 'activo',
})

let searchTimer = null

async function load() {
  error.value = ''
  loading.value = true
  try {
    rows.value = await fetchAdminCompanies({
      q: search.value,
      estado: estadoFilter.value || undefined,
    })
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar las empresas.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  load()
})

onUnmounted(() => {
  clearTimeout(searchTimer)
})

watch(
  () => estadoFilter.value,
  () => {
    load()
  }
)

watch(
  () => search.value,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => load(), 320)
  }
)

function openCreate() {
  modalMode.value = 'create'
  editingId.value = null
  modalError.value = ''
  fieldErrors.value = {}
  form.value = {
    nombre: '',
    factura_sigla: '',
    nit: '',
    telefono: '',
    correo: '',
    estado: 'activo',
  }
  modalOpen.value = true
}

function openEdit(row) {
  modalMode.value = 'edit'
  editingId.value = row.id
  modalError.value = ''
  fieldErrors.value = {}
  form.value = {
    nombre: row.nombre || '',
    factura_sigla: (row.factura_sigla || '').toString().toUpperCase().slice(0, 3),
    nit: row.nit || '',
    telefono: row.telefono || '',
    correo: row.correo || '',
    estado: row.estado === 'inactivo' ? 'inactivo' : 'activo',
  }
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
}

const modalTitle = computed(() => (modalMode.value === 'create' ? 'Nueva empresa' : 'Editar empresa'))

function formatDate(iso) {
  if (!iso) return '—'
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return '—'
  return d.toLocaleDateString('es-CO', { day: 'numeric', month: 'short', year: 'numeric' })
}

async function onSubmitModal() {
  modalError.value = ''
  fieldErrors.value = {}
  saving.value = true
  try {
    const payload = {
      nombre: form.value.nombre.trim(),
      factura_sigla: form.value.factura_sigla.trim().toUpperCase().replace(/[^A-Z]/g, '').slice(0, 3),
      nit: form.value.nit.trim() || null,
      telefono: form.value.telefono.trim() || null,
      correo: form.value.correo.trim() || null,
      estado: form.value.estado,
    }
    if (modalMode.value === 'create') {
      await createCompany(payload)
    } else {
      await updateCompany(editingId.value, payload)
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
  if (!window.confirm(`¿${label} la empresa «${row.nombre}»?`)) return
  try {
    const updated = await patchCompanyEstado(row.id, next)
    const i = rows.value.findIndex((r) => r.id === row.id)
    if (i >= 0) rows.value[i] = { ...rows.value[i], ...updated }
    else await load()
  } catch (e) {
    window.alert(e.data?.message || e.message || 'No se pudo actualizar el estado.')
  }
}
</script>

<template>
  <section class="page">
    <header class="head">
      <div>
        <h1>Empresas</h1>
        <p class="lede">
          Clientes del sistema. Solo las activas aparecen al registrar servicios. Use desactivar en lugar de eliminar.
        </p>
      </div>
      <button type="button" class="btn primary" @click="openCreate">+ Nueva empresa</button>
    </header>

    <div class="toolbar card">
      <label class="grow">
        <span class="lbl">Buscar</span>
        <input v-model="search" type="search" class="input" placeholder="Nombre, NIT o correo…" />
      </label>
      <label>
        <span class="lbl">Estado</span>
        <select v-model="estadoFilter" class="input">
          <option value="">Todos</option>
          <option value="activo">Activo</option>
          <option value="inactivo">Inactivo</option>
        </select>
      </label>
    </div>

    <p v-if="error" class="banner err">{{ error }}</p>

    <div class="card table-wrap">
      <div v-if="loading" class="muted pad">Cargando…</div>
      <template v-else>
        <table class="table">
          <thead>
            <tr>
              <th>Empresa</th>
              <th>Sigla factura</th>
              <th>NIT / ID</th>
              <th>Contacto</th>
              <th>Estado</th>
              <th>Creada</th>
              <th class="actions-col">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in rows" :key="c.id">
              <td>
                <span class="name">{{ c.nombre }}</span>
              </td>
              <td>
                <code v-if="c.factura_sigla" class="sigla">{{ c.factura_sigla }}</code>
                <span v-else class="muted">—</span>
              </td>
              <td class="muted">{{ c.nit || '—' }}</td>
              <td>
                <div v-if="c.telefono" class="cell-sm">{{ c.telefono }}</div>
                <div v-if="c.correo" class="cell-sm muted">{{ c.correo }}</div>
                <span v-if="!c.telefono && !c.correo" class="muted">—</span>
              </td>
              <td>
                <span class="pill" :data-st="c.estado">{{ c.estado === 'inactivo' ? 'Inactiva' : 'Activa' }}</span>
              </td>
              <td class="muted">{{ formatDate(c.created_at) }}</td>
              <td class="actions-col">
                <button type="button" class="link" @click="openEdit(c)">Editar</button>
                <label class="toggle" :title="c.estado === 'activo' ? 'Desactivar' : 'Activar'">
                  <input
                    type="checkbox"
                    :checked="c.estado === 'activo'"
                    @click.prevent="onToggleEstado(c)"
                  />
                  <span class="slider" />
                </label>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="7" class="empty muted">No hay empresas con estos criterios.</td>
            </tr>
          </tbody>
        </table>
      </template>
    </div>

    <Teleport to="body">
      <div v-if="modalOpen" class="modal-backdrop" @click.self="closeModal">
        <div class="modal card" role="dialog" aria-modal="true" :aria-labelledby="'emp-modal-title'">
          <h2 :id="'emp-modal-title'" class="modal-title">{{ modalTitle }}</h2>
          <p v-if="modalError" class="banner err">{{ modalError }}</p>
          <form class="modal-form" @submit.prevent="onSubmitModal">
            <label class="field">
              <span>Nombre <abbr title="obligatorio">*</abbr></span>
              <input v-model="form.nombre" class="input" required maxlength="255" placeholder="Ej. Tech Solutions" />
              <small v-if="fieldErrors.nombre" class="err">{{ fieldErrors.nombre[0] }}</small>
            </label>
            <label class="field">
              <span>Sigla en factura (3 letras) <abbr title="obligatorio">*</abbr></span>
              <input
                v-model="form.factura_sigla"
                class="input sigla-input"
                required
                maxlength="3"
                pattern="[A-Za-z]{3}"
                title="Tres letras A-Z"
                placeholder="Ej. SYF"
                autocapitalize="characters"
                autocomplete="off"
              />
              <small class="muted">En facturas: <strong>FAC-YYMMDD-XXX</strong> (1 por día y empresa). Debe ser única entre empresas.</small>
              <small v-if="fieldErrors.factura_sigla" class="err">{{ fieldErrors.factura_sigla[0] }}</small>
            </label>
            <label class="field">
              <span>NIT / identificación</span>
              <input v-model="form.nit" class="input" maxlength="100" placeholder="Opcional" />
              <small v-if="fieldErrors.nit" class="err">{{ fieldErrors.nit[0] }}</small>
            </label>
            <label class="field">
              <span>Teléfono</span>
              <input v-model="form.telefono" class="input" maxlength="64" placeholder="Opcional" />
              <small v-if="fieldErrors.telefono" class="err">{{ fieldErrors.telefono[0] }}</small>
            </label>
            <label class="field">
              <span>Correo</span>
              <input v-model="form.correo" type="email" class="input" maxlength="255" placeholder="Opcional" />
              <small v-if="fieldErrors.correo" class="err">{{ fieldErrors.correo[0] }}</small>
            </label>
            <fieldset class="field">
              <legend>Estado</legend>
              <label class="radio">
                <input v-model="form.estado" type="radio" value="activo" />
                Activa
              </label>
              <label class="radio">
                <input v-model="form.estado" type="radio" value="inactivo" />
                Inactiva
              </label>
              <small v-if="fieldErrors.estado" class="err">{{ fieldErrors.estado[0] }}</small>
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
  align-items: flex-start;
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
  line-height: 1.45;
}

.card {
  padding: 1rem 1.15rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 1rem;
}

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: flex-end;
}

.grow {
  flex: 1;
  min-width: 200px;
}

.lbl {
  display: block;
  font-size: 0.8rem;
  color: #94a3b8;
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

.input:focus {
  outline: none;
  border-color: #38bdf8;
  box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.35);
}

.banner {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  margin-bottom: 1rem;
}

.banner.err {
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
}

.table-wrap {
  overflow-x: auto;
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
  letter-spacing: 0.03em;
}

.name {
  font-weight: 600;
  color: #f1f5f9;
}

.sigla {
  font-size: 0.85rem;
  font-weight: 700;
  letter-spacing: 0.06em;
  color: #7dd3fc;
  background: rgba(56, 189, 248, 0.1);
  padding: 0.15rem 0.45rem;
  border-radius: 6px;
}

.sigla-input {
  text-transform: uppercase;
  letter-spacing: 0.12em;
  font-weight: 600;
}

.cell-sm {
  font-size: 0.82rem;
  line-height: 1.35;
}

.pill {
  display: inline-block;
  padding: 0.15rem 0.5rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: capitalize;
}

.pill[data-st='activo'] {
  background: rgba(34, 197, 94, 0.15);
  color: #86efac;
  border: 1px solid rgba(34, 197, 94, 0.35);
}

.pill[data-st='inactivo'] {
  background: rgba(148, 163, 184, 0.12);
  color: #cbd5e1;
  border: 1px solid rgba(148, 163, 184, 0.3);
}

.actions-col {
  white-space: nowrap;
}

.link {
  margin-right: 0.75rem;
  padding: 0;
  border: none;
  background: none;
  color: #7dd3fc;
  font-weight: 600;
  font-size: 0.85rem;
  cursor: pointer;
  text-decoration: underline;
}

.link:hover {
  color: #bae6fd;
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
  padding: 2rem 1rem;
  text-align: center;
}

.muted {
  color: #94a3b8;
}

.pad {
  padding: 1rem;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
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

.btn.primary:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.btn.secondary {
  border-color: rgba(148, 163, 184, 0.35);
  color: #e2e8f0;
  background: transparent;
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
  margin: 0;
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
