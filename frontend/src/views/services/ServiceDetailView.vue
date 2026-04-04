<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import { archiveService, fetchService, updateService } from '@/services/servicesApi.js'
import ServiceCorrectionFields from '@/components/services/ServiceCorrectionFields.vue'
import { useUiDialogStore } from '@/stores/uiDialog'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const uiDialog = useUiDialogStore()

const service = ref(null)
const loading = ref(true)
const error = ref('')
const saving = ref(false)
const fieldErrors = ref({})
const saveError = ref('')

const editForm = ref({
  client_name: '',
  service_type: '',
  description: '',
  amount: '',
})

const base = computed(() => (isAdminPanelRole(auth.user?.rol) ? '/admin' : '/empleado'))
const isAdmin = computed(() => isAdminPanelRole(auth.user?.rol))
const backHref = computed(() =>
  isAdmin.value ? `${base.value}/servicios` : `${base.value}/listado-servicios`
)

const canAdminEdit = computed(
  () =>
    isAdmin.value &&
    service.value &&
    !service.value.invoiced &&
    service.value.status !== 'eliminado'
)

const canEmpleadoManage = computed(
  () =>
    !isAdmin.value &&
    service.value &&
    !service.value.invoiced &&
    service.value.status !== 'eliminado'
)

const statusLabel = computed(() => {
  const s = service.value?.status
  if (s === 'activo') return 'Activo'
  if (s === 'corregido') return 'Corregido'
  if (s === 'eliminado') return 'Eliminado'
  return s || '—'
})

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

function syncEditFormFromService() {
  const s = service.value
  if (!s) return
  editForm.value = {
    client_name: s.client_name || '',
    service_type: s.service_type || '',
    description: s.description || '',
    amount: s.amount != null ? String(s.amount) : '',
  }
}

watch(service, syncEditFormFromService, { immediate: true })

const summaryAmountDisplay = computed(() => {
  if (canAdminEdit.value) {
    const n = Number(editForm.value.amount)
    return Number.isNaN(n) ? null : n
  }
  const a = service.value?.amount
  return a != null ? Number(a) : null
})

function validateLocal() {
  const f = editForm.value
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
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar el servicio.'
  } finally {
    loading.value = false
  }
})

