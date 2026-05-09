<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import { archiveService, fetchService, rejectServiceAssignment, updateService } from '@/services/servicesApi.js'
import { createInvoice } from '@/services/invoicesApi.js'
import ServiceCorrectionFields from '@/components/services/ServiceCorrectionFields.vue'
import { useUiDialogStore } from '@/stores/uiDialog'
import { moneyCOPIntegerOrDash as money } from '@/utils/moneyFormatCo.js'
import {
  formatDateLongEsCo as formatDateLong,
  lineBilledAmount,
  lineCompanyMargin,
  lineTechnicianAmount,
  numMoneyBase,
  serviceInventoryAssetDetailTo,
} from './serviceDetailHelpers.js'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const uiDialog = useUiDialogStore()

const service = ref(null)
const loading = ref(true)
const error = ref('')
const saving = ref(false)
const creatingMaintenanceInvoice = ref(false)
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

const isAwaitingAssignment = computed(
  () => !isAdmin.value && service.value?.assignment_status === 'awaiting_completion'
)

const canEmpleadoEditActions = computed(
  () => canEmpleadoManage.value && !isAwaitingAssignment.value
)

const statusLabel = computed(() => {
  const s = service.value?.status
  if (s === 'activo') return 'Activo'
  if (s === 'corregido') return 'Corregido'
  if (s === 'eliminado') return 'Eliminado'
  return s || '—'
})

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

/** Líneas del servicio (payload multilínea). */
const serviceItems = computed(() =>
  Array.isArray(service.value?.items) ? service.value.items : []
)

const isMaintenanceService = computed(() => String(service.value?.kind || '') === 'mantenimiento')

/** Enlace a hoja de vida del equipo vinculado (custodia incluye query para técnico). */
const inventoryAssetDetailTo = computed(() => serviceInventoryAssetDetailTo(service.value, isAdmin.value))

const showLiquidacionBlock = computed(
  () => isAdmin.value && service.value != null && serviceItems.value.length > 0
)

/** Totales guardados en servidor (conciliación técnico vs factura). */
const storedBilledTotal = computed(() => numMoneyBase(service.value?.amount))

const storedTechnicianTotal = computed(() => {
  const raw = service.value?.technician_line_total
  if (raw == null || raw === '') return null
  const n = Number(raw)
  return Number.isNaN(n) ? null : n
})

const companyMarginTotal = computed(() => {
  if (storedTechnicianTotal.value == null) return null
  return storedBilledTotal.value - storedTechnicianTotal.value
})

/** Periodo contable del servicio (mes de `service_date`), alineado con facturación backend. */
const maintenanceInvoicePeriod = computed(() => {
  const iso = service.value?.service_date
  if (!iso || typeof iso !== 'string') return null
  const m = /^(\d{4})-(\d{2})-\d{2}$/.exec(iso.trim())
  if (!m) return null
  return { year: Number(m[1]), month: Number(m[2]) }
})

const hasUnsavedCorrections = computed(() => {
  if (!canAdminEdit.value || !service.value) return false
  const s = service.value
  const f = editForm.value
  const norm = (v) => String(v ?? '').trim()
  const draftAmt = Number(f.amount)
  const savedAmt = numMoneyBase(s.amount)
  const amtDiff = Number.isNaN(draftAmt) || Math.abs(draftAmt - savedAmt) > 0.009
  return (
    norm(f.client_name) !== norm(s.client_name) ||
    norm(f.service_type) !== norm(s.service_type) ||
    norm(f.description) !== norm(s.description) ||
    amtDiff
  )
})

