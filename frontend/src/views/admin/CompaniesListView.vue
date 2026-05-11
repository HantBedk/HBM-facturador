<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import {
  createCompany,
  createCompanyRecurringService,
  deleteCompany,
  fetchWelcomeMailAttachmentReady,
  deleteCompanyRecurringService,
  fetchAdminCompanies,
  fetchCompanyMonthlyDashboard,
  fetchCompanyRecurringServices,
  updateCompany,
  updateCompanyRecurringService,
} from '@/services/companiesApi.js'
import { fetchAdminInvoices } from '@/services/invoicesApi.js'
import { fetchServices } from '@/services/servicesApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'
import { useClientSortedRows } from '@/composables/useClientSortedRows.js'
import { tableAriaSort, tableSortIndicator } from '@/utils/tableSort.js'
import {
  formatDate,
  formatServiceDate,
  moneyCOP,
  MONTH_NAMES,
  normalizeFacturaSigla,
  prevCalendarMonth,
  serviceStatusLabel,
} from './companiesListHelpers.js'

const uiDialog = useUiDialogStore()

const rows = ref([])
const deletingId = ref(null)
const loading = ref(false)
const error = ref('')
const search = ref('')

const modalOpen = ref(false)
const modalMode = ref('create')
const editingId = ref(null)
/** Si al abrir edición la empresa ya tenía correo (no reenviar bienvenida al guardar). */
const editingHadCorreo = ref(false)
const saving = ref(false)
const modalError = ref('')
const fieldErrors = ref({})

const form = ref({
  nombre: '',
  factura_sigla: '',
  nit: '',
  direccion: '',
  telefono: '',
  correo: '',
  estado: 'activo',
})

let searchTimer = null

const PANEL_PER_PAGE = 100

const companyPanelOpen = ref(false)
const companyPanelCompany = ref(null)
/** 'todo' | 'facturas' | 'servicios' */
const companyPanelFilter = ref('todo')
const companyPanelError = ref('')

const companyInvoices = ref([])
const invoiceMeta = ref(null)
const invoicesLoading = ref(false)
const invoicePage = ref(1)

const companyServices = ref([])
const serviceMeta = ref(null)
const servicesLoading = ref(false)
const servicePage = ref(1)

const invoicePanelSortKey = ref('period')
const invoicePanelSortDir = ref('desc')
const servicePanelSortKey = ref('service_date')
const servicePanelSortDir = ref('desc')
const PANEL_INV_SORT_FIRST = {
  code: 'asc',
  period: 'desc',
  status: 'asc',
  total: 'desc',
}
const PANEL_SRV_SORT_FIRST = {
  code: 'asc',
  service_date: 'desc',
  client_name: 'asc',
  amount: 'desc',
  status: 'asc',
}

const dashboardLoading = ref(false)
const dashboardError = ref('')
const dashboardData = ref(null)
/** Mes analizado (1–12); al abrir el panel = mes calendario anterior. */
const dashboardMonth = ref(1)
const dashboardYear = ref(new Date().getFullYear())

const recurringRows = ref([])
const recurringLoading = ref(false)
const recurringError = ref('')
const recurringModalOpen = ref(false)
const recurringModalMode = ref('create')
const recurringEditingId = ref(null)
const recurringSaving = ref(false)
const recurringModalError = ref('')

const RECURRING_BILLING_KIND_OPTIONS = [
  { value: 'venta', label: 'Venta' },
  { value: 'servicio', label: 'Servicio' },
  { value: 'alquiler', label: 'Alquiler' },
]

function recurringBillingKindLabel(k) {
  const key = k && String(k).trim() !== '' ? k : 'servicio'
  return RECURRING_BILLING_KIND_OPTIONS.find((o) => o.value === key)?.label || '—'
}

const recurringForm = ref({
  billing_kind: '',
  amount: '',
  description: '',
  service_type: '',
  is_active: true,
})

/** Empresa objetivo del modal de servicios fijos (tabla o panel). */
const recurringModalCompany = ref(null)

/** Listado mostrado dentro del modal (independiente del panel lateral). */
const recurringModalRows = ref([])
const recurringModalListLoading = ref(false)
const recurringModalListError = ref('')
/** Si true, se muestra el formulario de alta/edición debajo del listado. */
const recurringFormSectionOpen = ref(false)

const deleteCompanyModalOpen = ref(false)
const deleteCompanyTarget = ref(null)
const deleteCompanyConfirmInput = ref('')
const deleteCompanyModalError = ref('')
const deleteCompanyInputRef = ref(null)

const {
  sortedRows: sortedCompanies,
  toggleSort: toggleCompanyColSort,
  sortIndicator: companySortInd,
  ariaSort: companyAriaSort,
} = useClientSortedRows(
  rows,
  {
    nombre: (c) => c.nombre || '',
    factura_sigla: (c) => c.factura_sigla || '',
    nit: (c) => c.nit || '',
    contacto: (c) => [c.telefono, c.correo].filter(Boolean).join(' ') || '',
    created_at: (c) => c.created_at || '',
  },
  { initialKey: 'nombre', initialDir: 'asc' }
)

const directoryPageTitle = 'Empresas registradas'

const dashboardYearOptions = computed(() => {
  const y = new Date().getFullYear()
  return [y, y - 1, y - 2]
})

const dashboardPeriodTitle = computed(() => {
  const p = dashboardData.value?.period
  if (!p) return ''
  const name = MONTH_NAMES[(p.month || 1) - 1] || ''
  return `${name} ${p.year}`
})

function openCompanyPanel(row) {
  companyPanelCompany.value = row
  companyPanelFilter.value = 'todo'
  companyPanelError.value = ''
  invoicePage.value = 1
  servicePage.value = 1
  companyInvoices.value = []
  companyServices.value = []
  invoiceMeta.value = null
  serviceMeta.value = null
  dashboardData.value = null
  dashboardError.value = ''
  const pm = prevCalendarMonth()
  dashboardYear.value = pm.year
  dashboardMonth.value = pm.month
  invoicePanelSortKey.value = 'period'
  invoicePanelSortDir.value = 'desc'
  servicePanelSortKey.value = 'service_date'
  servicePanelSortDir.value = 'desc'
  companyPanelOpen.value = true
  loadCompanyPanelData()
  loadCompanyDashboard()
  loadCompanyRecurringServices()
}

function closeCompanyPanel() {
  companyPanelOpen.value = false
  companyPanelCompany.value = null
  companyPanelError.value = ''
  dashboardData.value = null
  dashboardError.value = ''
  recurringRows.value = []
  recurringError.value = ''
  recurringModalOpen.value = false
  recurringModalCompany.value = null
}

function resolveRecurringCompany() {
  return recurringModalCompany.value ?? companyPanelCompany.value
}

function recurringServicesCompanyId() {
  if (recurringModalOpen.value && recurringModalCompany.value?.id) {
    return recurringModalCompany.value.id
  }
  return companyPanelCompany.value?.id
}

async function loadRecurringModalList() {
  const id = recurringModalCompany.value?.id
  if (!id) return
  recurringModalListLoading.value = true
  recurringModalListError.value = ''
  try {
    recurringModalRows.value = await fetchCompanyRecurringServices(id)
  } catch (e) {
    recurringModalListError.value =
      e.data?.message || e.message || 'No se pudieron cargar los servicios fijos de la empresa.'
    recurringModalRows.value = []
  } finally {
    recurringModalListLoading.value = false
  }
}

async function loadCompanyRecurringServices() {
  const id = companyPanelCompany.value?.id
  if (!id) return
  recurringLoading.value = true
  recurringError.value = ''
  try {
    recurringRows.value = await fetchCompanyRecurringServices(id)
  } catch (e) {
    recurringError.value = e.data?.message || e.message || 'No se pudieron cargar los servicios fijos.'
    recurringRows.value = []
  } finally {
    recurringLoading.value = false
  }
}

