<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import ServiceCorrectionFields from '@/components/services/ServiceCorrectionFields.vue'
import { fetchService, fetchServiceCatalogActive, updateService } from '@/services/servicesApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const uiDialog = useUiDialogStore()

const service = ref(null)
const loading = ref(true)
const saving = ref(false)
const fieldErrors = ref({})
const globalError = ref('')

const catalogItems = ref([])

const form = ref({
  catalog_id: '',
  client_name: '',
  service_type: '',
  description: '',
  amount: '',
})

const isAdmin = computed(() => isAdminPanelRole(auth.user?.rol))

const detailPath = computed(() =>
  isAdmin.value ? `/admin/servicios/${route.params.id}` : `/empleado/servicio/${route.params.id}`
)

const canEdit = computed(
  () => service.value && !service.value.invoiced && service.value.status !== 'eliminado'
)

function money(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

function formatDateLong(iso) {
  if (!iso) return '—'
  const d = new Date(iso + (iso.length <= 10 ? 'T12:00:00' : ''))
  if (Number.isNaN(d.getTime())) return iso
  return new Intl.DateTimeFormat('es-CO', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(d)
}

function syncForm() {
  const s = service.value
  if (!s) return
  form.value = {
    catalog_id: s.catalog_id != null ? s.catalog_id : '',
    client_name: s.client_name || '',
    service_type: s.service_type || '',
    description: s.description || '',
    amount: s.amount != null ? String(s.amount) : '',
  }
}

watch(service, syncForm, { immediate: true })

function validateLocal() {
  const f = form.value
  const errs = {}
  if (!(f.client_name || '').trim()) errs.client_name = ['El nombre del cliente es obligatorio.']
  if (!(f.service_type || '').trim()) errs.service_type = ['El tipo de servicio es obligatorio.']
  if (!(f.description || '').trim() || (f.description || '').trim().length < 8) {
    errs.description = ['La descripción debe tener al menos 8 caracteres.']
  }
  const amt = Number(f.amount)
  if (Number.isNaN(amt) || amt < 0.01) errs.amount = ['Indique un valor numérico mayor a cero.']
  return errs
}

onMounted(async () => {
  try {
    service.value = await fetchService(route.params.id)
    const cid = service.value?.company_id
    catalogItems.value = cid ? await fetchServiceCatalogActive(cid) : []
  } catch (e) {
    globalError.value = e.data?.message || e.message || 'No se pudo cargar.'
    catalogItems.value = []
  } finally {
    loading.value = false
  }
})

async function onSubmit() {
  fieldErrors.value = {}
  globalError.value = ''
  const local = validateLocal()
  if (Object.keys(local).length) {
    fieldErrors.value = local
    return
  }
  const f = form.value
  const msg = isAdmin.value
    ? '¿Confirmar los cambios? Estos datos afectan la facturación y lo que verá el cliente.'
    : '¿Guardar los cambios en tu servicio?'
  const ok = await uiDialog.confirm({ title: 'Guardar cambios', message: msg })
  if (!ok) return

  saving.value = true
  try {
    const payload = {
      client_name: f.client_name.trim(),
      service_type: f.service_type.trim(),
      description: f.description.trim(),
      amount: Number(f.amount),
    }
    if (f.catalog_id !== '' && f.catalog_id != null) {
      payload.catalog_id = Number(f.catalog_id)
    } else {
      payload.catalog_id = null
    }
    await updateService(route.params.id, payload)
    await uiDialog.alert({ title: 'Listo', message: 'Cambios guardados.' })
    await router.push(detailPath.value)
  } catch (e) {
    if (e.data?.errors) fieldErrors.value = e.data.errors
    else globalError.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <section class="page">
    <header class="head">
      <div>
        <RouterLink class="back" :to="detailPath">← Volver al detalle</RouterLink>
        <h1>{{ isAdmin ? 'Editar servicio (administración)' : 'Editar mi servicio' }}</h1>
        <p class="lede">
          {{
            isAdmin
              ? 'Cliente, tipo, descripción y valor. Código, fecha, empresa y técnico no se alteran.'
              : 'Puedes corregir cliente, tipo, descripción y valor si te equivocaste al registrar. No debe estar facturado.'
          }}
        </p>
      </div>
    </header>

    <p v-if="loading" class="muted">Cargando…</p>
    <template v-else-if="service">
      <p v-if="service.invoiced" class="banner banner-warn">
        Este servicio ya está en una factura. No es editable.
        <RouterLink class="link" :to="detailPath">Ir al detalle</RouterLink>
      </p>
      <p v-else-if="service.status === 'eliminado'" class="banner banner-warn">
        Servicio eliminado; no admite edición.
      </p>
      <p v-if="globalError" class="banner banner-err">{{ globalError }}</p>

      <article class="card ref-block">
        <h2 class="h2">Referencia (solo lectura)</h2>
        <dl class="ref-grid">
          <div>
            <dt>Código</dt>
            <dd class="mono">{{ service.code }}</dd>
          </div>
          <div>
            <dt>Fecha</dt>
            <dd>{{ formatDateLong(service.service_date) }}</dd>
          </div>
          <div class="wide">
            <dt>Empresa</dt>
            <dd>{{ service.company?.nombre || '—' }}</dd>
          </div>
          <div class="wide">
            <dt>Técnico</dt>
            <dd>{{ service.empleado?.nombre || '—' }}</dd>
          </div>
          <div>
            <dt>Valor actual</dt>
            <dd>{{ money(service.amount) }}</dd>
          </div>
        </dl>
      </article>

      <form v-if="canEdit" class="card form-card" @submit.prevent="onSubmit">
        <h2 class="h2">Datos editables</h2>
        <ServiceCorrectionFields
          v-model="form"
          :catalog-items="catalogItems"
          :field-errors="fieldErrors"
          :disabled="saving"
        />
        <div class="actions">
          <RouterLink class="btn secondary" :to="detailPath">Cancelar</RouterLink>
          <button class="btn primary" type="submit" :disabled="saving">
            {{ saving ? 'Guardando…' : 'Guardar cambios' }}
          </button>
        </div>
      </form>
    </template>
  </section>
</template>

<style scoped>
.page {
  max-width: 920px;
  margin: 0 auto;
}

.head {
  margin-bottom: 1.25rem;
}

.back {
  display: inline-block;
  color: #94a3b8;
  text-decoration: none;
  font-size: 0.9rem;
  margin-bottom: 0.35rem;
}

h1 {
  margin: 0 0 0.35rem;
  font-size: 1.25rem;
}

.lede {
  margin: 0;
  max-width: 40rem;
  font-size: 0.88rem;
  color: #94a3b8;
}

.h2 {
  margin: 0 0 1rem;
  font-size: 1rem;
  color: #e2e8f0;
}

.card {
  padding: 1.25rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 1rem;
}

.ref-block {
  margin-bottom: 1rem;
}

.ref-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 1rem;
}

.ref-grid .wide {
  grid-column: 1 / -1;
}

dt {
  font-size: 0.78rem;
  color: #94a3b8;
  margin-bottom: 0.2rem;
}

dd {
  margin: 0;
  color: #f1f5f9;
}

.mono {
  font-family: ui-monospace, monospace;
}

.banner {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  margin-bottom: 1rem;
}

.banner-err {
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
}

.banner-warn {
  background: rgba(251, 191, 36, 0.1);
  border: 1px solid rgba(251, 191, 36, 0.35);
  color: #fde68a;
}

.link {
  margin-left: 0.5rem;
  color: #7dd3fc;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.65rem;
  margin-top: 1.25rem;
}

.muted {
  color: #94a3b8;
}

.btn {
  display: inline-flex;
  padding: 0.55rem 1rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: transparent;
  color: #e2e8f0;
  text-decoration: none;
  align-items: center;
}

.btn.primary {
  background: linear-gradient(90deg, #2563eb, #7c3aed);
  border: none;
  color: #fff;
}

.btn.primary:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}
</style>
