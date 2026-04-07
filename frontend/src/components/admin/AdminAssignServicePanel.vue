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
const useQuick = ref(false)
const qcNombre = ref('')
const qcTel = ref('')
const catalogId = ref('')
const clientName = ref('')
const loading = ref(false)
const saving = ref(false)
const error = ref('')

watch(
  () => props.open,
  async (o) => {
    if (!o) return
    error.value = ''
    companyId.value = ''
    useQuick.value = false
    qcNombre.value = ''
    qcTel.value = ''
    catalogId.value = ''
    clientName.value = ''
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
      catalog_id: Number(catalogId.value),
    }
    if (useQuick.value) {
      payload.quick_client = {
        nombre: qcNombre.value.trim(),
        telefono: qcTel.value.trim(),
      }
    } else {
      payload.company_id = Number(companyId.value)
      const cn = clientName.value.trim()
      if (cn) payload.client_name = cn
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
            <span>Ítem de catálogo (obligatorio)</span>
            <select v-model="catalogId" class="input" required>
              <option disabled value="">Seleccionar…</option>
              <option v-for="c in catalogItems" :key="c.id" :value="String(c.id)">{{ c.name }}</option>
            </select>
          </label>

          <label class="field check">
            <input v-model="useQuick" type="checkbox" />
            <span>Cliente puntual (sin empresa registrada)</span>
          </label>

          <template v-if="!useQuick">
            <label class="field">
              <span>Empresa</span>
              <select v-model="companyId" class="input" required>
                <option disabled value="">Seleccionar…</option>
                <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
              </select>
            </label>
            <label class="field">
              <span>Cliente atendido (opcional; por defecto el nombre de la empresa)</span>
              <input v-model="clientName" type="text" class="input" placeholder="Contacto en obra" />
            </label>
          </template>
          <template v-else>
            <label class="field">
              <span>Nombre cliente</span>
              <input v-model="qcNombre" type="text" class="input" required />
            </label>
            <label class="field">
              <span>Teléfono</span>
              <input v-model="qcTel" type="tel" class="input" inputmode="tel" required />
            </label>
          </template>

          <div class="assign-svc-footer">
            <button type="button" class="btn secondary" :disabled="saving" @click="close">Cancelar</button>
            <button
              type="button"
              class="btn primary"
              :disabled="
                saving ||
                !catalogId ||
                (!useQuick && !companyId) ||
                (useQuick && (!qcNombre.trim() || !qcTel.trim()))
              "
              @click="submit"
            >
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
  background: rgba(0, 0, 0, 0.55);
}
.assign-svc-modal {
  width: 100%;
  max-width: 26rem;
  max-height: 90vh;
  overflow: auto;
  padding: 1.25rem 1.35rem;
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
}
.modal-close {
  border: none;
  background: transparent;
  color: #94a3b8;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
}
.assign-svc-lede {
  font-size: 0.85rem;
  margin: 0 0 1rem;
}
.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-bottom: 0.85rem;
  font-size: 0.8rem;
  color: #94a3b8;
}
.field.check {
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
}
.input {
  border-radius: 0.65rem;
  border: 1px solid rgba(51, 65, 85, 0.9);
  background: #0f172a;
  color: #f1f5f9;
  padding: 0.55rem 0.75rem;
  font-size: 0.95rem;
}
.banner.err {
  padding: 0.5rem 0.65rem;
  border-radius: 0.5rem;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 0.75rem;
  font-size: 0.85rem;
}
.assign-svc-footer {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
  margin-top: 1rem;
  padding-top: 0.75rem;
  border-top: 1px solid rgba(51, 65, 85, 0.6);
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
  color: #e2e8f0;
}
.btn.primary {
  background: #0ea5e9;
  color: #0f172a;
}
.muted {
  color: #64748b;
}
.pad {
  padding: 0.75rem 0;
}
</style>
