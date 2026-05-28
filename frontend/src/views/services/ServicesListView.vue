<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AdminServiceDetailPanel from '@/components/admin/AdminServiceDetailPanel.vue'
import AdminServiceRegisterPanel from '@/components/admin/AdminServiceRegisterPanel.vue'
import ServiceDeleteModal from '@/components/services/ServiceDeleteModal.vue'
import ServiceListFilters from '@/components/services/ServiceListFilters.vue'
import ServiceListTable from '@/components/services/ServiceListTable.vue'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import { createInvoice } from '@/services/invoicesApi.js'
import { fetchEmpleadoCommercialInventorySettings } from '@/services/inventoryApi.js'
import { archiveService, fetchCompanies, fetchEmpleados, fetchServices } from '@/services/servicesApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'
import {
  SORT_DEFAULT_DIR,
  canAdminQuickMaintenanceInvoiceDraft as quickMaintInvoiceDraftAllowed,
  detectSavTypeByCode,
  parseServiceDatePeriod,
} from './servicesListHelpers.js'

const auth = useAuthStore()
const uiDialog = useUiDialogStore()
const router = useRouter()
const route = useRoute()
const props = defineProps({
  defaultKind: { type: String, default: '' },
})

const companies = ref([])
const empleados = ref([])
const rows = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref('')
const archivingId = ref(null)
const invoiceDraftBusyId = ref(null)

const deleteModalOpen = ref(false)
const deleteTarget = ref(null)

const detailPanelOpen = ref(false)
const detailServiceId = ref(null)
const registerPanelOpen = ref(false)
const registerPanelKind = ref('servicio')

function openRegisterPanel(kind) {
  registerPanelKind.value = kind
  registerPanelOpen.value = true
}

function openDetailPanel(row) {
  detailServiceId.value = row.id
  detailPanelOpen.value = true
}

function closeDetailPanel() {
  detailPanelOpen.value = false
  detailServiceId.value = null
}

const filters = ref({
  company_id: '',
  user_id: '',
  sav_type: props.defaultKind || '',
  service_date_from: '',
  service_date_to: '',
  q: '',
  page: 1,
  assignment_pending: false,
})

const isAdmin = computed(() => isAdminPanelRole(auth.user?.rol))
const isMaintenanceListing = computed(
  () =>
    props.defaultKind === 'mantenimiento' ||
    route.name === 'admin-mantenimientos' ||
    route.name === 'emp-mantenimientos',
)
const showEquipmentColumn = computed(
  () => isMaintenanceListing.value || filters.value.sav_type === 'mantenimiento',
)
const showServiceActions = computed(
  () => isAdmin.value || auth.user?.rol === 'empleado',
)
const tableColspan = computed(() => {
  const extra = showEquipmentColumn.value ? 1 : 0
  if (isAdmin.value) return 9 + extra
  if (showServiceActions.value) return 7 + extra
  return 6 + extra
})

const sortKey = ref('')
const sortDir = ref('desc')

const listTitle = computed(() => {
  if (isMaintenanceListing.value) return isAdmin.value ? 'Mantenimientos' : 'Mis mantenimientos'
  return isAdmin.value ? 'Listado SAV' : 'Mis SAV'
})

const empleadoCommercial = ref({ venta: false, alquiler: false })

function openDeleteModal(s) {
  if (s.status === 'eliminado' || archivingId.value != null) return
  deleteTarget.value = s
  deleteModalOpen.value = true
}

function closeDeleteModal() {
  deleteModalOpen.value = false
  deleteTarget.value = null
}

async function onDeleteConfirm(targetId) {
  archivingId.value = targetId
  try {
    await archiveService(targetId)
    closeDeleteModal()
    if (detailPanelOpen.value && String(detailServiceId.value) === String(targetId)) {
      closeDetailPanel()
    }
    await load()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo eliminar el servicio.'
  } finally {
    archivingId.value = null
  }
}

function onGlobalEscape(ev) {
  if (ev.key !== 'Escape') return
  if (deleteModalOpen.value) {
    ev.preventDefault()
    closeDeleteModal()
    return
  }
  if (isAdmin.value && registerPanelOpen.value) {
    ev.preventDefault()
    registerPanelOpen.value = false
    return
  }
  if (isAdmin.value && detailPanelOpen.value) {
    ev.preventDefault()
    closeDetailPanel()
  }
}

let searchTimer = null
let filterTimer = null