async function onSaveCorrections() {
  saveError.value = ''
  fieldErrors.value = {}
  const local = validateLocal()
  if (Object.keys(local).length) {
    fieldErrors.value = local
    return
  }
  const f = editForm.value
  const msg =
    '¿Confirmar los cambios en cliente, tipo, descripción y valor?\n\n' +
    'Estos datos afectan la facturación y lo que verá el cliente.'
  const ok = await uiDialog.confirm({ title: 'Guardar correcciones', message: msg })
  if (!ok) return

  saving.value = true
  try {
    service.value = await updateService(route.params.id, {
      client_name: f.client_name.trim(),
      service_type: f.service_type.trim(),
      description: f.description.trim(),
      amount: Number(f.amount),
    })
    await uiDialog.alert({ title: 'Listo', message: 'Cambios guardados correctamente.' })
  } catch (e) {
    if (e.data?.errors) fieldErrors.value = e.data.errors
    else saveError.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}

async function onEmpleadoArchive() {
  const s = service.value
  if (!s || s.invoiced || s.status === 'eliminado') return
  const typed = await uiDialog.prompt({
    title: 'Confirmar eliminación',
    message: `Para eliminar este registro, escribe exactamente el código del servicio (${s.code}):`,
    placeholder: s.code,
    danger: true,
    confirmLabel: 'Eliminar',
  })
  if (typed?.trim() !== s.code) {
    if (typed != null && typed.trim() !== '') {
      await uiDialog.alert({ title: 'Código incorrecto', message: 'El código no coincide.' })
    }
    return
  }
  try {
    await archiveService(s.id)
    await uiDialog.alert({ title: 'Listo', message: 'Servicio marcado como eliminado.' })
    await router.push(`${base.value}/listado-servicios`)
  } catch (e) {
    await uiDialog.alert({
      title: 'Error',
      message: e.data?.message || e.message || 'No se pudo eliminar.',
    })
  }
}
</script>

<template>
  <section class="page">
    <header class="head">
      <div>
        <RouterLink class="back" :to="backHref">← Volver al listado</RouterLink>
        <h1>{{ isAdmin ? 'Detalle y validación del servicio' : 'Detalle de servicio' }}</h1>
        <p v-if="isAdmin" class="lede">
          Revise la información de referencia y corrija solo los datos permitidos antes de facturar.
        </p>
      </div>
      <div v-if="service && isAdmin && canAdminEdit" class="actions">
        <RouterLink class="btn secondary" :to="`${base}/servicios/${service.id}/editar`">
          Editar en vista ampliada
        </RouterLink>
      </div>
      <div v-else-if="service && canEmpleadoManage" class="actions actions-emp">
        <RouterLink class="btn primary" :to="`${base}/servicio/${service.id}/editar`">Editar</RouterLink>
        <button type="button" class="btn danger" @click="onEmpleadoArchive">Eliminar</button>
      </div>
    </header>

    <p v-if="loading" class="muted">Cargando…</p>
    <p v-else-if="error" class="banner banner-err">{{ error }}</p>

    <div v-else-if="service" class="layout" :class="{ admin: isAdmin }">
      <!-- Empleado: vista simple -->
      <template v-if="!isAdmin">
        <div class="card">
          <dl class="grid">
            <div>
              <dt>Código</dt>
              <dd>{{ service.code }}</dd>
            </div>
            <div>
              <dt>Estado</dt>
              <dd class="cap">{{ service.status }}</dd>
            </div>
            <div>
              <dt>Fecha del servicio</dt>
              <dd>{{ service.service_date }}</dd>
            </div>
            <div>
              <dt>Valor</dt>
              <dd>{{ money(service.amount) }}</dd>
            </div>
            <div class="wide">
              <dt>Empresa</dt>
              <dd>
                {{ service.company?.nombre || '—' }}
                <span v-if="service.company?.nit" class="muted">· NIT {{ service.company.nit }}</span>
              </dd>
            </div>
            <div class="wide">
              <dt>Cliente atendido</dt>
              <dd>{{ service.client_name || '—' }}</dd>
            </div>
            <div class="wide">
              <dt>Tipo de servicio</dt>
              <dd>{{ service.service_type || '—' }}</dd>
            </div>
            <div v-if="service.catalog?.name" class="wide">
              <dt>Referencia catálogo</dt>
              <dd>{{ service.catalog.name }}</dd>
            </div>
            <div class="wide">
              <dt>Descripción</dt>
              <dd class="pre">{{ service.description }}</dd>
            </div>
            <div v-if="service.photos?.length" class="wide">
              <dt>Fotos</dt>
              <dd class="photo-grid">
                <a
                  v-for="p in service.photos"
                  :key="p.id"
                  :href="p.url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="photo-thumb"
                >
                  <img :src="p.url" alt="Evidencia del servicio" loading="lazy" />
                </a>
              </dd>
            </div>
          </dl>
        </div>
      </template>

      <!-- Admin: bloques A / B + panel lateral -->
      <template v-else>
        <p v-if="service.invoiced" class="banner banner-warn">
          Este servicio ya está asociado a una factura. La edición está bloqueada para evitar
          inconsistencias con documentos emitidos.
        </p>
        <p v-else-if="service.status === 'eliminado'" class="banner banner-warn">
          Servicio marcado como eliminado; no admite correcciones.
        </p>

        <div class="admin-main">
          <article class="card block">
            <h2 class="block-title">A. Información general <span class="tag">Solo lectura</span></h2>
            <p class="block-hint">Identificación del trabajo; no debe alterarse en correcciones.</p>
            <dl class="ref-grid">
              <div>
                <dt>Código del servicio</dt>
                <dd class="mono">{{ service.code }}</dd>
              </div>
              <div>
                <dt>Fecha del servicio</dt>
                <dd>{{ formatDateLong(service.service_date) }}</dd>
              </div>
              <div class="wide">
                <dt>Empresa</dt>
                <dd>
                  {{ service.company?.nombre || '—' }}
                  <span v-if="service.company?.nit" class="muted">· NIT {{ service.company.nit }}</span>
                </dd>
              </div>
              <div class="wide">
                <dt>Técnico (empleado)</dt>
                <dd>
                  {{ service.empleado?.nombre || '—' }}
                  <span v-if="service.empleado?.correo" class="muted"> · {{ service.empleado.correo }}</span>
                </dd>
              </div>
              <div>
                <dt>Estado del registro</dt>
                <dd><span class="pill">{{ statusLabel }}</span></dd>
              </div>
            </dl>
          </article>

          <article class="card block">
            <h2 class="block-title">B. Información corregible</h2>
            <p class="block-hint">
              Ajuste cliente, tipo, descripción y valor si el registro del empleado tenía errores. Estos campos
              impactan la factura y la comunicación al cliente.
            </p>
            <p v-if="saveError" class="banner banner-err inline">{{ saveError }}</p>
            <ServiceCorrectionFields
              v-model="editForm"
              :field-errors="fieldErrors"
              :disabled="saving"
              :readonly="!canAdminEdit"
            />
            <div v-if="canAdminEdit" class="save-row">
              <button type="button" class="btn primary" :disabled="saving" @click="onSaveCorrections">
                {{ saving ? 'Guardando…' : 'Guardar correcciones' }}
              </button>
            </div>
          </article>

          <article v-if="service.photos?.length" class="card block">
            <h2 class="block-title">Evidencias</h2>
            <div class="photo-grid">
              <a
                v-for="p in service.photos"
                :key="p.id"
                :href="p.url"
                target="_blank"
                rel="noopener noreferrer"
                class="photo-thumb"
              >
                <img :src="p.url" alt="Evidencia del servicio" loading="lazy" />
              </a>
            </div>
          </article>

          <article class="card block muted-block">
            <h2 class="block-title">C. Control de cambios</h2>
            <dl class="ref-grid">
              <div>
                <dt>Registro creado</dt>
                <dd>{{ service.created_at ? new Date(service.created_at).toLocaleString('es-CO') : '—' }}</dd>
              </div>
              <div>
                <dt>Última actualización</dt>
                <dd>{{ service.updated_at ? new Date(service.updated_at).toLocaleString('es-CO') : '—' }}</dd>
              </div>
            </dl>
            <p class="fineprint">
              El código y el técnico asignado no son editables aquí para preservar el historial operativo.
            </p>
          </article>
        </div>

        <aside class="aside" aria-label="Resumen del servicio">
          <div class="card aside-card">
            <h2 class="aside-title">Resumen</h2>
            <p class="aside-code">{{ service.code }}</p>
            <p class="aside-line">{{ formatDateLong(service.service_date) }}</p>
            <p class="aside-line">
              <span class="pill" :class="{ ok: service.status === 'activo' || service.status === 'corregido' }">
                {{ statusLabel }}
              </span>
            </p>
            <p class="aside-amount">
              {{ summaryAmountDisplay != null ? money(summaryAmountDisplay) : '—' }}
            </p>
            <p class="aside-company">{{ service.company?.nombre || '—' }}</p>
          </div>
        </aside>
      </template>
    </div>
  </section>
</template>

<style scoped>
.page {
  max-width: 1100px;
  margin: 0 auto;
  padding: 0 0.5rem;
}

.head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 1rem;
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
  font-size: 1.35rem;
}