/**
 * @param {object} [companyRow] Fila de la tabla; si omite y el panel está abierto, usa la empresa del panel.
 */
async function openRecurringCreate(companyRow) {
  const co = companyRow ?? companyPanelCompany.value
  if (!co?.id) {
    await uiDialog.alert({
      title: 'Empresa',
      message: 'Use el ícono de servicios fijos (calendario) en la columna Acciones de la fila de la empresa.',
    })
    return
  }
  recurringModalCompany.value = co
  recurringModalOpen.value = true
  recurringFormSectionOpen.value = false
  recurringModalError.value = ''
  recurringModalListError.value = ''
  recurringModalMode.value = 'create'
  recurringEditingId.value = null
  recurringForm.value = {
    billing_kind: '',
    amount: '',
    description: '',
    service_type: '',
    is_active: true,
  }
  await loadRecurringModalList()
}

async function openRecurringShowFormCreate() {
  recurringModalMode.value = 'create'
  recurringEditingId.value = null
  recurringModalError.value = ''
  recurringForm.value = {
    billing_kind: '',
    amount: '',
    description: '',
    service_type: '',
    is_active: true,
  }
  recurringFormSectionOpen.value = true
}

function applyRecurringFormFromRow(row) {
  recurringModalMode.value = 'edit'
  recurringEditingId.value = row.id
  recurringForm.value = {
    billing_kind: row.billing_kind || 'servicio',
    amount: String(row.amount ?? ''),
    description: row.description || '',
    service_type: row.service_type || '',
    is_active: row.is_active !== false,
  }
}

async function openRecurringModalEditRow(row) {
  recurringModalError.value = ''
  applyRecurringFormFromRow(row)
  recurringFormSectionOpen.value = true
}

function cancelRecurringFormSection() {
  recurringFormSectionOpen.value = false
  recurringModalError.value = ''
}

async function openRecurringEdit(row) {
  const co = companyPanelCompany.value
  if (!co?.id) return
  recurringModalCompany.value = co
  recurringModalOpen.value = true
  recurringFormSectionOpen.value = false
  recurringModalError.value = ''
  recurringModalListError.value = ''
  await loadRecurringModalList()
  applyRecurringFormFromRow(row)
  recurringFormSectionOpen.value = true
}

function closeRecurringModal() {
  recurringModalOpen.value = false
  recurringModalCompany.value = null
  recurringFormSectionOpen.value = false
  recurringModalRows.value = []
  recurringModalListError.value = ''
}

async function submitRecurringModal() {
  const company = resolveRecurringCompany()
  const companyId = company?.id
  if (!companyId) {
    recurringModalError.value = 'No se identificó la empresa. Cierre y abra de nuevo desde Acciones.'
    return
  }
  const savedTargetCompanyId = companyId
  recurringModalError.value = ''
  recurringSaving.value = true
  try {
    const bk = String(recurringForm.value.billing_kind || '').trim()
    if (!['venta', 'servicio', 'alquiler'].includes(bk)) {
      recurringModalError.value = 'Seleccione el tipo de cargo: Venta, Servicio o Alquiler.'
      return
    }
    const amt = Number(String(recurringForm.value.amount).replace(/\s/g, '').replace(',', '.'))
    if (!Number.isFinite(amt) || amt < 0.01) {
      recurringModalError.value = 'Indique un importe válido (mín. 0,01).'
      return
    }
    const payload = {
      billing_kind: bk,
      amount: amt,
      description: recurringForm.value.description.trim() || null,
      service_type: recurringForm.value.service_type.trim() || null,
      is_active: Boolean(recurringForm.value.is_active),
    }
    if (recurringModalMode.value === 'create') {
      await createCompanyRecurringService(companyId, payload)
    } else {
      await updateCompanyRecurringService(companyId, recurringEditingId.value, payload)
    }
    recurringFormSectionOpen.value = false
    recurringModalError.value = ''
    await loadRecurringModalList()
    if (companyPanelOpen.value && companyPanelCompany.value?.id === savedTargetCompanyId) {
      await loadCompanyRecurringServices()
    }
  } catch (e) {
    recurringModalError.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    recurringSaving.value = false
  }
}

async function toggleRecurringActive(row) {
  const companyId = recurringServicesCompanyId()
  if (!companyId) return
  try {
    await updateCompanyRecurringService(companyId, row.id, { is_active: !row.is_active })
    await loadCompanyRecurringServices()
    if (recurringModalOpen.value) await loadRecurringModalList()
  } catch (e) {
    const msg = e.data?.message || e.message || 'No se pudo actualizar el estado.'
    await uiDialog.alert({ title: 'Error', message: msg })
  }
}

async function deleteRecurringRow(row) {
  const companyId = recurringServicesCompanyId()
  if (!companyId) return
  const ok = await uiDialog.confirm({
    title: 'Quitar servicio fijo',
    message: `¿Eliminar esta plantilla de la empresa? No borra líneas ya generadas en facturas pasadas.`,
    danger: true,
    confirmLabel: 'Eliminar',
  })
  if (!ok) return
  try {
    await deleteCompanyRecurringService(companyId, row.id)
    if (recurringEditingId.value === row.id) {
      cancelRecurringFormSection()
      recurringEditingId.value = null
    }
    await loadCompanyRecurringServices()
    if (recurringModalOpen.value) await loadRecurringModalList()
  } catch (e) {
    await uiDialog.alert({
      title: 'No se pudo eliminar',
      message: e.data?.message || e.message || 'Error',
    })
  }
}

async function loadCompanyDashboard() {
  const id = companyPanelCompany.value?.id
  if (!id) return
  dashboardLoading.value = true
  dashboardError.value = ''
  try {
    dashboardData.value = await fetchCompanyMonthlyDashboard(id, {
      year: dashboardYear.value,
      month: dashboardMonth.value,
    })
  } catch (e) {
    dashboardError.value = e.data?.message || e.message || 'No se pudieron cargar las métricas del mes.'
    dashboardData.value = null
  } finally {
    dashboardLoading.value = false
  }
}

async function loadCompanyPanelInvoices() {
  const id = companyPanelCompany.value?.id
  if (!id) return
  invoicesLoading.value = true
  try {
    const res = await fetchAdminInvoices({
      company_id: id,
      page: invoicePage.value,
      per_page: PANEL_PER_PAGE,
      sort: invoicePanelSortKey.value,
      sort_dir: invoicePanelSortDir.value,
    })
    companyInvoices.value = res.data ?? []
    invoiceMeta.value = res.meta ?? null
  } catch (e) {
    companyPanelError.value = e.data?.message || e.message || 'No se pudieron cargar las facturas.'
    companyInvoices.value = []
    invoiceMeta.value = null
  } finally {
    invoicesLoading.value = false
  }
}

async function loadCompanyPanelServices() {
  const id = companyPanelCompany.value?.id
  if (!id) return
  servicesLoading.value = true
  try {
    const res = await fetchServices({
      company_id: id,
      page: servicePage.value,
      per_page: PANEL_PER_PAGE,
      sort: servicePanelSortKey.value,
      sort_dir: servicePanelSortDir.value,
    })
    companyServices.value = res.data ?? []
    serviceMeta.value = res.meta ?? null
  } catch (e) {
    companyPanelError.value = e.data?.message || e.message || 'No se pudieron cargar los servicios.'
    companyServices.value = []
    serviceMeta.value = null
  } finally {
    servicesLoading.value = false
  }
}