async function load() {
  error.value = ''
  loading.value = true
  try {
    const params = { page: filters.value.page, per_page: 15 }
    if (filters.value.company_id) params.company_id = filters.value.company_id
    if (isAdmin.value && filters.value.user_id) params.user_id = filters.value.user_id
    if (filters.value.service_date_from) params.service_date_from = filters.value.service_date_from
    if (filters.value.service_date_to) params.service_date_to = filters.value.service_date_to
    if (filters.value.q.trim()) params.q = filters.value.q.trim()
    if (!isAdmin.value && filters.value.assignment_pending) params.assignment_pending = true
    if (filters.value.sav_type === 'mantenimiento') params.kind = 'mantenimiento'
    if (sortKey.value) {
      params.sort = sortKey.value
      params.sort_dir = sortDir.value
    }

    const res = await fetchServices(params)
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = e.data?.message || e.message || 'Error al cargar servicios.'
  } finally {
    loading.value = false
  }
}

function scheduleFilterLoad() {
  clearTimeout(filterTimer)
  filterTimer = setTimeout(() => {
    filters.value.page = 1
    load()
  }, 400)
}

onMounted(async () => {
  document.addEventListener('keydown', onGlobalEscape)
  try {
    companies.value = await fetchCompanies()
    if (isAdmin.value) {
      empleados.value = await fetchEmpleados()
    }
  } catch {
    /* filtros opcionales */
  }
  if (!isAdmin.value) {
    sortKey.value = 'code'
    sortDir.value = 'desc'
    try {
      const r = await fetchEmpleadoCommercialInventorySettings()
      const d = r?.data || {}
      empleadoCommercial.value = {
        venta: Boolean(d.venta_enabled),
        alquiler: Boolean(d.alquiler_enabled),
      }
    } catch {
      empleadoCommercial.value = { venta: false, alquiler: false }
    }
  }
  await load()
})

onUnmounted(() => {
  document.removeEventListener('keydown', onGlobalEscape)
  clearTimeout(searchTimer)
  clearTimeout(filterTimer)
})

watch(
  () => filters.value.q,
  () => {
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filters.value.page = 1
      load()
    }, 350)
  },
)

watch(
  () => [
    filters.value.company_id,
    filters.value.user_id,
    filters.value.service_date_from,
    filters.value.service_date_to,
    filters.value.assignment_pending,
  ],
  () => scheduleFilterLoad(),
)

watch(
  isMaintenanceListing,
  (on) => {
    if (on && filters.value.sav_type !== 'mantenimiento') {
      filters.value.sav_type = 'mantenimiento'
    }
  },
  { immediate: true },
)

function goPage(p) {
  filters.value.page = p
  load()
}

function toggleSort(key) {
  if (sortKey.value === key) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortDir.value = SORT_DEFAULT_DIR[key] ?? 'asc'
  }
  filters.value.page = 1
  load()
}

const filteredRows = computed(() => {
  const wanted = String(filters.value.sav_type || '')
  if (!wanted) return rows.value
  return rows.value.filter((s) => detectSavTypeByCode(s) === wanted)
})

async function onQuickMaintenanceInvoiceDraft(s) {
  const per = parseServiceDatePeriod(s.service_date)
  if (!per || !quickMaintInvoiceDraftAllowed(s, isAdmin.value) || invoiceDraftBusyId.value != null) return
  const ok = await uiDialog.confirm({
    title: 'Crear borrador de factura',
    message:
      `Se creará un borrador del periodo ${per.month}/${per.year} con el mantenimiento ${s.code}. ` +
      'Revise el detalle del servicio si necesita corregir importes antes.',
    confirmLabel: 'Crear borrador',
    cancelLabel: 'Cancelar',
  })
  if (!ok) return
  invoiceDraftBusyId.value = s.id
  try {
    const inv = await createInvoice({
      company_id: s.company_id,
      period_year: per.year,
      period_month: per.month,
      service_ids: [s.id],
    })
    await load()
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
    invoiceDraftBusyId.value = null
  }
}
</script>

