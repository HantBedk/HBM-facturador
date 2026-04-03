<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  fetchEmpleadoNotificacionSettings,
  updateEmpleadoNotificacionSettings,
} from '@/services/adminEmpleadoNotifSettingsApi.js'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const toast = ref('')
const help = ref('')
const items = ref([])

/** @type {Record<string, boolean>} */
const toggles = ref({})

async function load() {
  error.value = ''
  toast.value = ''
  loading.value = true
  try {
    const r = await fetchEmpleadoNotificacionSettings()
    help.value = r.help || ''
    items.value = r.items || []
    const m = {}
    for (const it of items.value) {
      m[it.type] = !!it.enabled
    }
    toggles.value = m
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar la configuración.'
    items.value = []
  } finally {
    loading.value = false
  }
}

async function save() {
  error.value = ''
  toast.value = ''
  saving.value = true
  try {
    const r = await updateEmpleadoNotificacionSettings(toggles.value)
    toast.value = r.message || 'Guardado.'
    if (r.items) {
      items.value = r.items
      const m = { ...toggles.value }
      for (const it of r.items) {
        m[it.type] = !!it.enabled
      }
      toggles.value = m
    }
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="page">
    <header class="head">
      <div>
        <p class="crumb">
          <RouterLink to="/admin/configuracion/cuentas">Cuentas del equipo</RouterLink>
        </p>
        <h1>Notificaciones para técnicos</h1>
        <p class="lede">
          Activa o desactiva los tipos de avisos que se generan en el panel del técnico (campana superior). No borra avisos
          ya enviados.
        </p>
      </div>
    </header>

    <p v-if="help" class="hint">{{ help }}</p>
    <p v-if="error" class="banner err">{{ error }}</p>
    <p v-if="toast" class="banner ok">{{ toast }}</p>

    <div v-if="loading" class="muted pad">Cargando…</div>

    <div v-else class="card">
      <ul class="list">
        <li v-for="it in items" :key="it.type" class="row">
          <label class="lab">
            <input v-model="toggles[it.type]" type="checkbox" class="chk" />
            <span class="txt">{{ it.label }}</span>
          </label>
          <code class="code">{{ it.type }}</code>
        </li>
      </ul>
      <div class="actions">
        <button type="button" class="btn primary" :disabled="saving" @click="save">
          {{ saving ? 'Guardando…' : 'Guardar preferencias' }}
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

.list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.row {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.5rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
}

.row:last-child {
  border-bottom: none;
}

.lab {
  display: flex;
  align-items: flex-start;
  gap: 0.65rem;
  cursor: pointer;
  flex: 1;
  min-width: 200px;
}

.chk {
  margin-top: 0.2rem;
  flex-shrink: 0;
}

.txt {
  color: #e2e8f0;
  font-size: 0.9rem;
  line-height: 1.35;
}

.code {
  font-size: 0.7rem;
  color: #64748b;
  background: rgba(15, 23, 42, 0.8);
  padding: 0.15rem 0.35rem;
  border-radius: 4px;
  align-self: center;
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
