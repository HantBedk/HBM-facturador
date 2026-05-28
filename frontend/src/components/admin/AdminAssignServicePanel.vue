<script setup>
import { ref, watch } from 'vue'
import { assignServiceToTechnician, fetchCompanies, fetchServiceCatalogActive } from '@/services/servicesApi.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Fila usuario (técnico) */
  technician: { type: Object, default: null },
})

const emit = defineEmits(['close', 'assigned'])

const companies = ref([])
const catalogItems = ref([])
const companyId = ref('')
const catalogId = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')

watch(
  () => props.open,
  async (o) => {
    if (!o) return
    error.value = ''
    companyId.value = ''
    catalogId.value = ''
    loading.value = true
    try {
      const [co, cat] = await Promise.all([fetchCompanies(), fetchServiceCatalogActive()])
      companies.value = Array.isArray(co) ? co : []
      catalogItems.value = Array.isArray(cat) ? cat : []
    } catch (e) {
      error.value = e.data?.message || e.message || 'No se pudieron cargar datos.'
      companies.value = []
      catalogItems.value = []
    } finally {
      loading.value = false
    }
  }
)

function close() {
  emit('close')
}

async function submit() {
  if (!props.technician?.id) return
  error.value = ''
  saving.value = true
  try {
    const payload = {
      technician_user_id: props.technician.id,
      company_id: Number(companyId.value),
    }
    if (catalogId.value) {
      payload.catalog_id = Number(catalogId.value)
    }
    const created = await assignServiceToTechnician(payload)
    emit('assigned', created)
    emit('close')
  } catch (e) {
    if (e.data?.errors) {
      const errs = e.data.errors
      const first = Object.keys(errs)[0]
      error.value = first ? `${first}: ${errs[first]?.[0] || ''}` : e.data?.message || 'Error de validación.'
    } else {
      error.value = e.data?.message || e.message || 'No se pudo asignar.'
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && technician"
      class="modal-backdrop assign-svc-backdrop"
      role="presentation"
      @click.self="close"
    >
      <div class="modal card assign-svc-modal" role="dialog" aria-modal="true" aria-labelledby="assign-svc-title">
        <div class="modal-header">
          <h2 id="assign-svc-title" class="modal-title">Asignar servicio a {{ technician.nombre }}</h2>
          <button type="button" class="modal-close" aria-label="Cerrar" @click="close">×</button>
        </div>
        <p class="muted assign-svc-lede">
          Se creará un servicio pendiente: el técnico debe completar líneas e importes o puede rechazar la asignación.
        </p>
        <p v-if="error" class="banner err">{{ error }}</p>
        <div v-if="loading" class="muted pad">Cargando…</div>
        <template v-else>
          <label class="field">
            <span>Empresa <span class="req">*</span></span>
            <select v-model="companyId" class="input" required>
              <option disabled value="">Seleccionar…</option>
              <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
            </select>
          </label>

          <label class="field">
            <span>Ítem de catálogo (opcional)</span>
            <select v-model="catalogId" class="input">
              <option value="">Sin ítem de catálogo</option>
              <option v-for="c in catalogItems" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
          </label>

          <div class="assign-svc-footer">
            <button type="button" class="btn secondary" :disabled="saving" @click="close">Cancelar</button>
            <button type="button" class="btn primary" :disabled="saving || !companyId" @click="submit">
              {{ saving ? 'Asignando…' : 'Asignar' }}
            </button>
          </div>
        </template>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.assign-svc-backdrop {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(2, 6, 23, 0.78);
  backdrop-filter: blur(6px);
}

.assign-svc-modal {
  width: 100%;
  max-width: 26rem;
  max-height: 90vh;
  overflow: auto;
  padding: 1.35rem 1.4rem;
  background: #0f172a;
  color: #e2e8f0;
  border: 1px solid rgba(148, 163, 184, 0.28);
  border-radius: 14px;
  box-shadow:
    0 0 0 1px rgba(15, 23, 42, 0.9),
    0 24px 48px -12px rgba(0, 0, 0, 0.65);
}

.modal-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.5rem;
}

.modal-title {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 700;
  color: #f8fafc;
  line-height: 1.35;
}

.modal-close {
  flex-shrink: 0;
  width: 2rem;
  height: 2rem;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: 8px;
  background: rgba(51, 65, 85, 0.55);
  color: #cbd5e1;
  font-size: 1.35rem;
  line-height: 1;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease;
}

.modal-close:hover {
  background: rgba(71, 85, 105, 0.85);
  color: #f1f5f9;
}

.assign-svc-lede {
  font-size: 0.875rem;
  margin: 0 0 1rem;
  color: #cbd5e1;
  line-height: 1.45;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-bottom: 0.85rem;
  font-size: 0.8rem;
  color: #cbd5e1;
}

.req {
  color: #f87171;
}

.input {
  border-radius: 0.65rem;
  border: 1px solid rgba(100, 116, 139, 0.45);
  background: #020617;
  color: #f8fafc;
  padding: 0.55rem 0.75rem;
  font-size: 0.95rem;
}

.input:focus {
  outline: none;
  border-color: rgba(56, 189, 248, 0.65);
  box-shadow: 0 0 0 2px rgba(14, 165, 233, 0.2);
}

.banner.err {
  padding: 0.6rem 0.75rem;
  border-radius: 0.5rem;
  background: rgba(127, 29, 29, 0.45);
  border: 1px solid rgba(248, 113, 113, 0.55);
  color: #fecaca;
  margin-bottom: 0.75rem;
  font-size: 0.85rem;
}

.assign-svc-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 1rem;
  padding-top: 0.85rem;
  border-top: 1px solid rgba(71, 85, 105, 0.65);
}

.btn {
  border-radius: 0.65rem;
  padding: 0.5rem 1rem;
  font-weight: 600;
  cursor: pointer;
  border: none;
  font-size: 0.9rem;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn.secondary {
  background: #334155;
  color: #f1f5f9;
  border: 1px solid rgba(148, 163, 184, 0.25);
}

.btn.primary {
  background: #0ea5e9;
  color: #0c1222;
}

.muted {
  color: #94a3b8;
}

.pad {
  padding: 0.75rem 0;
}
</style>