<template>
  <section class="page page--fluid">
    <header class="head">
      <div>
        <h1>{{ listTitle }}</h1>
        <p class="lede">
          {{
            isAdmin
              ? 'Clic en el código de servicio abre el panel lateral con el detalle; debajo, el código de factura (si existe) enlaza a la factura.'
              : 'Solo tus servicios visibles. Toca una fila para ver el detalle.'
          }}
        </p>
      </div>
      <div class="head-btns">
        <template v-if="isAdmin">
          <template v-if="isMaintenanceListing">
            <button type="button" class="btn primary register-btn" @click="openRegisterPanel('mantenimiento')">+ Mantenimiento</button>
          </template>
          <template v-else>
            <button type="button" class="btn primary register-btn" @click="openRegisterPanel('servicio')">+ Servicio</button>
            <button type="button" class="btn register-btn register-btn--alquiler" @click="openRegisterPanel('alquiler')">+ Alquiler</button>
            <button type="button" class="btn register-btn register-btn--venta" @click="openRegisterPanel('venta')">+ Venta</button>
          </template>
        </template>
        <template v-else>
          <template v-if="isMaintenanceListing">
            <button type="button" class="btn primary register-btn" @click="openRegisterPanel('mantenimiento')">+ Mantenimiento</button>
          </template>
          <template v-else>
            <button type="button" class="btn primary register-btn" @click="openRegisterPanel('servicio')">+ Servicio</button>
            <button
              type="button"
              class="btn register-btn register-btn--alquiler"
              :disabled="!empleadoCommercial.alquiler"
              :title="empleadoCommercial.alquiler ? 'Registrar alquiler' : 'Alquiler no habilitado por administración'"
              @click="openRegisterPanel('alquiler')"
            >+ Alquiler</button>
            <button
              type="button"
              class="btn register-btn register-btn--venta"
              :disabled="!empleadoCommercial.venta"
              :title="empleadoCommercial.venta ? 'Registrar venta' : 'Venta no habilitada por administración'"
              @click="openRegisterPanel('venta')"
            >+ Venta</button>
          </template>
        </template>
      </div>
    </header>

    <p v-if="error" class="banner" role="alert">{{ error }}</p>

    <ServiceListFilters
      :filters="filters"
      :companies="companies"
      :empleados="empleados"
      :is-admin="isAdmin"
      :is-maintenance-listing="isMaintenanceListing"
    />

    <p class="filter-hint muted">
      <template v-if="isAdmin">
        Los filtros y la búsqueda se aplican automáticamente al cambiar valores. El listado SAV incluye servicios
        eliminados; se distinguen por el estado «Eliminado». Pulsa un encabezado de columna para ordenar (▲/▼).
      </template>
      <template v-else>
        Los filtros y la búsqueda se aplican automáticamente al cambiar valores. Pulsa un encabezado para ordenar (▲/▼).
      </template>
    </p>

    <ServiceListTable
      :rows="filteredRows"
      :loading="loading"
      :meta="meta"
      :is-admin="isAdmin"
      :show-equipment-column="showEquipmentColumn"
      :show-service-actions="showServiceActions"
      :table-colspan="tableColspan"
      :sort-key="sortKey"
      :sort-dir="sortDir"
      :archiving-id="archivingId"
      :invoice-draft-busy-id="invoiceDraftBusyId"
      @toggle-sort="toggleSort"
      @go-page="goPage"
      @open-detail="openDetailPanel"
      @open-delete="openDeleteModal"
      @quick-draft="onQuickMaintenanceInvoiceDraft"
    />

    <ServiceDeleteModal
      :open="deleteModalOpen"
      :target="deleteTarget"
      :archiving-id="archivingId"
      @close="closeDeleteModal"
      @confirm="onDeleteConfirm"
    />

    <AdminServiceRegisterPanel
      :open="registerPanelOpen"
      :register-kind="registerPanelKind"
      :is-empleado="!isAdmin"
      :overlay-z-index="96"
      @close="registerPanelOpen = false"
      @created="load"
    />

    <AdminServiceDetailPanel
      v-if="isAdmin"
      :open="detailPanelOpen"
      :service-id="detailServiceId"
      @close="closeDetailPanel"
    />
  </section>
</template>

<style scoped>
.page.page--fluid {
  width: 100%;
  max-width: none;
  min-width: 0;
  margin: 0;
  box-sizing: border-box;
}

.head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.head-btns {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}

.register-btn {
  min-width: 9.5rem;
  border-color: transparent;
  color: #fff;
  transition: filter 0.15s ease, transform 0.12s ease;
}

.register-btn:hover:not(:disabled) {
  filter: brightness(1.08);
}

.register-btn:active:not(:disabled) {
  transform: scale(0.99);
}

.register-btn--alquiler {
  background: linear-gradient(90deg, #ca8a04, #eab308);
  box-shadow: 0 4px 14px rgba(234, 179, 8, 0.28);
}

.register-btn--venta {
  background: linear-gradient(90deg, #059669, #22c55e);
  box-shadow: 0 4px 14px rgba(34, 197, 94, 0.28);
}

.btn.primary.register-btn {
  box-shadow: 0 4px 14px rgba(37, 99, 235, 0.3);
}

.head h1 {
  margin: 0;
  font-size: 1.35rem;
}

.lede {
  margin: 0.35rem 0 0;
  font-size: 0.875rem;
  color: #94a3b8;
  max-width: min(48rem, 100%);
  line-height: 1.45;
}

.banner {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}

.filter-hint {
  font-size: 0.78rem;
  margin: -0.35rem 0 1rem;
}

.muted {
  color: #94a3b8;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem 0.9rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  text-decoration: none;
}

.btn.primary {
  background: linear-gradient(90deg, #2563eb, #7c3aed);
  color: #fff;
  border-color: transparent;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
