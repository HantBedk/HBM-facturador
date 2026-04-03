<script setup>
import { onMounted, ref, watch } from 'vue'
import {
  createServiceCatalogItem,
  fetchAdminServiceCatalog,
  patchServiceCatalogEstado,
  updateServiceCatalogItem,
} from '@/services/servicesApi.js'

const rows = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref('')
const saving = ref(false)
const fieldErrors = ref({})

const showModal = ref(false)
const editingId = ref(null)
const form = ref({
  name: '',
  description: '',
  base_price: '',
  status: 'activo',
})

async function load() {
  error.value = ''
  loading.value = true
  try {
    const res = await fetchAdminServiceCatalog({ per_page: 100 })
    rows.value = res.data || []
    meta.value = res.meta || null
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar el catálogo.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

onMounted(load)

watch(showModal, (open) => {
  if (!open) {
    editingId.value = null
    form.value = { name: '', description: '', base_price: '', status: 'activo' }
  }
})

function openCreate() {
  editingId.value = null
  form.value = { name: '', description: '', base_price: '', status: 'activo' }
  showModal.value = true
}

function openEdit(row) {
  editingId.value = row.id
  form.value = {
    name: row.name,
    description: row.description || '',
    base_price: String(row.base_price),
    status: row.status,
  }
  showModal.value = true
}

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

async function onSave() {
  saving.value = true
  error.value = ''
  fieldErrors.value = {}
  try {
    if (editingId.value) {
      await updateServiceCatalogItem(editingId.value, {
        name: form.value.name.trim(),
        description: form.value.description.trim() || null,
        base_price: Number(form.value.base_price),
        status: form.value.status,
      })
    } else {
      await createServiceCatalogItem({
        name: form.value.name.trim(),
        description: form.value.description.trim() || null,
        base_price: Number(form.value.base_price),
      })
    }
    showModal.value = false
    await load()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo guardar.'
    if (e.data?.errors) fieldErrors.value = e.data.errors
  } finally {
    saving.value = false
  }
}

async function toggleStatus(row) {
  const next = row.status === 'activo' ? 'inactivo' : 'activo'
  try {
    await patchServiceCatalogEstado(row.id, next)
    await load()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo actualizar.'
  }
}
</script>

<template>
  <section class="page">
    <header class="head">
      <div>
        <h1>Catálogo de servicios</h1>
        <p class="lede">Precios base estándar para reutilizar al registrar trabajos. Los cambios solo afectan a nuevos servicios.</p>
      </div>
      <button type="button" class="btn primary" @click="openCreate">+ Nuevo ítem</button>
    </header>

    <p v-if="error" class="banner err">{{ error }}</p>

    <div class="card table-wrap">
      <div v-if="loading" class="muted pad">Cargando…</div>
      <table v-else class="table">
        <thead>
          <tr>
            <th>Nombre</th>
            <th class="num">Precio base</th>
            <th>Estado</th>
            <th class="actions-col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.id">
            <td>
              <strong>{{ r.name }}</strong>
              <p v-if="r.description" class="muted tiny">{{ r.description }}</p>
            </td>
            <td class="num">{{ money(r.base_price) }}</td>
            <td>
              <span class="pill" :data-st="r.status">{{ r.status === 'activo' ? 'Activo' : 'Inactivo' }}</span>
            </td>
            <td class="actions-col">
              <button type="button" class="link" @click="openEdit(r)">Editar</button>
              <button type="button" class="link" @click="toggleStatus(r)">
                {{ r.status === 'activo' ? 'Desactivar' : 'Activar' }}
              </button>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td colspan="4" class="empty muted">Sin ítems. Cree el primero.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <Teleport to="body">
      <div v-if="showModal" class="modal-backdrop" @click.self="showModal = false">
        <div class="modal card">
          <h2>{{ editingId ? 'Editar ítem' : 'Nuevo ítem' }}</h2>
          <form class="modal-form" @submit.prevent="onSave">
            <label>
              <span>Nombre</span>
              <input v-model="form.name" required class="input" maxlength="255" />
            </label>
            <label>
              <span>Descripción (opcional)</span>
              <textarea v-model="form.description" class="input" rows="3" />
            </label>
            <label>
              <span>Precio base (COP)</span>
              <input v-model="form.base_price" type="number" min="0.01" step="0.01" required class="input" />
            </label>
            <label v-if="editingId">
              <span>Estado</span>
              <select v-model="form.status" class="input">
                <option value="activo">Activo</option>
                <option value="inactivo">Inactivo</option>
              </select>
            </label>
            <div class="modal-actions">
              <button type="button" class="btn secondary" @click="showModal = false">Cancelar</button>
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
  max-width: 960px;
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
  color: #94a3b8;
  font-size: 0.9rem;
  max-width: 40rem;
}
.card {
  padding: 1rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
}
.table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}
.table th,
.table td {
  padding: 0.55rem 0.45rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
  text-align: left;
}
.th.num,
.num {
  text-align: right;
}
.pill {
  display: inline-block;
  padding: 0.12rem 0.45rem;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 600;
}
.pill[data-st='activo'] {
  color: #86efac;
  border: 1px solid rgba(74, 222, 128, 0.4);
}
.pill[data-st='inactivo'] {
  color: #94a3b8;
  border: 1px solid rgba(148, 163, 184, 0.35);
}
.link {
  background: none;
  border: none;
  color: #7dd3fc;
  cursor: pointer;
  font-weight: 600;
  margin-right: 0.75rem;
}
.actions-col {
  white-space: nowrap;
}
.muted {
  color: #94a3b8;
}
.tiny {
  font-size: 0.8rem;
  margin: 0.25rem 0 0;
}
.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}
.empty {
  padding: 1.5rem;
  text-align: center;
}
.pad {
  padding: 1rem;
}
.btn {
  display: inline-flex;
  padding: 0.5rem 0.9rem;
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
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 80;
  background: rgba(0, 0, 0, 0.55);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}
.modal {
  width: min(100%, 420px);
  max-height: 90vh;
  overflow-y: auto;
}
.modal-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  margin-top: 1rem;
}
.modal-form span {
  display: block;
  font-size: 0.78rem;
  color: #94a3b8;
  margin-bottom: 0.25rem;
}
.input {
  width: 100%;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.35);
  color: #f8fafc;
  padding: 0.45rem 0.55rem;
  font: inherit;
}
.modal-actions {
  display: flex;
  gap: 0.5rem;
  justify-content: flex-end;
  margin-top: 0.5rem;
}
</style>
