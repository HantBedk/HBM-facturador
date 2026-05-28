<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  approveCorreoSolicitud,
  createUser,
  fetchAdminUsers,
  patchUserEstado,
  rejectCorreoSolicitud,
  updateUser,
} from '@/services/usersApi.js'
import { fetchTechnicianPendingServices, postTechnicianBatchPay } from '@/services/empleadoTechnicianPayApi.js'
import { useAuthStore } from '@/stores/auth'
import { useUiDialogStore } from '@/stores/uiDialog'
import { tableAriaSort, tableSortIndicator } from '@/utils/tableSort.js'
import { formatDate } from './companiesListHelpers.js'
import { formatMoneyRef, formatPayServiceDate, todayYmd } from './empleadosListHelpers.js'
import AdminEmpleadoFichaPanel from '@/components/admin/AdminEmpleadoFichaPanel.vue'
import AdminAssignServicePanel from '@/components/admin/AdminAssignServicePanel.vue'

const route = useRoute()
const auth = useAuthStore()
const uiDialog = useUiDialogStore()
const router = useRouter()

const rows = ref([])
const meta = ref(null)
const loading = ref(false)
const error = ref('')
const search = ref('')

const userSortKey = ref('nombre')
const userSortDir = ref('asc')
const USER_SORT_FIRST = {
  nombre: 'asc',
  correo: 'asc',
  estado: 'asc',
  created_at: 'desc',
}

const showPassword = ref(false)

const assignOpen = ref(false)
const assignTarget = ref(null)

const modalOpen = ref(false)
const modalMode = ref('create')
const editingId = ref(null)
/** Fila en edición (p. ej. solicitud de correo) */
const editingRow = ref(null)
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

const isAdminNotSuper = computed(() => auth.user?.rol === 'admin')

const ROL_FORM_OPTIONS = computed(() => {
  if (isAdminNotSuper.value) {
    return [{ value: 'empleado', label: 'Empleado' }]
  }
  return [
    { value: 'empleado', label: 'Empleado' },
    { value: 'admin', label: 'Administrador' },
    { value: 'super_admin', label: 'Super administrador' },
  ]
})

let searchTimer = null

/** Desde notificación (p. ej. cambio de correo, solicitud de restablecimiento de contraseña): ?usuario_id= */
const filterUsuarioId = computed(() => {
  const raw = route.query.usuario_id
  if (raw == null || raw === '') return null
  const n = Number.parseInt(String(raw), 10)
  return Number.isFinite(n) && n > 0 ? n : null
})

async function load() {
  error.value = ''
  loading.value = true
  try {
    const params = { page: filtersPage.value, per_page: 15 }
    if (filterUsuarioId.value != null) {
      params.user_id = filterUsuarioId.value
    } else {
      if (search.value.trim()) params.q = search.value.trim()
      params.sort = userSortKey.value
      params.sort_dir = userSortDir.value
    }
    const res = await fetchAdminUsers(params)
    rows.value = res.data
    meta.value = res.meta
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar los usuarios.'
    rows.value = []
  } finally {
    loading.value = false
  }
  if (filterUsuarioId.value != null) {
    await nextTick()
    const el = document.querySelector(`tr[data-user-id="${filterUsuarioId.value}"]`)
    el?.scrollIntoView({ behavior: 'smooth', block: 'nearest' })
  }
}

function clearUsuarioNotificationFilter() {
  router.replace({ name: 'admin-emp-rendimiento' })
}

const filtersPage = ref(1)

watch(
  () => search.value,
  () => {
    if (filterUsuarioId.value != null) return
    clearTimeout(searchTimer)
    searchTimer = setTimeout(() => {
      filtersPage.value = 1
      load()
    }, 320)
  }
)

watch(
  () => route.query.usuario_id,
  () => {
    filtersPage.value = 1
    load()
  }
)

onMounted(load)
onUnmounted(() => clearTimeout(searchTimer))

function goPage(p) {
  filtersPage.value = p
  load()
}