const canCreateMaintenanceInvoiceDraft = computed(() => {
  if (!isAdmin.value || !isMaintenanceService.value || !service.value) return false
  const s = service.value
  if (s.invoiced || (s.status !== 'activo' && s.status !== 'corregido')) return false
  if (!s.company_id || !maintenanceInvoicePeriod.value) return false
  if (storedBilledTotal.value < 0.01) return false
  if (hasUnsavedCorrections.value) return false
  return true
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

async function onRejectAssignment() {
  const s = service.value
  if (!s || s.assignment_status !== 'awaiting_completion') return
  const ok = await uiDialog.confirm({
    title: 'Rechazar asignación',
    message:
      'Se marcará el servicio como eliminado y se avisará a administración. ¿Continuar?',
    confirmLabel: 'Rechazar',
    cancelLabel: 'Cancelar',
    danger: true,
  })
  if (!ok) return
  try {
    service.value = await rejectServiceAssignment(s.id)
    await uiDialog.alert({ title: 'Listo', message: 'Asignación rechazada.' })
    await router.push(`${base.value}/listado-servicios`)
  } catch (e) {
    await uiDialog.alert({
      title: 'Error',
      message: e.data?.message || e.message || 'No se pudo rechazar.',
    })
  }
}

async function onCreateMaintenanceInvoiceDraft() {
  const s = service.value
  const per = maintenanceInvoicePeriod.value
  if (!s || !per || !canCreateMaintenanceInvoiceDraft.value) return
  const ok = await uiDialog.confirm({
    title: 'Crear borrador de factura',
    message:
      `Se creará un borrador del periodo ${per.month}/${per.year} incluyendo solo el mantenimiento ${s.code}. ` +
      'Podrá revisarlo y añadir más servicios en Facturas si aplica.',
    confirmLabel: 'Crear borrador',
    cancelLabel: 'Cancelar',
  })
  if (!ok) return
  creatingMaintenanceInvoice.value = true
  try {
    const inv = await createInvoice({
      company_id: s.company_id,
      period_year: per.year,
      period_month: per.month,
      service_ids: [s.id],
    })
    await router.push({ name: 'admin-factura-detalle', params: { id: String(inv.id) } })
  } catch (e) {
    const parts = []
    if (e.data?.errors && typeof e.data.errors === 'object') {
      for (const v of Object.values(e.data.errors)) {
        if (Array.isArray(v)) parts.push(...v)
        else if (v != null) parts.push(String(v))
      }
    }
    const msg = parts.length ? parts.join('\n') : e.message || 'No se pudo crear el borrador.'
    await uiDialog.alert({ title: 'No se pudo crear el borrador', message: msg })
  } finally {
    creatingMaintenanceInvoice.value = false
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
      <div v-else-if="service && canEmpleadoManage && isAwaitingAssignment" class="actions actions-emp actions-emp--stack">
        <RouterLink class="btn primary" :to="`/empleado/servicio/${service.id}/completar-asignacion`">
          Completar asignación
        </RouterLink>
        <button type="button" class="btn danger" @click="onRejectAssignment">Rechazar</button>
      </div>
      <div v-else-if="service && canEmpleadoEditActions" class="actions actions-emp">
        <RouterLink class="btn primary" :to="`${base}/servicio/${service.id}/editar`">Editar</RouterLink>
        <button type="button" class="btn danger" @click="onEmpleadoArchive">Eliminar</button>
      </div>
    </header>

    <p v-if="loading" class="muted">Cargando…</p>
    <p v-else-if="error" class="banner banner-err">{{ error }}</p>

    <div v-else-if="service" class="layout" :class="{ admin: isAdmin }">
      <!-- Empleado: vista simple -->
      <template v-if="!isAdmin">
        <div v-if="isAwaitingAssignment" class="card banner-assign">
          <p class="banner-assign-title">Asignación pendiente</p>
          <p class="banner-assign-text">
            Complete líneas e importes de referencia o rechace la asignación. Los datos de empresa quedan fijados por
            administración.
            <span v-if="service.assigned_by?.nombre" class="muted">
              Asignado por {{ service.assigned_by.nombre }}.
            </span>
          </p>
        </div>
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
              <dt>Valor (su referencia)</dt>
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
            <div v-if="isMaintenanceService && service.inventory_lot" class="wide">
              <dt>Equipo (inventario)</dt>
              <dd>
                <RouterLink v-if="inventoryAssetDetailTo" class="link-inv" :to="inventoryAssetDetailTo">
                  {{ service.inventory_lot.name || 'Activo' }}
                  <span v-if="service.inventory_lot.internal_code" class="muted">
                    · {{ service.inventory_lot.internal_code }}
                  </span>
                </RouterLink>
                <template v-else>
                  {{ service.inventory_lot.name || '—' }}
                  <span v-if="service.inventory_lot.internal_code" class="muted">
                    · {{ service.inventory_lot.internal_code }}
                  </span>
                </template>
              </dd>
            </div>
            <div v-if="service.catalog?.name" class="wide">
              <dt>Referencia catálogo</dt>
              <dd>{{ service.catalog.name }}</dd>
            </div>
            <div v-if="serviceItems.length" class="wide">
              <dt>Conceptos</dt>
              <dd>
                <ul class="emp-items">
                  <li v-for="it in serviceItems" :key="it.id" class="emp-item">
                    <p class="emp-item-label">{{ it.label || '—' }}</p>
                    <p class="pre emp-item-desc">{{ it.line_description || '—' }}</p>
                    <p class="emp-item-amt">{{ money(lineTechnicianAmount(it)) }}</p>
                  </li>
                </ul>
              </dd>
            </div>
            <div v-else class="wide">
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
              <div v-if="isMaintenanceService && service.inventory_lot" class="wide">
                <dt>Equipo (inventario)</dt>
                <dd>
                  <RouterLink v-if="inventoryAssetDetailTo" class="link-inv" :to="inventoryAssetDetailTo">
                    {{ service.inventory_lot.name || 'Activo' }}
                    <span v-if="service.inventory_lot.internal_code" class="muted">
                      · {{ service.inventory_lot.internal_code }}
                    </span>
                  </RouterLink>
                  <template v-else>
                    {{ service.inventory_lot.name || '—' }}
                    <span v-if="service.inventory_lot.internal_code" class="muted">
                      · {{ service.inventory_lot.internal_code }}
                    </span>
                  </template>
                  <span v-if="service.inventory_lot.lifecycle_status" class="muted">
                    · Estado: {{ service.inventory_lot.lifecycle_status }}
                  </span>
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

          <article v-if="showLiquidacionBlock" class="card block">
            <h2 class="block-title">D. Facturado vs base técnico <span class="tag">Liquidación</span></h2>
            <p class="block-hint">
              <strong>Factura (cliente):</strong> importe que va en la factura a la empresa.
              <strong>Base técnico:</strong> referencia alineada con lo que manejó el técnico al registrar (sin el incremento
              del margen). La diferencia es lo que retiene la operación antes de liquidar al empleado.
            </p>
            <div class="liq-table-wrap">
              <table class="liq-table">
                <thead>
                  <tr>
                    <th scope="col">Concepto</th>
                    <th scope="col" class="num">Factura (cliente)</th>
                    <th scope="col" class="num">Base técnico</th>
                    <th scope="col" class="num">Margen empresa</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="it in serviceItems" :key="it.id">
                    <td>{{ it.label || '—' }}</td>
                    <td class="num">{{ money(lineBilledAmount(it)) }}</td>
                    <td class="num">{{ money(lineTechnicianAmount(it)) }}</td>
                    <td class="num">{{ money(lineCompanyMargin(it)) }}</td>
                  </tr>
                </tbody>
                <tfoot>
                  <tr>
                    <th scope="row">Totales</th>
                    <td class="num">{{ money(storedBilledTotal) }}</td>
                    <td class="num">{{ money(storedTechnicianTotal ?? 0) }}</td>
                    <td class="num">{{ money(companyMarginTotal ?? 0) }}</td>
                  </tr>
                </tfoot>
              </table>
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
            <p class="aside-sub muted">Valor total del servicio</p>
            <div v-if="showLiquidacionBlock" class="aside-liq">
              <p class="aside-liq-title">Liquidación técnico</p>
              <p class="aside-liq-row">
                <span class="aside-liq-k">Factura (cliente)</span>
                <span class="aside-liq-v">{{ money(storedBilledTotal) }}</span>
              </p>
              <p class="aside-liq-row">
                <span class="aside-liq-k">Base técnico</span>
                <span class="aside-liq-v">{{ money(storedTechnicianTotal ?? 0) }}</span>
              </p>
              <p
                v-if="companyMarginTotal != null && companyMarginTotal > 0.005"
                class="aside-liq-row aside-liq-margin"
              >
                <span class="aside-liq-k">Margen empresa</span>
                <span class="aside-liq-v">{{ money(companyMarginTotal) }}</span>
              </p>
              <p v-if="canAdminEdit" class="aside-liq-sync muted">
                Cifras según último guardado; usa «Guardar correcciones» en B para actualizar.
              </p>
            </div>
            <p v-else class="aside-liq-note muted">
              Sin líneas detalladas en este registro; solo hay un total único (factura = referencia guardada).
            </p>
            <p class="aside-company">{{ service.company?.nombre || '—' }}</p>
            <div v-if="isMaintenanceService" class="aside-invoice">
              <template v-if="canCreateMaintenanceInvoiceDraft">
                <button
                  type="button"
                  class="btn primary aside-invoice-btn"
                  :disabled="creatingMaintenanceInvoice"
                  @click="onCreateMaintenanceInvoiceDraft"
                >
                  {{ creatingMaintenanceInvoice ? 'Creando borrador…' : 'Crear borrador de factura' }}
                </button>
                <p class="aside-invoice-hint muted">
                  Usa el periodo de la fecha del servicio ({{ maintenanceInvoicePeriod?.month }}/{{
                    maintenanceInvoicePeriod?.year
                  }}).
                </p>
              </template>
              <template v-else-if="!service.invoiced && (service.status === 'activo' || service.status === 'corregido')">
                <p v-if="hasUnsavedCorrections" class="aside-invoice-hint muted">
                  Guarde las correcciones (bloque B) antes de generar el borrador, para que el importe coincida con lo
                  facturado.
                </p>
                <p v-else-if="storedBilledTotal < 0.01" class="aside-invoice-hint muted">
                  Defina un importe mayor a cero (bloque B) para poder incluir este mantenimiento en una factura.
                </p>
              </template>
            </div>
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

.link-inv {
  color: #7dd3fc;
  text-decoration: none;
  font-weight: 600;
}
.link-inv:hover {
  text-decoration: underline;
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

.actions-emp--stack {
  flex-direction: column;
  align-items: stretch;
}

.banner-assign {
  margin-bottom: 1rem;
  border-color: rgba(56, 189, 233, 0.35);
  background: rgba(14, 165, 233, 0.08);
}

.banner-assign-title {
  margin: 0 0 0.35rem;
  font-weight: 700;
  font-size: 0.95rem;
  color: #7dd3fc;
}

.banner-assign-text {
  margin: 0;
  font-size: 0.88rem;
  line-height: 1.45;
  color: #cbd5e1;
}

.banner-assign-text .muted {
  color: #64748b;
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

.aside-sub {
  margin: 0.2rem 0 0;
  font-size: 0.75rem;
}

.aside-liq {
  margin-top: 1rem;
  padding-top: 0.85rem;
  border-top: 1px solid rgba(148, 163, 184, 0.2);
  font-size: 0.82rem;
}

.aside-liq-title {
  margin: 0 0 0.5rem;
  font-weight: 600;
  color: #e2e8f0;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.aside-liq-row {
  margin: 0.35rem 0 0;
  display: flex;
  justify-content: space-between;
  gap: 0.75rem;
  align-items: baseline;
}

.aside-liq-k {
  color: #94a3b8;
}

.aside-liq-v {
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  color: #f1f5f9;
}

.aside-liq-margin .aside-liq-v {
  color: #a7f3d0;
}

.aside-liq-sync {
  margin: 0.6rem 0 0;
  font-size: 0.72rem;
  line-height: 1.35;
}

.aside-liq-note {
  margin: 0.75rem 0 0;
  font-size: 0.78rem;
  line-height: 1.4;
}

.liq-table-wrap {
  overflow-x: auto;
  margin-top: 0.5rem;
}

.liq-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.88rem;
}

.liq-table th,
.liq-table td {
  padding: 0.5rem 0.65rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.15);
  text-align: left;
}

.liq-table thead th {
  color: #94a3b8;
  font-weight: 600;
  font-size: 0.78rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.liq-table tbody td:first-child {
  color: #e2e8f0;
}

.liq-table tfoot th,
.liq-table tfoot td {
  border-bottom: none;
  padding-top: 0.75rem;
  font-weight: 700;
  color: #f8fafc;
}

.liq-table .num {
  text-align: right;
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.aside-company {
  margin: 0.65rem 0 0;
  font-size: 0.85rem;
  color: #94a3b8;
}

.aside-invoice {
  margin-top: 1rem;
  padding-top: 1rem;
  border-top: 1px solid rgba(148, 163, 184, 0.2);
}

.aside-invoice-btn {
  width: 100%;
  justify-content: center;
}

.aside-invoice-hint {
  margin: 0.5rem 0 0;
  font-size: 0.75rem;
  line-height: 1.4;
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

.emp-items {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.emp-item {
  margin: 0;
  padding: 0.65rem 0.75rem;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.22);
  background: rgba(15, 23, 42, 0.35);
}

.emp-item-label {
  margin: 0 0 0.35rem;
  font-size: 0.9rem;
  font-weight: 600;
  color: #e2e8f0;
}

.emp-item-desc {
  margin: 0 0 0.35rem;
  font-size: 0.85rem;
  color: #cbd5e1;
}

.emp-item-amt {
  margin: 0;
  font-size: 0.88rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
  color: #7dd3fc;
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