.lede {
  margin: 0;
  max-width: 36rem;
  font-size: 0.9rem;
  color: #94a3b8;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: flex-start;
}

.layout.admin {
  display: grid;
  gap: 1.25rem;
}

@media (min-width: 1024px) {
  .layout.admin {
    grid-template-columns: minmax(0, 1fr) 300px;
    align-items: start;
  }

  .aside {
    position: sticky;
    top: 1rem;
  }
}

.admin-main {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;
  min-width: 0;
}

.card {
  padding: 1.25rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
}

.aside-card {
  background: linear-gradient(145deg, rgba(30, 41, 59, 0.9), rgba(15, 23, 42, 0.95));
  border-color: rgba(56, 189, 248, 0.2);
}

.aside-title {
  margin: 0 0 0.75rem;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #94a3b8;
}

.aside-code {
  margin: 0;
  font-size: 1.1rem;
  font-weight: 700;
  color: #f8fafc;
  font-family: ui-monospace, monospace;
}

.aside-line {
  margin: 0.5rem 0 0;
  font-size: 0.88rem;
  color: #cbd5e1;
}

.aside-amount {
  margin: 1rem 0 0;
  font-size: 1.5rem;
  font-weight: 700;
  color: #38bdf8;
}

.aside-company {
  margin: 0.65rem 0 0;
  font-size: 0.85rem;
  color: #94a3b8;
}

.block-title {
  margin: 0 0 0.35rem;
  font-size: 1.05rem;
  color: #f1f5f9;
}

.tag {
  display: inline-block;
  margin-left: 0.35rem;
  padding: 0.12rem 0.45rem;
  border-radius: 6px;
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  background: rgba(148, 163, 184, 0.2);
  color: #94a3b8;
  vertical-align: middle;
}

.block-hint {
  margin: 0 0 1rem;
  font-size: 0.85rem;
  color: #94a3b8;
}

.block {
  margin: 0;
}

.ref-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
  font-size: 0.95rem;
}

.pill {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 600;
  background: rgba(148, 163, 184, 0.2);
  color: #e2e8f0;
}

.pill.ok {
  background: rgba(34, 197, 94, 0.2);
  color: #86efac;
}

.save-row {
  margin-top: 1.25rem;
  display: flex;
  justify-content: flex-end;
}

.muted-block {
  border-style: dashed;
  opacity: 0.95;
}

.fineprint {
  margin: 0.75rem 0 0;
  font-size: 0.78rem;
  color: #64748b;
}

.banner {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  margin-bottom: 1rem;
}

.banner.inline {
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

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 1rem;
}

.grid .wide {
  grid-column: 1 / -1;
}

.pre {
  white-space: pre-wrap;
}

.cap {
  text-transform: capitalize;
}

.muted {
  color: #94a3b8;
}

.btn {
  display: inline-flex;
  padding: 0.5rem 0.85rem;
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

.btn.secondary {
  border-color: rgba(148, 163, 184, 0.45);
}

.btn.danger {
  border-color: rgba(248, 113, 113, 0.55);
  background: rgba(127, 29, 29, 0.35);
  color: #fecaca;
}

.btn.danger:hover {
  background: rgba(153, 27, 27, 0.45);
}

.actions-emp {
  align-items: center;
}

.photo-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
}

.photo-thumb {
  display: block;
  width: 120px;
  height: 120px;
  border-radius: 10px;
  overflow: hidden;
  border: 1px solid rgba(148, 163, 184, 0.25);
}

.photo-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
</style>