function userSortInd(k) {
  return tableSortIndicator(userSortKey.value, userSortDir.value, k)
}

function userAriaSort(k) {
  return tableAriaSort(userSortKey.value, userSortDir.value, k)
}

function toggleUserSort(key) {
  if (userSortKey.value === key) {
    userSortDir.value = userSortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    userSortKey.value = key
    userSortDir.value = USER_SORT_FIRST[key] || 'asc'
  }
  filtersPage.value = 1
  load()
}

function openCreate() {
  showPassword.value = false
  modalMode.value = 'create'
  editingId.value = null
  editingRow.value = null
  modalError.value = ''
  fieldErrors.value = {}
  form.value = { nombre: '', correo: '', password: '', rol: 'empleado', estado: 'activo' }
  modalOpen.value = true
}

function openEdit(row) {
  showPassword.value = false
  modalMode.value = 'edit'
  editingId.value = row.id
  editingRow.value = row
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

function openAssignService(row) {
  if (row.rol !== 'empleado' || row.estado !== 'activo') return
  assignTarget.value = row
  assignOpen.value = true
}

function closeAssignService() {
  assignOpen.value = false
  assignTarget.value = null
}

async function onServiceAssigned(created) {
  closeAssignService()
  await uiDialog.alert({
    title: 'Servicio asignado',
    message: `Se creó ${created?.code || 'el servicio'} para el técnico. Aparecerá en su listado como pendiente de completar.`,
  })
}

const modalTitle = computed(() => (modalMode.value === 'create' ? 'Nuevo Empleado' : 'Editar usuario'))

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

async function onApproveCorreo(row) {
  if (!row.correo_solicitado) return false
  const msg = `¿Aplicar como correo de acceso el siguiente?\n${row.correo_solicitado}\n\nEl técnico usará ese correo para iniciar sesión.`
  const ok = await uiDialog.confirm({ title: 'Aprobar correo solicitado', message: msg })
  if (!ok) return false
  try {
    await approveCorreoSolicitud(row.id)
    await load()
    return true
  } catch (e) {
    await uiDialog.alert({
      title: 'Error',
      message: e.data?.message || e.message || 'No se pudo aprobar.',
    })
    return false
  }
}

async function onRejectCorreo(row) {
  if (!row.correo_solicitado) return false
  const ok = await uiDialog.confirm({
    title: 'Rechazar solicitud',
    message: '¿Rechazar la solicitud de cambio de correo? El técnico seguirá con su correo actual.',
    danger: true,
    confirmLabel: 'Rechazar',
  })
  if (!ok) return false
  try {
    await rejectCorreoSolicitud(row.id)
    await load()
    return true
  } catch (e) {
    await uiDialog.alert({
      title: 'Error',
      message: e.data?.message || e.message || 'No se pudo rechazar.',
    })
    return false
  }
}

async function approveFromModal() {
  const row = editingRow.value
  if (!row?.correo_solicitado) return
  const ok = await onApproveCorreo(row)
  if (ok) closeModal()
}

async function rejectFromModal() {
  const row = editingRow.value
  if (!row?.correo_solicitado) return
  const ok = await onRejectCorreo(row)
  if (ok) closeModal()
}

async function onToggleEstado(row) {
  const next = row.estado === 'activo' ? 'inactivo' : 'activo'
  const label = next === 'activo' ? 'activar' : 'desactivar'
  const ok = await uiDialog.confirm({
    title: next === 'activo' ? 'Activar empleado' : 'Desactivar empleado',
    message: `¿${label} a «${row.nombre}»?`,
    danger: next === 'inactivo',
  })
  if (!ok) return
  try {
    const updated = await patchUserEstado(row.id, next)
    const i = rows.value.findIndex((r) => r.id === row.id)
    if (i >= 0) rows.value[i] = { ...rows.value[i], ...updated }
    else await load()
  } catch (e) {
    await uiDialog.alert({
      title: 'Error',
      message: e.data?.message || e.message || 'No se pudo actualizar.',
    })
  }
}

const pageSummary = computed(() => {
  const m = meta.value
  if (!m || !m.total) return ''
  return `${m.from ?? 0}–${m.to ?? 0} de ${m.total}`
})

/** Suma ref. pendiente en la página actual (solo filas técnico). */
const pageRefPendingTotal = computed(() => {
  let sum = 0
  for (const u of rows.value) {
    if (u.rol !== 'empleado') continue
    const n = Number(u.technician_debt_pending_total)
    if (!Number.isNaN(n)) sum += n
  }
  return sum
})

const payModalOpen = ref(false)
const payTarget = ref(null)
const payServices = ref([])
const payLoading = ref(false)
const paySaving = ref(false)
const payError = ref('')
const payDate = ref('')
const selectedServiceIds = ref([])

const paySelectedTotal = computed(() => {
  const set = new Set(selectedServiceIds.value)
  let t = 0
  for (const s of payServices.value) {
    if (set.has(s.id)) {
      const n = Number(s.technician_line_total)
      if (!Number.isNaN(n)) t += n
    }
  }
  return t
})

async function openPayModal(row) {
  if (row.rol !== 'empleado') return
  payTarget.value = row
  payError.value = ''
  selectedServiceIds.value = []
  payDate.value = todayYmd()
  payServices.value = []
  payModalOpen.value = true
  payLoading.value = true
  try {
    const res = await fetchTechnicianPendingServices(row.id)
    payServices.value = Array.isArray(res.data) ? res.data : []
    if (payServices.value.length) {
      selectedServiceIds.value = payServices.value.map((s) => s.id)
    }
  } catch (e) {
    payError.value = e.data?.message || e.message || 'No se pudieron cargar los servicios.'
    payServices.value = []
  } finally {
    payLoading.value = false
  }
}

function closePayModal() {
  payModalOpen.value = false
  payTarget.value = null
  payServices.value = []
  payError.value = ''
}

function togglePaySvc(id) {
  const i = selectedServiceIds.value.indexOf(id)
  if (i >= 0) {
    selectedServiceIds.value = selectedServiceIds.value.filter((x) => x !== id)
  } else {
    selectedServiceIds.value = [...selectedServiceIds.value, id]
  }
}

function selectAllPaySvc() {
  selectedServiceIds.value = payServices.value.map((s) => s.id)
}

function clearPaySvc() {
  selectedServiceIds.value = []
}

async function submitPayModal() {
  const row = payTarget.value
  if (!row || paySaving.value || selectedServiceIds.value.length === 0) return
  paySaving.value = true
  payError.value = ''
  try {
    await postTechnicianBatchPay(row.id, {
      service_ids: selectedServiceIds.value,
      technician_paid_at: payDate.value || todayYmd(),
    })
    closePayModal()
    await load()
  } catch (e) {
    payError.value = e.data?.message || e.message || 'No se pudo registrar el pago.'
  } finally {
    paySaving.value = false
  }
}

const fichaOpen = ref(false)
const fichaRow = ref(null)

function openFicha(row) {
  fichaRow.value = row
  fichaOpen.value = true
}

function closeFicha() {
  fichaOpen.value = false
  fichaRow.value = null
}

function onFichaUpdated() {
  load()
}
</script>

<template>
  <section class="page page--fluid">
    <header class="head">
      <div class="head-main">
        <div class="head-title-row">
          <h1>Empleados</h1>
          <button type="button" class="btn primary" @click="openCreate">+ Nuevo empleado</button>
        </div>
        <p class="lede">
          Alta y edición de cuentas. Pulse el nombre para abrir la ficha en un panel (técnicos: contacto, documento y
          datos de pago). La columna «Ref. pendiente» muestra el total de referencia técnico sin abono registrado; use
          «Pagar» para elegir servicios y fechar el abono. «Historial» abre métricas y servicios por mes.
          <template v-if="isAdminNotSuper">
            Solo gestiona técnicos; las cuentas administrador las gestiona un super administrador.
          </template>
        </p>
      </div>
    </header>

    <p v-if="filterUsuarioId != null" class="banner focus">
      Vista filtrada por la notificación (solicitud de correo). Use
      <button type="button" class="link-btn" @click="clearUsuarioNotificationFilter">ver todos los usuarios</button>
      para volver al listado completo.
    </p>

    <div class="toolbar card">
      <label class="grow">
        <span class="lbl">Buscar</span>
        <input v-model="search" type="search" class="input" placeholder="Nombre o correo…" />
      </label>
    </div>

    <p v-if="!loading && rows.length && pageRefPendingTotal > 0" class="meta-line muted page-debt-hint">
      Referencia técnico pendiente en esta página (suma):
      <strong>{{ formatMoneyRef(String(pageRefPendingTotal)) }}</strong>
    </p>

    <p v-if="error" class="banner err">{{ error }}</p>

    <div class="card table-wrap">
      <div v-if="loading" class="muted pad">Cargando…</div>
      <template v-else>
        <p v-if="pageSummary" class="meta-line muted">{{ pageSummary }}</p>
        <table class="table">
          <thead>
            <tr>
              <th scope="col" :aria-sort="userAriaSort('nombre')">
                <button type="button" class="th-sort" @click="toggleUserSort('nombre')">
                  Nombre<span class="sort-ind" aria-hidden="true">{{ userSortInd('nombre') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="userAriaSort('correo')">
                <button type="button" class="th-sort" @click="toggleUserSort('correo')">
                  Correo<span class="sort-ind" aria-hidden="true">{{ userSortInd('correo') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="userAriaSort('estado')">
                <button type="button" class="th-sort" @click="toggleUserSort('estado')">
                  Estado<span class="sort-ind" aria-hidden="true">{{ userSortInd('estado') }}</span>
                </button>
              </th>
              <th class="num" scope="col">Ref. pendiente</th>
              <th scope="col" :aria-sort="userAriaSort('created_at')">
                <button type="button" class="th-sort" @click="toggleUserSort('created_at')">
                  Alta<span class="sort-ind" aria-hidden="true">{{ userSortInd('created_at') }}</span>
                </button>
              </th>
              <th class="actions-col">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="u in rows"
              :key="u.id"
              :data-user-id="u.id"
              :class="{ 'row--focus': filterUsuarioId != null && filterUsuarioId === u.id }"
            >
              <td>
                <button
                  type="button"
                  class="name-link"
                  :title="`Ver ficha de ${u.nombre}`"
                  @click="openFicha(u)"
                >
                  {{ u.nombre }}
                </button>
              </td>
              <td>
                <div v-if="u.correo" class="cell-sm muted">{{ u.correo }}</div>
                <div v-if="u.rol === 'empleado' && u.correo_solicitado" class="correo-solicitado">
                  <span class="badge">Pendiente</span>
                  {{ u.correo_solicitado }}
                </div>
                <span v-if="!u.correo && !(u.rol === 'empleado' && u.correo_solicitado)" class="muted">—</span>
              </td>
              <td>
                <button
                  type="button"
                  class="pill pill-estado"
                  :data-st="u.estado"
                  :title="u.estado === 'activo' ? 'Pulsa para desactivar' : 'Pulsa para activar'"
                  :aria-label="`${u.estado === 'activo' ? 'Desactivar' : 'Activar'} a ${u.nombre}`"
                  @click="onToggleEstado(u)"
                >
                  {{ u.estado === 'activo' ? 'Activo' : 'Inactivo' }}
                </button>
              </td>
              <td class="num cell-ref">
                <template v-if="u.rol === 'empleado'">
                  {{ formatMoneyRef(u.technician_debt_pending_total) }}
                  <span v-if="(u.technician_debt_pending_services || 0) > 0" class="ref-svc-count muted">
                    ({{ u.technician_debt_pending_services }} srv.)
                  </span>
                </template>
                <template v-else>—</template>
              </td>
              <td class="muted">{{ formatDate(u.created_at) }}</td>
              <td class="actions-col">
                <button type="button" class="link" @click="openEdit(u)">Editar</button>
                <RouterLink
                  v-if="u.rol === 'empleado'"
                  class="link link-inline-block"
                  :to="{ name: 'admin-emp-rendimiento-user', params: { userId: String(u.id) } }"
                >
                  Historial
                </RouterLink>
                <button
                  v-if="u.rol === 'empleado' && u.estado === 'activo'"
                  type="button"
                  class="link"
                  @click="openAssignService(u)"
                >
                  Asignar servicio
                </button>
                <button
                  v-if="u.rol === 'empleado' && Number(u.technician_debt_pending_total) > 0"
                  type="button"
                  class="link link-pay"
                  @click="openPayModal(u)"
                >
                  Pagar
                </button>
                <template v-if="u.rol === 'empleado' && u.correo_solicitado">
                  <button type="button" class="link link-ok" @click="onApproveCorreo(u)">Aprobar correo</button>
                  <button type="button" class="link link-warn" @click="onRejectCorreo(u)">Rechazar</button>
                </template>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="6" class="empty muted">No hay resultados.</td>
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

    <AdminEmpleadoFichaPanel :open="fichaOpen" :row="fichaRow" @close="closeFicha" @updated="onFichaUpdated" />

    <AdminAssignServicePanel
      :open="assignOpen"
      :technician="assignTarget"
      @close="closeAssignService"
      @assigned="onServiceAssigned"
    />

    <Teleport to="body">
      <div
        v-if="payModalOpen"
        class="modal-backdrop pay-modal-backdrop"
        role="presentation"
        @click.self="closePayModal"
      >
        <div class="modal card pay-modal" role="dialog" aria-modal="true" aria-labelledby="pay-modal-title">
          <div class="modal-header">
            <h2 id="pay-modal-title" class="modal-title">
              Registrar abono a {{ payTarget?.nombre || 'técnico' }}
            </h2>
            <button type="button" class="modal-close" aria-label="Cerrar" @click="closePayModal">×</button>
          </div>
          <p class="muted pay-modal-lede">
            Seleccione los servicios a los que aplica la misma fecha de abono de referencia. El técnico recibirá un aviso en
            su panel por cada servicio.
          </p>
          <p v-if="payError" class="banner err">{{ payError }}</p>
          <div v-if="payLoading" class="muted pad">Cargando servicios…</div>
          <template v-else-if="payServices.length">
            <div class="pay-modal-toolbar">
              <button type="button" class="btn secondary btn-sm" @click="selectAllPaySvc">Seleccionar todos</button>
              <button type="button" class="btn secondary btn-sm" @click="clearPaySvc">Ninguno</button>
              <label class="pay-date-label">
                <span>Fecha del abono</span>
                <input v-model="payDate" type="date" class="input pay-date-input" />
              </label>
            </div>
            <div class="pay-table-wrap">
              <table class="table pay-table">
                <thead>
                  <tr>
                    <th class="chk" scope="col" />
                    <th scope="col">Código</th>
                    <th scope="col">Fecha</th>
                    <th scope="col">Empresa</th>
                    <th class="num" scope="col">Ref. técnico</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="s in payServices" :key="s.id">
                    <td class="chk">
                      <input
                        type="checkbox"
                        :checked="selectedServiceIds.includes(s.id)"
                        :aria-label="`Incluir servicio ${s.code}`"
                        @change="togglePaySvc(s.id)"
                      />
                    </td>
                    <td class="mono">{{ s.code }}</td>
                    <td>{{ formatPayServiceDate(s.service_date) }}</td>
                    <td>{{ s.company_name || '—' }}</td>
                    <td class="num">{{ formatMoneyRef(s.technician_line_total) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
            <div class="pay-modal-footer">
              <p class="pay-total">
                Total seleccionado: <strong>{{ formatMoneyRef(String(paySelectedTotal)) }}</strong>
              </p>
              <div class="pay-modal-actions">
                <button type="button" class="btn secondary" :disabled="paySaving" @click="closePayModal">
                  Cancelar
                </button>
                <button
                  type="button"
                  class="btn primary"
                  :disabled="paySaving || selectedServiceIds.length === 0"
                  @click="submitPayModal"
                >
                  {{ paySaving ? 'Guardando…' : 'Confirmar abono' }}
                </button>
              </div>
            </div>
          </template>
          <p v-else class="muted pad">No hay servicios con referencia técnico pendiente para este usuario.</p>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="modalOpen" class="modal-backdrop" @click.self="closeModal">
        <div class="modal card modal-emp" role="dialog" aria-modal="true" aria-labelledby="emp-modal-title">
          <div class="modal-header">
            <h2 id="emp-modal-title" class="modal-title">{{ modalTitle }}</h2>
            <button type="button" class="modal-close" aria-label="Cerrar" @click="closeModal">×</button>
          </div>
          <p v-if="modalError" class="banner err modal-banner-err">{{ modalError }}</p>
          <div
            v-if="modalMode === 'edit' && form.rol === 'empleado' && editingRow?.correo_solicitado"
            class="banner info"
          >
            <p class="info-p">
              <strong>Solicitud de correo (técnico):</strong>
              {{ editingRow.correo_solicitado }}
            </p>
            <p class="info-p muted-note">Acceso actual: {{ form.correo }}</p>
            <div class="correo-actions">
              <button type="button" class="btn primary" @click="approveFromModal">Aprobar este correo</button>
              <button type="button" class="btn secondary" @click="rejectFromModal">Rechazar solicitud</button>
            </div>
          </div>
          <form class="modal-form" @submit.prevent="onSubmitModal">
            <label class="field">
              <span>Nombre completo <abbr title="obligatorio">*</abbr></span>
              <input v-model="form.nombre" class="input" required maxlength="255" />
              <small v-if="fieldErrors.nombre" class="err">{{ fieldErrors.nombre[0] }}</small>
            </label>
            <label class="field">
              <span>Correo electrónico <abbr title="obligatorio">*</abbr></span>
              <input v-model="form.correo" type="email" class="input" required />
              <small v-if="fieldErrors.correo" class="err">{{ fieldErrors.correo[0] }}</small>
            </label>
            <label class="field">
              <span>Contraseña {{ modalMode === 'create' ? '(obligatoria)' : '(opcional)' }}</span>
              <span class="password-wrap">
                <input
                  v-model="form.password"
                  :type="showPassword ? 'text' : 'password'"
                  class="input input--password"
                  :required="modalMode === 'create'"
                  autocomplete="new-password"
                />
                <button
                  type="button"
                  class="pw-toggle"
                  :aria-pressed="showPassword"
                  :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                  @click="showPassword = !showPassword"
                >
                  <svg v-if="!showPassword" width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12z"
                    />
                    <circle cx="12" cy="12" r="3" stroke="currentColor" stroke-width="2" />
                  </svg>
                  <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path
                      stroke="currentColor"
                      stroke-width="2"
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      d="M3 3l18 18M10.6 10.6a3 3 0 004.8 4.8M9.9 5.1A10.4 10.4 0 0112 5c7 0 11 7 11 7a18.3 18.3 0 01-3.5 4M6.2 6.2C3.6 8.3 2 12 2 12s4 7 11 7a10 10 0 004.4-.9M12 12a3 3 0 01-3-3"
                    />
                  </svg>
                </button>
              </span>
              <small v-if="fieldErrors.password" class="err">{{ fieldErrors.password[0] }}</small>
            </label>
            <div class="modal-row-2">
              <label class="field">
                <span>Rol</span>
                <select v-model="form.rol" class="input" required>
                  <option v-for="o in ROL_FORM_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                </select>
                <small v-if="fieldErrors.rol" class="err">{{ fieldErrors.rol[0] }}</small>
              </label>
              <label class="field">
                <span>Estado</span>
                <select v-model="form.estado" class="input" required>
                  <option value="activo">Activo</option>
                  <option value="inactivo">Inactivo</option>
                </select>
              </label>
            </div>
            <div class="modal-actions">
              <button type="button" class="btn secondary" @click="closeModal">Cancelar</button>
              <button type="submit" class="btn primary" :disabled="saving">
                {{ saving ? 'Guardando…' : modalMode === 'create' ? 'Crear' : 'Guardar' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
/* Mismo criterio que Empresas (CompaniesListView): ancho completo y tabla card + table. */
.page.page--fluid {
  width: 100%;
  max-width: none;
  min-width: 0;
  margin: 0;
  box-sizing: border-box;
}

.head {
  margin-bottom: 1rem;
}

.head-main {
  min-width: 0;
}

.head-title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem 1rem;
  margin-bottom: 0.35rem;
  width: 100%;
}

.head-title-row h1 {
  margin: 0;
  font-size: 1.35rem;
  color: #f8fafc;
  min-width: 0;
}

.lede {
  margin: 0;
  max-width: min(48rem, 100%);
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

/* Modales: superficie opaca para que el texto no compita con la tabla detrás */
.modal.card {
  background: #0f172a;
  border-color: rgba(148, 163, 184, 0.28);
  box-shadow:
    0 0 0 1px rgba(15, 23, 42, 0.95),
    0 24px 48px -12px rgba(0, 0, 0, 0.6);
  margin-bottom: 0;
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

.banner.focus {
  background: rgba(56, 189, 248, 0.08);
  border: 1px solid rgba(56, 189, 248, 0.4);
  color: #bae6fd;
  font-size: 0.875rem;
  line-height: 1.45;
}

.banner.info {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(56, 189, 248, 0.1);
  border: 1px solid rgba(56, 189, 248, 0.35);
  color: #e0f2fe;
  margin-bottom: 1rem;
}

.link-btn {
  display: inline;
  margin: 0;
  padding: 0;
  border: none;
  background: none;
  color: #7dd3fc;
  font: inherit;
  font-weight: 600;
  cursor: pointer;
  text-decoration: underline;
}

.link-btn:hover {
  color: #bae6fd;
}

.modal-banner-err {
  margin-top: 0;
}

.table-wrap {
  width: 100%;
  min-width: 0;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  padding-inline-end: 2px;
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

.cell-sm {
  font-size: 0.82rem;
  line-height: 1.35;
}

.name-plain {
  font-weight: 600;
  color: #f1f5f9;
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

button.pill {
  font: inherit;
}

button.pill-estado {
  display: inline-block;
  cursor: pointer;
  text-transform: none;
}

button.pill-estado:focus-visible {
  outline: 2px solid rgba(56, 189, 248, 0.55);
  outline-offset: 2px;
}

.actions-col {
  white-space: normal;
  min-width: 11rem;
  max-width: 22rem;
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

a.link {
  display: inline;
}

.link-inline-block {
  display: inline-block;
  margin-top: 0.25rem;
}

.link-ok {
  color: #86efac;
}

.link-warn {
  color: #fbbf24;
}

.name-link {
  margin: 0;
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  font-weight: 600;
  color: #f1f5f9;
  text-align: left;
  cursor: pointer;
  text-decoration: underline;
  text-decoration-color: rgba(125, 211, 252, 0.45);
  text-underline-offset: 0.15em;
}

a.name-link {
  display: inline;
}

button.name-link {
  display: inline;
}

.name-link:hover {
  color: #7dd3fc;
  text-decoration-color: rgba(125, 211, 252, 0.85);
}

.name-link:focus-visible {
  outline: 2px solid rgba(56, 189, 248, 0.55);
  outline-offset: 2px;
  border-radius: 4px;
}

.row--focus {
  background: rgba(56, 189, 248, 0.08);
  box-shadow: inset 0 0 0 2px rgba(56, 189, 248, 0.45);
}

.correo-solicitado {
  margin-top: 0.35rem;
  font-size: 0.78rem;
  color: #fde68a;
}

.correo-solicitado .badge {
  display: inline-block;
  margin-right: 0.35rem;
  padding: 0.05rem 0.35rem;
  border-radius: 4px;
  background: rgba(251, 191, 36, 0.2);
  color: #fde68a;
  font-size: 0.7rem;
  font-weight: 700;
}

.meta-line {
  font-size: 0.8rem;
  margin: 0 0 0.65rem;
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

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin-top: 1rem;
  flex-wrap: wrap;
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
  background: rgba(2, 6, 23, 0.82);
  backdrop-filter: blur(8px);
}

.modal {
  width: 100%;
  max-width: 440px;
  max-height: 90vh;
  overflow-y: auto;
  margin: 0;
}

.modal-emp {
  padding-top: 1.1rem;
}

.modal-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
}

.modal-title {
  margin: 0;
  font-size: 1.15rem;
  color: #f8fafc;
}

.modal-close {
  flex-shrink: 0;
  width: 2rem;
  height: 2rem;
  border: none;
  border-radius: 8px;
  background: rgba(148, 163, 184, 0.15);
  color: #94a3b8;
  font-size: 1.35rem;
  line-height: 1;
  cursor: pointer;
}

.modal-close:hover {
  color: #f8fafc;
  background: rgba(148, 163, 184, 0.28);
}

.modal-form {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
}

.modal-row-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0.75rem 1rem;
}

@media (max-width: 480px) {
  .modal-row-2 {
    grid-template-columns: 1fr;
  }
}

.field span {
  display: block;
  font-size: 0.82rem;
  color: #cbd5e1;
  margin-bottom: 0.35rem;
}

.info-p {
  margin: 0 0 0.5rem;
}

.muted-note {
  font-size: 0.82rem;
  opacity: 0.9;
}

.password-wrap {
  position: relative;
  display: block;
}

.input--password {
  padding-right: 2.75rem;
}

.pw-toggle {
  position: absolute;
  right: 0.35rem;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: #94a3b8;
  cursor: pointer;
}

.pw-toggle:hover {
  color: #e2e8f0;
  background: rgba(148, 163, 184, 0.12);
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

.correo-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.page-debt-hint {
  margin: 0 0 0.75rem;
  font-size: 0.85rem;
}

.table th.num,
.table td.num {
  text-align: right;
}

.cell-ref {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
}

.ref-svc-count {
  display: block;
  font-size: 0.72rem;
  margin-top: 0.15rem;
}

.link-pay {
  color: #fde68a !important;
  font-weight: 600;
}

.pay-modal-backdrop {
  z-index: 120;
}

.pay-modal {
  max-width: min(640px, calc(100vw - 2rem));
  max-height: min(90vh, 720px);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.pay-modal-lede {
  margin: 0 0 0.75rem;
  font-size: 0.82rem;
  line-height: 1.45;
}

.pay-modal-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 0.65rem 1rem;
  margin-bottom: 0.65rem;
}

.btn-sm {
  padding: 0.35rem 0.65rem;
  font-size: 0.82rem;
}

.pay-date-label {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  margin-left: auto;
  font-size: 0.78rem;
  color: #94a3b8;
}

.pay-date-input {
  max-width: 11rem;
}

.pay-table-wrap {
  flex: 1;
  min-height: 0;
  overflow: auto;
  border: 1px solid rgba(148, 163, 184, 0.15);
  border-radius: 10px;
}

.pay-table {
  font-size: 0.82rem;
}

.pay-table th.chk,
.pay-table td.chk {
  width: 2.25rem;
  text-align: center;
}

.pay-modal-footer {
  margin-top: 0.85rem;
  padding-top: 0.85rem;
  border-top: 1px solid rgba(148, 163, 184, 0.15);
}

.pay-total {
  margin: 0 0 0.65rem;
  font-size: 0.9rem;
  color: #e2e8f0;
}

.pay-modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.65rem;
  flex-wrap: wrap;
}
</style>