async function loadCompanyPanelData() {
  const id = companyPanelCompany.value?.id
  if (!id) return
  companyPanelError.value = ''
  const f = companyPanelFilter.value
  const tasks = []
  if (f === 'todo' || f === 'facturas') tasks.push(loadCompanyPanelInvoices())
  if (f === 'todo' || f === 'servicios') tasks.push(loadCompanyPanelServices())
  await Promise.all(tasks)
}

function goInvoicePage(delta) {
  const m = invoiceMeta.value
  if (!m) return
  const target = m.current_page + delta
  if (target < 1 || target > m.last_page) return
  invoicePage.value = target
  loadCompanyPanelInvoices()
}

function goServicePage(delta) {
  const m = serviceMeta.value
  if (!m) return
  const target = m.current_page + delta
  if (target < 1 || target > m.last_page) return
  servicePage.value = target
  loadCompanyPanelServices()
}

function panelInvSortInd(k) {
  return tableSortIndicator(invoicePanelSortKey.value, invoicePanelSortDir.value, k)
}

function panelInvAriaSort(k) {
  return tableAriaSort(invoicePanelSortKey.value, invoicePanelSortDir.value, k)
}

async function togglePanelInvSort(key) {
  if (invoicePanelSortKey.value === key) {
    invoicePanelSortDir.value = invoicePanelSortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    invoicePanelSortKey.value = key
    invoicePanelSortDir.value = PANEL_INV_SORT_FIRST[key] || 'asc'
  }
  invoicePage.value = 1
  await loadCompanyPanelInvoices()
}

function panelSrvSortInd(k) {
  return tableSortIndicator(servicePanelSortKey.value, servicePanelSortDir.value, k)
}

function panelSrvAriaSort(k) {
  return tableAriaSort(servicePanelSortKey.value, servicePanelSortDir.value, k)
}

async function togglePanelSrvSort(key) {
  if (servicePanelSortKey.value === key) {
    servicePanelSortDir.value = servicePanelSortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    servicePanelSortKey.value = key
    servicePanelSortDir.value = PANEL_SRV_SORT_FIRST[key] || 'asc'
  }
  servicePage.value = 1
  await loadCompanyPanelServices()
}

watch(companyPanelFilter, () => {
  if (!companyPanelOpen.value) return
  invoicePage.value = 1
  servicePage.value = 1
  loadCompanyPanelData()
})

function onCompanyPanelKeydown(ev) {
  if (ev.key !== 'Escape') return
  if (recurringModalOpen.value) {
    ev.preventDefault()
    closeRecurringModal()
    return
  }
  if (deleteCompanyModalOpen.value) {
    ev.preventDefault()
    closeDeleteCompanyModal()
    return
  }
  if (!companyPanelOpen.value) return
  ev.preventDefault()
  closeCompanyPanel()
}

async function load() {
  error.value = ''
  loading.value = true
  try {
    const params = { q: search.value, company_kind: 'registered' }
    rows.value = await fetchAdminCompanies(params)
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudieron cargar las empresas.'
    rows.value = []
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  document.addEventListener('keydown', onCompanyPanelKeydown)
  load()
})

onUnmounted(() => {
  document.removeEventListener('keydown', onCompanyPanelKeydown)
  clearTimeout(searchTimer)
})

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
    direccion: '',
    telefono: '',
    correo: '',
    estado: 'activo',
  }
  modalOpen.value = true
}

function openEdit(row) {
  modalMode.value = 'edit'
  editingId.value = row.id
  editingHadCorreo.value = !!(row.correo && String(row.correo).trim())
  modalError.value = ''
  fieldErrors.value = {}
  form.value = {
    nombre: row.nombre || '',
    factura_sigla: (row.factura_sigla || '').toString().toUpperCase().slice(0, 3),
    nit: row.nit || '',
    direccion: row.direccion || '',
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

const deleteExpectedSigla = computed(() =>
  deleteCompanyTarget.value ? normalizeFacturaSigla(deleteCompanyTarget.value.factura_sigla) : ''
)

/** Si hay correo y no hay PDF de bienvenida, confirma antes de disparar el envío. */
async function confirmWelcomePdfIfCorreo(correo) {
  if (!correo || !String(correo).trim()) return true
  try {
    const st = await fetchWelcomeMailAttachmentReady()
    if (!st.welcome_pdf_ready) {
      const creating = modalMode.value === 'create'
      const ok = await uiDialog.confirm({
        title: 'Bienvenida sin PDF adjunto',
        message:
          'No hay PDF de bienvenida configurado o el archivo no está disponible. Se programará el envío del correo solo con el texto de la plantilla (sin adjunto), en segundo plano tras guardar. ¿Continuar?',
        confirmLabel: creating ? 'Crear empresa' : 'Guardar',
        cancelLabel: 'Volver',
      })
      return ok
    }
  } catch (e) {
    modalError.value =
      e.data?.message || e.message || 'No se pudo comprobar si hay PDF de bienvenida.'
    return false
  }
  return true
}

/** Tras guardar: aviso si el bienvenida quedó en cola; error solo si el API aún devolviera fallo síncrono. */
async function notifyWelcomeMailOutcome(welcomeMail) {
  if (!welcomeMail) return
  if (welcomeMail.skipped_reason === 'no_correo') return
  if (welcomeMail.queued) {
    const dest = welcomeMail.to ? ` (${welcomeMail.to})` : ''
    await uiDialog.alert({
      title: 'Correo de bienvenida',
      message: `La empresa se guardó. El correo de bienvenida${dest} se enviará en segundo plano; recibirá una notificación en el panel cuando termine (éxito o error).`,
    })
    return
  }
  if (!welcomeMail.sent && welcomeMail.skipped_reason === 'send_failed') {
    const extra = welcomeMail.detail ? `\n\nDetalle: ${welcomeMail.detail}` : ''
    const dest = welcomeMail.to ? ` a ${welcomeMail.to}` : ''
    await uiDialog.alert({
      title: 'No se envió el correo de bienvenida',
      message: `La empresa se guardó, pero el envío${dest} falló.${extra}\n\nRevise Admin → Correo del sistema (SMTP y remitente) y storage/logs/laravel.log.`,
    })
  }
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
      direccion: form.value.direccion.trim(),
      telefono: form.value.telefono.trim() || null,
      correo: form.value.correo.trim() || null,
      estado: form.value.estado,
    }
    if (modalMode.value === 'create') {
      if (payload.correo) {
        const okPdf = await confirmWelcomePdfIfCorreo(payload.correo)
        if (!okPdf) {
          saving.value = false
          return
        }
      }
      const res = await createCompany(payload)
      modalOpen.value = false
      await load()
      await notifyWelcomeMailOutcome(res.welcome_mail)
    } else {
      const addedFirstCorreo = !editingHadCorreo.value && !!payload.correo
      if (addedFirstCorreo) {
        const okPdf = await confirmWelcomePdfIfCorreo(payload.correo)
        if (!okPdf) {
          saving.value = false
          return
        }
      }
      const res = await updateCompany(editingId.value, payload)
      modalOpen.value = false
      await load()
      if (res.welcome_mail) await notifyWelcomeMailOutcome(res.welcome_mail)
    }
  } catch (e) {
    if (e.data?.errors) fieldErrors.value = e.data.errors
    else modalError.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}

function openDeleteCompanyModal(row) {
  deleteCompanyTarget.value = row
  deleteCompanyConfirmInput.value = ''
  deleteCompanyModalError.value = ''
  deleteCompanyModalOpen.value = true
  nextTick(() => deleteCompanyInputRef.value?.focus())
}

function closeDeleteCompanyModal() {
  deleteCompanyModalOpen.value = false
  deleteCompanyTarget.value = null
  deleteCompanyConfirmInput.value = ''
  deleteCompanyModalError.value = ''
}

async function submitDeleteCompanyModal() {
  const row = deleteCompanyTarget.value
  if (!row) return
  const expected = deleteExpectedSigla.value
  if (!expected) {
    deleteCompanyModalError.value =
      'Esta empresa no tiene sigla de factura válida. Asigne una en «Editar» antes de eliminar.'
    return
  }
  const typed = normalizeFacturaSigla(deleteCompanyConfirmInput.value)
  if (typed !== expected) {
    deleteCompanyModalError.value =
      'La sigla no coincide. Escriba las tres letras exactas de la columna «Sigla factura» (mayúsculas o minúsculas).'
    return
  }
  deleteCompanyModalError.value = ''
  deletingId.value = row.id
  try {
    await deleteCompany(row.id)
    closeDeleteCompanyModal()
    if (companyPanelOpen.value && companyPanelCompany.value?.id === row.id) {
      closeCompanyPanel()
    }
    await load()
  } catch (e) {
    const msg =
      e.data?.errors?.company?.[0] ||
      e.data?.message ||
      e.message ||
      'No se pudo eliminar la empresa.'
    await uiDialog.alert({ title: 'No se pudo eliminar', message: msg })
  } finally {
    deletingId.value = null
  }
}
</script>

<template>
  <section class="page page--fluid">
    <header class="head">
      <div class="head-main">
        <div class="head-title-row">
          <h1>{{ directoryPageTitle }}</h1>
          <button type="button" class="btn primary" @click="openCreate">+ Nueva empresa</button>
        </div>
        <p class="lede">
          <strong>Empresas registradas</strong> son las que usted crea aquí (alta manual): cuentas B2B que controla la
          administración. Las ventas ocasionales sin alta no generan filas en este directorio; el dato del comprador queda en el
          servicio y en la factura emitida. Solo las empresas activas aparecen al registrar servicios para técnicos. Para
          eliminar una empresa debe cumplir condiciones (sin dependencias); confirme escribiendo la sigla de factura. Pulse el
          nombre para ver facturas, servicios fijos, servicios y métricas.
        </p>
      </div>
    </header>

    <div class="toolbar card">
      <label class="grow">
        <span class="lbl">Buscar</span>
        <input v-model="search" type="search" class="input" placeholder="Nombre, NIT o correo…" />
      </label>
    </div>

    <p v-if="error" class="banner err">{{ error }}</p>

    <div class="card table-wrap">
      <div v-if="loading" class="muted pad">Cargando…</div>
      <template v-else>
        <table class="table">
          <thead>
            <tr>
              <th scope="col" :aria-sort="companyAriaSort('nombre')">
                <button type="button" class="th-sort" @click="toggleCompanyColSort('nombre')">
                  Empresa<span class="sort-ind" aria-hidden="true">{{ companySortInd('nombre') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="companyAriaSort('factura_sigla')">
                <button type="button" class="th-sort" @click="toggleCompanyColSort('factura_sigla')">
                  Sigla factura<span class="sort-ind" aria-hidden="true">{{ companySortInd('factura_sigla') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="companyAriaSort('nit')">
                <button type="button" class="th-sort" @click="toggleCompanyColSort('nit')">
                  NIT / ID<span class="sort-ind" aria-hidden="true">{{ companySortInd('nit') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="companyAriaSort('contacto')">
                <button type="button" class="th-sort" @click="toggleCompanyColSort('contacto')">
                  Contacto<span class="sort-ind" aria-hidden="true">{{ companySortInd('contacto') }}</span>
                </button>
              </th>
              <th scope="col" :aria-sort="companyAriaSort('created_at')">
                <button type="button" class="th-sort" @click="toggleCompanyColSort('created_at')">
                  Creada<span class="sort-ind" aria-hidden="true">{{ companySortInd('created_at') }}</span>
                </button>
              </th>
              <th class="actions-col">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in sortedCompanies" :key="c.id">
              <td>
                <button type="button" class="name-link" :title="`Ver facturas y servicios de ${c.nombre}`" @click="openCompanyPanel(c)">
                  {{ c.nombre }}
                </button>
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
              <td class="muted">{{ formatDate(c.created_at) }}</td>
              <td class="actions-col">
                <div class="actions-wrap">
                  <RouterLink
                    :to="{
                      name: 'admin-empresa-inventario',
                      params: { companyId: String(c.id) },
                      state: { empresaNombre: c.nombre || '' },
                    }"
                    class="icon-act icon-act--inventory"
                    title="Inventario y activos de esta empresa"
                    @click.stop
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"
                      />
                    </svg>
                  </RouterLink>
                  <button
                    type="button"
                    class="icon-act icon-act--recurring"
                    title="Servicios fijos mensuales (cargos recurrentes en facturación)"
                    @click.stop="openRecurringCreate(c)"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
                      />
                    </svg>
                  </button>
                  <button type="button" class="icon-act" title="Editar empresa" @click="openEdit(c)">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                      />
                    </svg>
                  </button>
                  <button
                    type="button"
                    class="icon-act icon-act--danger"
                    title="Eliminar empresa"
                    :disabled="deletingId === c.id"
                    @click="openDeleteCompanyModal(c)"
                  >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                      />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="6" class="empty muted">No hay empresas con estos criterios.</td>
            </tr>
          </tbody>
        </table>
      </template>
    </div>

    <Teleport to="body">
      <div
        v-if="companyPanelOpen"
        class="modal-backdrop panel-backdrop"
        role="presentation"
        @click.self="closeCompanyPanel"
      >
        <div
          class="modal card panel-modal"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="'company-panel-title'"
          @click.stop
        >
          <div class="panel-head">
            <div>
              <h2 id="company-panel-title" class="panel-title">Actividad de la empresa</h2>
              <p v-if="companyPanelCompany" class="panel-sub muted">{{ companyPanelCompany.nombre }}</p>
            </div>
            <button type="button" class="btn secondary panel-close" aria-label="Cerrar" @click="closeCompanyPanel">✕</button>
          </div>

          <div class="panel-kpi-block card kpi-card-outer">
            <div class="kpi-toolbar">
              <h3 class="panel-h3 kpi-block-title">Resumen del mes</h3>
              <div class="kpi-month-picks">
                <label class="kpi-pick">
                  <span class="lbl">Mes</span>
                  <select v-model.number="dashboardMonth" class="input input--sm" @change="loadCompanyDashboard">
                    <option v-for="m in 12" :key="m" :value="m">{{ MONTH_NAMES[m - 1] }}</option>
                  </select>
                </label>
                <label class="kpi-pick">
                  <span class="lbl">Año</span>
                  <select v-model.number="dashboardYear" class="input input--sm" @change="loadCompanyDashboard">
                    <option v-for="y in dashboardYearOptions" :key="y" :value="y">{{ y }}</option>
                  </select>
                </label>
              </div>
            </div>
            <p v-if="dashboardPeriodTitle" class="kpi-period-label muted">
              Cifras para <strong>{{ dashboardPeriodTitle }}</strong> (servicios por fecha; facturas por periodo contable; cobros en calendario).
            </p>
            <p v-if="dashboardError" class="banner err kpi-banner">{{ dashboardError }}</p>
            <div v-else-if="dashboardLoading" class="muted pad-sm">Cargando métricas…</div>
            <template v-else-if="dashboardData">
              <div class="kpi-grid">
                <div class="kpi-tile">
                  <span class="kpi-label">Servicios en el mes</span>
                  <span class="kpi-value">{{ dashboardData.last_month.services_count }}</span>
                  <span class="kpi-hint">Registros con fecha de servicio en el mes.</span>
                </div>
                <div class="kpi-tile">
                  <span class="kpi-label">Valor servicios (mes)</span>
                  <span class="kpi-value">{{ moneyCOP(dashboardData.last_month.services_total_amount) }}</span>
                  <span class="kpi-hint">Suma de importes de esos servicios.</span>
                </div>
                <div class="kpi-tile">
                  <span class="kpi-label">Facturas del periodo</span>
                  <span class="kpi-value">{{ dashboardData.last_month.invoices_for_period_count }}</span>
                  <span class="kpi-hint">Facturas con periodo contable {{ dashboardPeriodTitle }}.</span>
                </div>
                <div class="kpi-tile">
                  <span class="kpi-label">Total facturado (periodo)</span>
                  <span class="kpi-value">{{ moneyCOP(dashboardData.last_month.invoices_for_period_billed_total) }}</span>
                  <span class="kpi-hint">Suma de totales de esas facturas.</span>
                </div>
                <div class="kpi-tile">
                  <span class="kpi-label">Pagado sobre esas facturas</span>
                  <span class="kpi-value">{{ moneyCOP(dashboardData.last_month.invoices_for_period_paid_total) }}</span>
                  <span class="kpi-hint">Pagos registrados (cualquier fecha) aplicados a facturas de ese periodo.</span>
                </div>
                <div class="kpi-tile kpi-tile--warn">
                  <span class="kpi-label">Saldo pendiente (periodo)</span>
                  <span class="kpi-value">{{ moneyCOP(dashboardData.last_month.invoices_for_period_balance) }}</span>
                  <span class="kpi-hint">Lo que aún deben en facturas de ese periodo.</span>
                </div>
                <div class="kpi-tile kpi-tile--accent">
                  <span class="kpi-label">Cobrado en el mes (calendario)</span>
                  <span class="kpi-value">{{ moneyCOP(dashboardData.last_month.payments_received_in_calendar_month) }}</span>
                  <span class="kpi-hint">Dinero ingresado por pagos con fecha en el mes (toda factura de la empresa).</span>
                </div>
                <div class="kpi-tile">
                  <span class="kpi-label">Promedio mensual facturado</span>
                  <span class="kpi-value">{{ moneyCOP(dashboardData.averages_last_12_months.avg_billed_per_month) }}</span>
                  <span class="kpi-hint">Media de total facturado por periodo en los últimos 12 meses.</span>
                </div>
                <div class="kpi-tile">
                  <span class="kpi-label">Promedio mensual cobrado</span>
                  <span class="kpi-value">{{ moneyCOP(dashboardData.averages_last_12_months.avg_collected_per_month) }}</span>
                  <span class="kpi-hint">Media de cobros por calendario en los últimos 12 meses.</span>
                </div>
              </div>
            </template>
          </div>

          <label class="panel-filter">
            <span class="lbl">Mostrar</span>
            <select v-model="companyPanelFilter" class="input">
              <option value="todo">Facturas y servicios</option>
              <option value="facturas">Solo facturas</option>
              <option value="servicios">Solo servicios</option>
            </select>
          </label>

          <div class="panel-section panel-section--recurring">
            <div class="recurring-panel-head">
              <h3 class="panel-h3 recurring-section-title">Servicios fijos mensuales</h3>
              <button
                type="button"
                class="btn secondary btn-sm"
                title="Listado completo, alta y edición en una sola ventana"
                @click="openRecurringCreate(companyPanelCompany)"
              >
                Gestionar todo
              </button>
            </div>
            <p class="muted pad-sm recurring-hint">
              Cargos que se convierten en líneas de factura en cada generación automática de borradores. Use
              <strong>Gestionar todo</strong> o el ícono de calendario en <strong>Acciones</strong> para ver, añadir, editar o
              quitar plantillas en un solo lugar. Desde aquí también puede usar los botones de cada fila.
            </p>
            <p v-if="recurringError" class="banner err panel-banner">{{ recurringError }}</p>
            <div v-if="recurringLoading" class="muted pad-sm">Cargando servicios fijos…</div>
            <template v-else-if="!recurringRows.length">
              <p class="muted pad-sm">No hay servicios fijos configurados para esta empresa.</p>
            </template>
            <template v-else>
              <div class="panel-table-wrap">
                <table class="panel-table">
                  <thead>
                    <tr>
                      <th scope="col">Tipo</th>
                      <th scope="col">Detalle</th>
                      <th class="num" scope="col">Importe</th>
                      <th scope="col">Estado</th>
                      <th class="actions-col">Acciones</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="r in recurringRows" :key="'rec-' + r.id">
                      <td>{{ recurringBillingKindLabel(r.billing_kind) }}</td>
                      <td class="muted">
                        <span v-if="r.service_type" class="recurring-type">{{ r.service_type }}</span>
                        <span v-if="r.description">{{ r.description }}</span>
                        <span v-if="!r.service_type && !r.description">—</span>
                      </td>
                      <td class="num">{{ moneyCOP(r.amount) }}</td>
                      <td>
                        <span :class="r.is_active ? 'tag tag--ok' : 'tag tag--off'">
                          {{ r.is_active ? 'Activo' : 'Suspendido' }}
                        </span>
                      </td>
                      <td class="actions-col">
                        <div class="recurring-actions">
                          <button type="button" class="btn secondary btn-sm" @click="toggleRecurringActive(r)">
                            {{ r.is_active ? 'Suspender' : 'Reactivar' }}
                          </button>
                          <button type="button" class="btn secondary btn-sm" @click="openRecurringEdit(r)">Editar</button>
                          <button type="button" class="btn secondary btn-sm danger-text" @click="deleteRecurringRow(r)">
                            Eliminar
                          </button>
                        </div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </template>
          </div>

          <p v-if="companyPanelError" class="banner err panel-banner">{{ companyPanelError }}</p>

          <div v-if="companyPanelFilter !== 'servicios'" class="panel-section">
            <h3 class="panel-h3">Facturas</h3>
            <div v-if="invoicesLoading" class="muted pad-sm">Cargando facturas…</div>
            <template v-else-if="!companyInvoices.length">
              <p class="muted pad-sm">No hay facturas para esta empresa.</p>
            </template>
            <template v-else>
              <div class="panel-table-wrap">
                <table class="panel-table">
                  <thead>
                    <tr>
                      <th scope="col" :aria-sort="panelInvAriaSort('code')">
                        <button type="button" class="th-sort" @click="togglePanelInvSort('code')">
                          Código<span class="sort-ind" aria-hidden="true">{{ panelInvSortInd('code') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="panelInvAriaSort('period')">
                        <button type="button" class="th-sort" @click="togglePanelInvSort('period')">
                          Periodo<span class="sort-ind" aria-hidden="true">{{ panelInvSortInd('period') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="panelInvAriaSort('status')">
                        <button type="button" class="th-sort" @click="togglePanelInvSort('status')">
                          Estado<span class="sort-ind" aria-hidden="true">{{ panelInvSortInd('status') }}</span>
                        </button>
                      </th>
                      <th class="num" scope="col" :aria-sort="panelInvAriaSort('total')">
                        <button type="button" class="th-sort th-sort--end" @click="togglePanelInvSort('total')">
                          Total<span class="sort-ind" aria-hidden="true">{{ panelInvSortInd('total') }}</span>
                        </button>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="inv in companyInvoices" :key="'inv-' + inv.id">
                      <td>
                        <RouterLink class="panel-link" :to="`/admin/facturas/${inv.id}`" @click="closeCompanyPanel">
                          {{ inv.code }}
                        </RouterLink>
                      </td>
                      <td class="muted">{{ inv.period_label || '—' }}</td>
                      <td>{{ inv.status_label || inv.status }}</td>
                      <td class="num">{{ moneyCOP(inv.total) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="invoiceMeta && invoiceMeta.last_page > 1" class="panel-pager">
                <button
                  type="button"
                  class="btn secondary btn-sm"
                  :disabled="invoiceMeta.current_page <= 1"
                  @click="goInvoicePage(-1)"
                >
                  Anterior
                </button>
                <span class="muted pager-meta">
                  Página {{ invoiceMeta.current_page }} de {{ invoiceMeta.last_page }} ({{ invoiceMeta.total }} en total)
                </span>
                <button
                  type="button"
                  class="btn secondary btn-sm"
                  :disabled="invoiceMeta.current_page >= invoiceMeta.last_page"
                  @click="goInvoicePage(1)"
                >
                  Siguiente
                </button>
              </div>
            </template>
          </div>

          <div v-if="companyPanelFilter !== 'facturas'" class="panel-section">
            <h3 class="panel-h3">Servicios</h3>
            <div v-if="servicesLoading" class="muted pad-sm">Cargando servicios…</div>
            <template v-else-if="!companyServices.length">
              <p class="muted pad-sm">No hay servicios para esta empresa.</p>
            </template>
            <template v-else>
              <div class="panel-table-wrap">
                <table class="panel-table">
                  <thead>
                    <tr>
                      <th scope="col" :aria-sort="panelSrvAriaSort('code')">
                        <button type="button" class="th-sort" @click="togglePanelSrvSort('code')">
                          Código<span class="sort-ind" aria-hidden="true">{{ panelSrvSortInd('code') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="panelSrvAriaSort('service_date')">
                        <button type="button" class="th-sort" @click="togglePanelSrvSort('service_date')">
                          Fecha<span class="sort-ind" aria-hidden="true">{{ panelSrvSortInd('service_date') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="panelSrvAriaSort('client_name')">
                        <button type="button" class="th-sort" @click="togglePanelSrvSort('client_name')">
                          Cliente<span class="sort-ind" aria-hidden="true">{{ panelSrvSortInd('client_name') }}</span>
                        </button>
                      </th>
                      <th class="num" scope="col" :aria-sort="panelSrvAriaSort('amount')">
                        <button type="button" class="th-sort th-sort--end" @click="togglePanelSrvSort('amount')">
                          Valor<span class="sort-ind" aria-hidden="true">{{ panelSrvSortInd('amount') }}</span>
                        </button>
                      </th>
                      <th scope="col" :aria-sort="panelSrvAriaSort('status')">
                        <button type="button" class="th-sort" @click="togglePanelSrvSort('status')">
                          Estado<span class="sort-ind" aria-hidden="true">{{ panelSrvSortInd('status') }}</span>
                        </button>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="s in companyServices" :key="'srv-' + s.id">
                      <td>
                        <RouterLink class="panel-link" :to="`/admin/servicios/${s.id}`" @click="closeCompanyPanel">
                          {{ s.code }}
                        </RouterLink>
                      </td>
                      <td class="muted">{{ formatServiceDate(s.service_date) }}</td>
                      <td>{{ s.client_name || '—' }}</td>
                      <td class="num">{{ moneyCOP(s.amount) }}</td>
                      <td>{{ serviceStatusLabel(s.status) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="serviceMeta && serviceMeta.last_page > 1" class="panel-pager">
                <button
                  type="button"
                  class="btn secondary btn-sm"
                  :disabled="serviceMeta.current_page <= 1"
                  @click="goServicePage(-1)"
                >
                  Anterior
                </button>
                <span class="muted pager-meta">
                  Página {{ serviceMeta.current_page }} de {{ serviceMeta.last_page }} ({{ serviceMeta.total }} en total)
                </span>
                <button
                  type="button"
                  class="btn secondary btn-sm"
                  :disabled="serviceMeta.current_page >= serviceMeta.last_page"
                  @click="goServicePage(1)"
                >
                  Siguiente
                </button>
              </div>
            </template>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="deleteCompanyModalOpen && deleteCompanyTarget"
        class="modal-backdrop del-co-backdrop"
        role="presentation"
        @click.self="closeDeleteCompanyModal"
      >
        <div
          class="modal card del-co-modal"
          role="dialog"
          aria-modal="true"
          aria-labelledby="del-co-title"
          @click.stop
        >
          <h2 id="del-co-title" class="modal-title">Eliminar empresa</h2>
          <p class="del-co-lede muted">
            Esta acción no se puede deshacer. Si la operación está permitida, se borrará la empresa y las facturas en cascada.
            Escriba la <strong>sigla de factura</strong> (tres letras) como comprobante.
          </p>
          <dl class="del-co-dl">
            <div>
              <dt>Empresa</dt>
              <dd>{{ deleteCompanyTarget.nombre }}</dd>
            </div>
            <div>
              <dt>Sigla a confirmar</dt>
              <dd>
                <code v-if="deleteExpectedSigla" class="sigla">{{ deleteExpectedSigla }}</code>
                <span v-else class="muted">—</span>
              </dd>
            </div>
          </dl>
          <label class="field del-co-field">
            <span>Confirmación (sigla)</span>
            <input
              ref="deleteCompanyInputRef"
              v-model="deleteCompanyConfirmInput"
              type="text"
              class="input sigla-input"
              maxlength="8"
              autocapitalize="characters"
              autocomplete="off"
              placeholder="Ej. SYF"
              @keydown.enter.prevent="submitDeleteCompanyModal"
            />
          </label>
          <p v-if="deleteCompanyModalError" class="banner err del-co-err" role="alert">{{ deleteCompanyModalError }}</p>
          <div class="modal-actions">
            <button type="button" class="btn secondary" :disabled="deletingId != null" @click="closeDeleteCompanyModal">
              Cancelar
            </button>
            <button
              type="button"
              class="btn danger"
              :disabled="
                deletingId != null ||
                !deleteCompanyConfirmInput.trim() ||
                !deleteExpectedSigla
              "
              @click="submitDeleteCompanyModal"
            >
              {{ deletingId != null ? 'Eliminando…' : 'Eliminar empresa' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

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
              <span>Dirección <abbr title="obligatorio">*</abbr></span>
              <textarea
                v-model="form.direccion"
                class="input"
                rows="2"
                required
                maxlength="512"
                placeholder="Dirección comercial o fiscal que aparecerá en la factura"
              />
              <small class="muted">Se muestra en el PDF de factura (bloque «Facturar a»).</small>
              <small v-if="fieldErrors.direccion" class="err">{{ fieldErrors.direccion[0] }}</small>
            </label>
            <label class="field">
              <span>Teléfono</span>
              <input v-model="form.telefono" class="input" maxlength="64" placeholder="Opcional" />
              <small v-if="fieldErrors.telefono" class="err">{{ fieldErrors.telefono[0] }}</small>
            </label>
            <label class="field">
              <span>Correo</span>
              <input
                v-model="form.correo"
                type="email"
                class="input"
                maxlength="255"
                placeholder="ej. contacto@empresa.com"
              />
              
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

    <Teleport to="body">
      <div
        v-if="recurringModalOpen"
        class="modal-backdrop recurring-modal-backdrop"
        role="presentation"
        @click.self="closeRecurringModal"
      >
        <div class="modal card recurring-modal" role="dialog" aria-modal="true" aria-labelledby="recurring-modal-title" @click.stop>
          <div class="recurring-modal-header">
            <h2 id="recurring-modal-title" class="modal-title recurring-modal-title-main">Servicios fijos mensuales</h2>
            <button type="button" class="btn secondary btn-sm recurring-modal-close-top" @click="closeRecurringModal">
              Cerrar
            </button>
          </div>
          <p v-if="resolveRecurringCompany()?.nombre" class="recurring-modal-company muted">
            {{ resolveRecurringCompany().nombre }}
          </p>
          <p class="muted recurring-modal-lede">
            Plantillas que se convierten en líneas de factura en cada generación automática de borradores. Puede revisar las
            existentes, añadir otra, editar o eliminar desde esta misma ventana.
          </p>

          <p v-if="recurringModalListError" class="banner err">{{ recurringModalListError }}</p>
          <div v-if="recurringModalListLoading" class="muted pad-sm">Cargando plantillas…</div>
          <template v-else>
            <div v-if="!recurringModalRows.length" class="muted pad-sm recurring-modal-empty">
              Aún no hay servicios fijos para esta empresa. Pulse «Añadir servicio fijo» para crear la primera plantilla.
            </div>
            <div v-else class="recurring-modal-table-wrap">
              <table class="recurring-modal-table">
                <thead>
                  <tr>
                    <th scope="col">Tipo</th>
                    <th scope="col">Detalle</th>
                    <th class="num" scope="col">Importe</th>
                    <th scope="col">Estado</th>
                    <th class="actions-col" scope="col">Acciones</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="r in recurringModalRows" :key="'mod-rec-' + r.id">
                    <td>{{ recurringBillingKindLabel(r.billing_kind) }}</td>
                    <td class="muted recurring-modal-detail">
                      <span v-if="r.service_type" class="recurring-type">{{ r.service_type }}</span>
                      <span v-if="r.description">{{ r.description }}</span>
                      <span v-if="!r.service_type && !r.description">—</span>
                    </td>
                    <td class="num">{{ moneyCOP(r.amount) }}</td>
                    <td>
                      <span :class="r.is_active ? 'tag tag--ok' : 'tag tag--off'">
                        {{ r.is_active ? 'Activo' : 'Suspendido' }}
                      </span>
                    </td>
                    <td class="actions-col">
                      <div class="recurring-modal-row-actions">
                        <button type="button" class="btn secondary btn-sm" @click="toggleRecurringActive(r)">
                          {{ r.is_active ? 'Suspender' : 'Reactivar' }}
                        </button>
                        <button type="button" class="btn secondary btn-sm" @click="openRecurringModalEditRow(r)">
                          Editar
                        </button>
                        <button type="button" class="btn secondary btn-sm danger-text" @click="deleteRecurringRow(r)">
                          Eliminar
                        </button>
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>

          <div class="recurring-modal-toolbar">
            <button
              v-if="!recurringFormSectionOpen"
              type="button"
              class="btn primary btn-sm"
              @click="openRecurringShowFormCreate"
            >
              + Añadir servicio fijo
            </button>
          </div>

          <p v-if="recurringModalError" class="banner err">{{ recurringModalError }}</p>

          <div v-show="recurringFormSectionOpen" class="recurring-modal-form-block">
            <h3 class="recurring-form-block-title">
              {{ recurringModalMode === 'create' ? 'Nueva plantilla' : 'Editar plantilla' }}
            </h3>
            <form class="modal-form" @submit.prevent="submitRecurringModal">
              <label class="field">
                <span>Tipo de cargo <abbr title="obligatorio">*</abbr></span>
                <select v-model="recurringForm.billing_kind" class="input" required>
                  <option disabled value="">Seleccione…</option>
                  <option v-for="opt in RECURRING_BILLING_KIND_OPTIONS" :key="opt.value" :value="opt.value">
                    {{ opt.label }}
                  </option>
                </select>
              </label>
              <label class="field">
                <span>Importe facturado (mensual) <abbr title="obligatorio">*</abbr></span>
                <input
                  v-model="recurringForm.amount"
                  class="input"
                  type="text"
                  inputmode="decimal"
                  required
                  placeholder="Ej. 150000"
                />
              </label>
              <label class="field">
                <span>Título en factura (opcional)</span>
                <input
                  v-model="recurringForm.service_type"
                  class="input"
                  maxlength="255"
                  placeholder="Si vacío, se usa una etiqueta según el tipo (Venta / Servicio / Alquiler)"
                />
              </label>
              <label class="field">
                <span>Descripción / detalle (opcional)</span>
                <textarea v-model="recurringForm.description" class="input" rows="2" placeholder="Texto en el cuerpo de la línea" />
              </label>
              <fieldset class="field">
                <legend>Plantilla</legend>
                <label class="radio">
                  <input v-model="recurringForm.is_active" type="radio" :value="true" />
                  Activa (se incluye en próximos borradores automáticos)
                </label>
                <label class="radio">
                  <input v-model="recurringForm.is_active" type="radio" :value="false" />
                  Suspendida
                </label>
              </fieldset>
              <div class="modal-actions">
                <button type="button" class="btn secondary" :disabled="recurringSaving" @click="cancelRecurringFormSection">
                  Cancelar formulario
                </button>
                <button type="submit" class="btn primary" :disabled="recurringSaving">
                  {{ recurringSaving ? 'Guardando…' : 'Guardar plantilla' }}
                </button>
              </div>
            </form>
          </div>

          <div class="recurring-modal-footer">
            <button type="button" class="btn secondary" @click="closeRecurringModal">Cerrar ventana</button>
          </div>
        </div>
      </div>
    </Teleport>
  </section>
</template>

<style scoped>
/* Mismo criterio que listado de servicios (ServicesListView): ancho completo del main. */
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
  width: 100%;
}

.head-title-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 0.4rem;
}

h1 {
  margin: 0;
  font-size: 1.35rem;
  color: #f8fafc;
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

.list-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  align-items: center;
}

.list-tab {
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.35);
  color: #cbd5e1;
  padding: 0.4rem 0.85rem;
  font: inherit;
  font-size: 0.85rem;
  cursor: pointer;
}

.list-tab:hover {
  border-color: rgba(56, 189, 248, 0.4);
  color: #e2e8f0;
}

.list-tab--on {
  border-color: rgba(56, 189, 248, 0.55);
  background: rgba(56, 189, 248, 0.12);
  color: #e0f2fe;
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

.actions-col {
  white-space: nowrap;
}

.actions-wrap {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
}

.icon-act {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 2.25rem;
  height: 2.25rem;
  padding: 0;
  border: none;
  border-radius: 10px;
  background: rgba(148, 163, 184, 0.12);
  color: #e2e8f0;
  cursor: pointer;
  transition:
    background 0.15s ease,
    color 0.15s ease;
}

.icon-act:hover:not(:disabled) {
  background: rgba(56, 189, 248, 0.22);
  color: #7dd3fc;
}

.icon-act--danger:hover:not(:disabled) {
  background: rgba(248, 113, 113, 0.18);
  color: #fecaca;
}

.icon-act:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.icon-act svg {
  width: 1.15rem;
  height: 1.15rem;
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

.panel-backdrop {
  z-index: 85;
  padding: 0.75rem;
}

.kpi-card-outer {
  margin-bottom: 1.1rem;
  padding: 1rem 1.1rem;
}

.kpi-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 0.85rem;
  margin-bottom: 0.5rem;
}

.kpi-block-title {
  margin: 0;
}

.kpi-month-picks {
  display: flex;
  flex-wrap: wrap;
  gap: 0.65rem;
  align-items: flex-end;
}

.kpi-pick .lbl {
  display: block;
  font-size: 0.72rem;
  color: #94a3b8;
  margin-bottom: 0.25rem;
}

.kpi-period-label {
  margin: 0 0 0.85rem;
  font-size: 0.8rem;
  line-height: 1.45;
}

.kpi-banner {
  margin-bottom: 0.65rem;
}

.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 0.75rem;
}

.kpi-tile {
  padding: 0.75rem 0.85rem;
  border-radius: 12px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(2, 6, 23, 0.45);
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  min-height: 5.5rem;
}

.kpi-tile--accent {
  border-color: rgba(56, 189, 248, 0.35);
  background: rgba(56, 189, 248, 0.08);
}

.kpi-tile--warn {
  border-color: rgba(251, 191, 36, 0.35);
  background: rgba(251, 191, 36, 0.06);
}

.kpi-label {
  font-size: 0.72rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #94a3b8;
  line-height: 1.3;
}

.kpi-value {
  font-size: 1.2rem;
  font-weight: 700;
  color: #f8fafc;
  line-height: 1.2;
  word-break: break-word;
}

.kpi-hint {
  font-size: 0.72rem;
  color: #64748b;
  line-height: 1.35;
  margin-top: auto;
}

.input--sm {
  padding: 0.4rem 0.55rem;
  font-size: 0.85rem;
  min-width: 7.5rem;
}

.panel-modal {
  max-width: min(1320px, 98vw);
  width: 100%;
  max-height: 92vh;
  overflow-y: auto;
  margin: 0;
  padding: 1.25rem 1.35rem;
}

.panel-head {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 1rem;
  margin-bottom: 1rem;
}

.panel-title {
  margin: 0;
  font-size: 1.2rem;
  color: #f8fafc;
}

.panel-sub {
  margin: 0.25rem 0 0;
  font-size: 0.9rem;
}

.panel-close {
  flex-shrink: 0;
  padding: 0.35rem 0.65rem;
  min-width: auto;
  line-height: 1;
}

.panel-filter {
  display: block;
  margin-bottom: 1rem;
}

.panel-filter .lbl {
  display: block;
  margin-bottom: 0.35rem;
}

.panel-banner {
  margin-bottom: 0.75rem;
}

.panel-section {
  margin-bottom: 1.25rem;
}

.panel-section:last-child {
  margin-bottom: 0;
}

.panel-h3 {
  margin: 0 0 0.65rem;
  font-size: 1rem;
  font-weight: 600;
  color: #e2e8f0;
}

.panel-table-wrap {
  overflow-x: auto;
  border: 1px solid rgba(148, 163, 184, 0.15);
  border-radius: 10px;
}

.panel-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}

.panel-table th,
.panel-table td {
  padding: 0.45rem 0.5rem;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
}

.panel-table th {
  color: #94a3b8;
  font-weight: 600;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.panel-table .num {
  text-align: right;
  white-space: nowrap;
}

.panel-link {
  color: #7dd3fc;
  font-weight: 600;
  text-decoration: none;
}

.panel-link:hover {
  text-decoration: underline;
  color: #bae6fd;
}

.pad-sm {
  padding: 0.5rem 0;
}

.panel-pager {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

.pager-meta {
  font-size: 0.78rem;
  text-align: center;
  flex: 1 1 12rem;
}

.btn-sm {
  padding: 0.35rem 0.75rem;
  font-size: 0.82rem;
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

.name-link:hover {
  color: #7dd3fc;
  text-decoration-color: rgba(125, 211, 252, 0.85);
}

.name-link:focus-visible {
  outline: 2px solid rgba(56, 189, 248, 0.55);
  outline-offset: 2px;
  border-radius: 4px;
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

.del-co-backdrop {
  z-index: 90;
}

.del-co-modal {
  max-width: 460px;
}

.del-co-lede {
  margin: 0 0 1rem;
  font-size: 0.88rem;
  line-height: 1.45;
}

.del-co-dl {
  margin: 0 0 1rem;
  padding: 0.75rem 0.85rem;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(2, 6, 23, 0.35);
  font-size: 0.88rem;
}

.del-co-dl > div {
  display: grid;
  grid-template-columns: 8rem 1fr;
  gap: 0.35rem 0.75rem;
  padding: 0.35rem 0;
  border-bottom: 1px solid rgba(148, 163, 184, 0.1);
}

.del-co-dl > div:last-child {
  border-bottom: none;
}

.del-co-dl dt {
  margin: 0;
  color: #94a3b8;
  font-weight: 600;
}

.del-co-dl dd {
  margin: 0;
  color: #e2e8f0;
}

.del-co-field {
  margin-bottom: 0.5rem;
}

.del-co-err {
  margin-bottom: 0.65rem;
}

.btn.danger {
  background: linear-gradient(90deg, #b91c1c, #dc2626);
  color: #fff;
  border: none;
}

.btn.danger:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.recurring-section-title {
  margin-bottom: 0;
}

.recurring-panel-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.65rem;
  margin-bottom: 0.35rem;
}

.recurring-modal-backdrop {
  z-index: 95;
}

.recurring-modal-company {
  margin: -0.35rem 0 0.65rem;
  font-size: 0.88rem;
  font-weight: 600;
  color: #cbd5e1;
}

.icon-act--recurring:hover:not(:disabled) {
  background: rgba(56, 189, 248, 0.22);
  color: #7dd3fc;
}

a.icon-act--inventory {
  text-decoration: none;
}

.icon-act--inventory:hover {
  background: rgba(52, 211, 153, 0.2);
  color: #6ee7b7;
}

.recurring-hint {
  margin-top: 0;
  margin-bottom: 0.65rem;
  font-size: 0.8rem;
  line-height: 1.45;
}

.recurring-type {
  display: block;
  font-weight: 600;
  color: #e2e8f0;
  margin-bottom: 0.15rem;
}

.recurring-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.danger-text {
  color: #fecaca;
  border-color: rgba(248, 113, 113, 0.35);
}

.danger-text:hover:not(:disabled) {
  background: rgba(248, 113, 113, 0.12);
}

.tag {
  display: inline-block;
  font-size: 0.72rem;
  font-weight: 600;
  padding: 0.2rem 0.45rem;
  border-radius: 6px;
}

.tag--ok {
  background: rgba(34, 197, 94, 0.15);
  color: #86efac;
}

.tag--off {
  background: rgba(148, 163, 184, 0.12);
  color: #94a3b8;
}

.recurring-modal {
  width: 100%;
  max-width: min(760px, 98vw);
  max-height: 92vh;
  overflow-y: auto;
  margin: 0;
}

.recurring-modal-header {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.65rem;
  margin-bottom: 0.25rem;
}

.recurring-modal-title-main {
  margin: 0;
  flex: 1 1 12rem;
  font-size: 1.12rem;
}

.recurring-modal-close-top {
  flex-shrink: 0;
}

.recurring-modal-lede {
  margin: 0 0 0.85rem;
  font-size: 0.8rem;
  line-height: 1.45;
}

.recurring-modal-empty {
  margin-bottom: 0.65rem;
}

.recurring-modal-table-wrap {
  overflow-x: auto;
  border: 1px solid rgba(148, 163, 184, 0.18);
  border-radius: 10px;
  margin-bottom: 0.85rem;
}

.recurring-modal-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.82rem;
}

.recurring-modal-table th,
.recurring-modal-table td {
  padding: 0.5rem 0.55rem;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid rgba(148, 163, 184, 0.12);
}

.recurring-modal-table th {
  color: #94a3b8;
  font-weight: 600;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.recurring-modal-table .num {
  text-align: right;
  white-space: nowrap;
}

.recurring-modal-detail {
  max-width: 14rem;
}

.recurring-modal-row-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
}

.recurring-modal-toolbar {
  margin-bottom: 0.75rem;
}

.recurring-modal-form-block {
  margin-top: 0.25rem;
  padding-top: 0.85rem;
  border-top: 1px solid rgba(148, 163, 184, 0.2);
}

.recurring-form-block-title {
  margin: 0 0 0.65rem;
  font-size: 0.95rem;
  font-weight: 600;
  color: #e2e8f0;
}

.recurring-modal-footer {
  margin-top: 1rem;
  padding-top: 0.85rem;
  border-top: 1px solid rgba(148, 163, 184, 0.15);
}

textarea.input {
  resize: vertical;
  min-height: 3.5rem;
}
</style>
