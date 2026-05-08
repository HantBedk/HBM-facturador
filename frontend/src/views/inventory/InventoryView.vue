<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'

const props = defineProps({
  /** Desde la ruta `/admin/empresas/:companyId/inventario`: inventario solo de esa empresa. */
  companyId: {
    type: [String, Number],
    default: null,
  },
})
import {
  approveInventoryLifecycleRequest,
  createInventoryLot,
  deleteInventoryLot,
  fetchInventoryAuditEvents,
  fetchInventoryLifecycleRequests,
  fetchInventoryLots,
  fetchInventoryFormFieldOptions,
  fetchInventoryRentals,
  fetchInventorySales,
  reportInventoryLifecycle,
  updateInventoryLot,
} from '@/services/inventoryApi.js'
const auth = useAuthStore()
const route = useRoute()

const isAdmin = computed(() => isAdminPanelRole(auth.user?.rol))
const isInternalAdminInventory = computed(() => route.name === 'admin-inventario')
const isCompanyInventoryRoute = computed(() => route.name === 'admin-empresa-inventario')
const lockedTenantCompanyId = computed(() => {
  if (props.companyId == null || props.companyId === '') return null
  return String(props.companyId)
})
/** Admin: inventario interno HBM o inventario de una empresa cliente. */
const isAdminInventoryRoute = computed(
  () => isInternalAdminInventory.value || isCompanyInventoryRoute.value
)
const companyDisplayName = ref('')
const companyDisplayLabel = computed(() => {
  if (!isCompanyInventoryRoute.value) return ''
  const n = companyDisplayName.value.trim()
  return n || `Empresa #${lockedTenantCompanyId.value}`
})

const lotsLoading = ref(false)
const lotsError = ref('')
const lots = ref([])
const lotsMeta = ref(null)
const lotsPage = ref(1)
const lotsQ = ref('')
const activeTab = ref('activos')

watch(isCompanyInventoryRoute, (custodia) => {
  if (custodia && ['alquiler', 'ventas', 'en_alquiler_lotes'].includes(activeTab.value)) {
    activeTab.value = 'activos'
  }
})

const rentalsLoading = ref(false)
const rentalsError = ref('')
const rentals = ref([])
const rentalsMeta = ref(null)
const rentalsPage = ref(1)
const rentalsQ = ref('')

const salesLoading = ref(false)
const salesError = ref('')
const sales = ref([])
const salesMeta = ref(null)
const salesPage = ref(1)
const salesQ = ref('')

const bajasQ = ref('')
const vendidosQ = ref('')
const rentLotsQ = ref('')
const repairQ = ref('')

const sortState = ref({
  lots: { key: 'code', dir: 'asc' },
  rentals: { key: 'started_at', dir: 'desc' },
  sales: { key: 'created_at', dir: 'desc' },
  bajas: { key: 'name', dir: 'asc' },
  vendidos: { key: 'name', dir: 'asc' },
  rentLots: { key: 'name', dir: 'asc' },
  repair: { key: 'name', dir: 'asc' },
})

function readDescriptionField(description, key) {
  const text = String(description || '')
  const line = text
    .split('\n')
    .map((item) => item.trim())
    .find((item) => item.toLowerCase().startsWith(`${key.toLowerCase()}:`))
  if (!line) return ''
  return line.slice(line.indexOf(':') + 1).trim()
}

function parseDescriptionYesNoLine(description, keys) {
  const lines = String(description || '')
    .split('\n')
    .map((x) => x.trim())
    .filter(Boolean)
  for (const raw of lines) {
    const line = raw.toLowerCase()
    const hit = keys.some((k) => line.includes(String(k).toLowerCase()))
    if (!hit) continue
    const idx = raw.indexOf(':')
    const value = (idx >= 0 ? raw.slice(idx + 1) : raw).trim().toLowerCase()
    if (value.includes('no') || value === 'false' || value === '0') return false
    if (value.includes('si') || value.includes('sí') || value.includes('true') || value === '1') return true
    return true
  }
  return null
}

function readAllowSaleFromRow(row) {
  if (typeof row.allow_sale === 'boolean') return row.allow_sale
  const desc = String(row.description || '')
  if (!/(disponible|etiqueta).*venta/i.test(desc)) return true
  const parsed = parseDescriptionYesNoLine(desc, ['Disponible para venta', 'Etiqueta para venta'])
  return parsed !== null ? parsed : true
}

function readAllowRentalFromRow(row) {
  if (typeof row.allow_rental === 'boolean') return row.allow_rental
  const desc = String(row.description || '')
  if (!/(disponible|etiqueta).*alquiler/i.test(desc)) return false
  const parsed = parseDescriptionYesNoLine(desc, ['Disponible para alquiler', 'Etiqueta para alquiler'])
  return parsed === true
}

const activeLots = computed(() =>
  (lots.value || []).filter((row) => String(row.lifecycle_status || 'activo') === 'activo')
)
/** Equipos vendidos por completo (lifecycle «vendido», distinto de baja por inservible). */
const soldLots = computed(() => (lots.value || []).filter((row) => String(row.lifecycle_status || '') === 'vendido'))
/** Lotes con unidades físicamente en alquiler activo. */
const lotsWithActiveRentUnits = computed(() =>
  (lots.value || []).filter((row) => Number(row.units_on_rent || 0) > 0)
)
const decommissionedLots = computed(() => (lots.value || []).filter((row) => String(row.lifecycle_status || '') === 'baja'))
const repairLots = computed(() => (lots.value || []).filter((row) => String(row.lifecycle_status || '') === 'reparacion'))

function sortableValue(row, key) {
  if (key === 'code') return readDescriptionField(row.description, 'Codigo interno') || row.sku || ''
  if (key === 'brand') return readDescriptionField(row.description, 'Marca') || ''
  if (key === 'owner') return row.owner?.nombre || ''
  if (key === 'name') return row.name || ''
  if (key === 'entry_date') return row.created_at || ''
  if (key === 'location') return readDescriptionField(row.description, 'Ubicacion') || ''
  if (key === 'detail') return row.description || ''
  if (key === 'stock') return Number(row.quantity_available || 0)
  if (key === 'price') return Number(row.unit_price || 0)
  if (key === 'status') return row.is_active ? 1 : 0
  return ''
}

function sortRows(rows, table, getValue) {
  const { key, dir } = sortState.value[table]
  const d = dir === 'asc' ? 1 : -1
  const copy = [...(rows || [])]
  copy.sort((a, b) => {
    const va = getValue(a, key)
    const vb = getValue(b, key)
    if (typeof va === 'number' && typeof vb === 'number') return (va - vb) * d
    return String(va).localeCompare(String(vb), 'es', { sensitivity: 'base', numeric: true }) * d
  })
  return copy
}

function rentalSortValue(r, key) {
  if (key === 'id') return Number(r.id) || 0
  if (key === 'started_at') return r.started_at || ''
  if (key === 'closed_at') return r.closed_at || ''
  if (key === 'status') return r.status || ''
  if (key === 'customer_name') return r.customer_name || ''
  if (key === 'customer_phone') return r.customer_phone || ''
  if (key === 'equipos') return formatRentalLinesSummary(r)
  if (key === 'total') return sumInventoryLineTotals(r.lines)
  return ''
}

function saleSortValue(s, key) {
  if (key === 'id') return Number(s.id) || 0
  if (key === 'created_at') return s.created_at || ''
  if (key === 'total') return Number(s.total_amount) || 0
  if (key === 'vendedor') return s.sold_by?.nombre || ''
  if (key === 'detalle') return formatSaleLinesSummary(s)
  return ''
}

function repairSortValue(row, key) {
  if (key === 'name') return row.name || ''
  if (key === 'code') return readDescriptionField(row.description, 'Codigo interno') || row.sku || ''
  if (key === 'location') return readDescriptionField(row.description, 'Ubicacion') || ''
  if (key === 'condition') return readDescriptionField(row.description, 'Estado fisico') || ''
  if (key === 'detail') return row.description || ''
  if (key === 'status') return row.is_active ? 1 : 0
  return ''
}

const filteredRentals = computed(() => {
  const q = String(rentalsQ.value || '').trim().toLowerCase()
  if (!q) return rentals.value
  return (rentals.value || []).filter((r) => {
    const haystack = [
      r.id,
      r.customer_name,
      r.customer_phone,
      r.status,
      r.started_at,
      r.closed_at,
      formatRentalLinesSummary(r),
    ]
      .join(' ')
      .toLowerCase()
    return haystack.includes(q)
  })
})

const filteredSales = computed(() => {
  const q = String(salesQ.value || '').trim().toLowerCase()
  if (!q) return sales.value
  return (sales.value || []).filter((s) => {
    const haystack = [
      s.id,
      s.created_at,
      s.total_amount,
      s.sold_by?.nombre,
      formatSaleLinesSummary(s),
    ]
      .join(' ')
      .toLowerCase()
    return haystack.includes(q)
  })
})

const filteredBajas = computed(() => {
  const q = String(bajasQ.value || '').trim().toLowerCase()
  if (!q) return decommissionedLots.value
  return decommissionedLots.value.filter((row) => {
    const haystack = [
      row.name,
      row.sku,
      row.created_at,
      row.description,
      readDescriptionField(row.description, 'Codigo interno'),
    ]
      .join(' ')
      .toLowerCase()
    return haystack.includes(q)
  })
})

const filteredVendidos = computed(() => {
  const q = String(vendidosQ.value || '').trim().toLowerCase()
  if (!q) return soldLots.value
  return soldLots.value.filter((row) => {
    const haystack = [row.name, row.sku, row.created_at, row.description, readDescriptionField(row.description, 'Codigo interno')]
      .join(' ')
      .toLowerCase()
    return haystack.includes(q)
  })
})

const filteredRentLots = computed(() => {
  const q = String(rentLotsQ.value || '').trim().toLowerCase()
  if (!q) return lotsWithActiveRentUnits.value
  return lotsWithActiveRentUnits.value.filter((row) => {
    const haystack = [
      row.name,
      row.sku,
      row.description,
      readDescriptionField(row.description, 'Codigo interno'),
      String(row.units_on_rent || ''),
      String(row.quantity_available || ''),
    ]
      .join(' ')
      .toLowerCase()
    return haystack.includes(q)
  })
})

const filteredRepair = computed(() => {
  const q = String(repairQ.value || '').trim().toLowerCase()
  if (!q) return repairLots.value
  return repairLots.value.filter((row) => {
    const haystack = [
      row.name,
      row.sku,
      row.description,
      readDescriptionField(row.description, 'Codigo interno'),
      readDescriptionField(row.description, 'Ubicacion'),
      readDescriptionField(row.description, 'Estado fisico'),
    ]
      .join(' ')
      .toLowerCase()
    return haystack.includes(q)
  })
})

const activeTabSearch = computed({
  get() {
    if (activeTab.value === 'activos') return lotsQ.value
    if (activeTab.value === 'alquiler') return rentalsQ.value
    if (activeTab.value === 'ventas') return salesQ.value
    if (activeTab.value === 'bajas') return bajasQ.value
    if (activeTab.value === 'vendidos') return vendidosQ.value
    if (activeTab.value === 'en_alquiler_lotes') return rentLotsQ.value
    if (activeTab.value === 'reparacion') return repairQ.value
    return ''
  },
  set(v) {
    const value = String(v || '')
    if (activeTab.value === 'activos') lotsQ.value = value
    else if (activeTab.value === 'alquiler') rentalsQ.value = value
    else if (activeTab.value === 'ventas') salesQ.value = value
    else if (activeTab.value === 'bajas') bajasQ.value = value
    else if (activeTab.value === 'vendidos') vendidosQ.value = value
    else if (activeTab.value === 'en_alquiler_lotes') rentLotsQ.value = value
    else if (activeTab.value === 'reparacion') repairQ.value = value
  },
})

const activeTabSearchPlaceholder = computed(() => {
  if (activeTab.value === 'activos') return 'Buscar por nombre del dispositivo…'
  if (activeTab.value === 'alquiler') return 'Buscar alquiler por cliente, teléfono, equipo, estado o fecha…'
  if (activeTab.value === 'ventas') return 'Buscar venta por ID, fecha, vendedor, total o detalle…'
  if (activeTab.value === 'bajas') return 'Buscar baja por nombre, código, fecha o detalle…'
  if (activeTab.value === 'vendidos') return 'Buscar equipo vendido por nombre, código o detalle…'
  if (activeTab.value === 'en_alquiler_lotes')
    return 'Buscar lote con unidades en alquiler por nombre, código o stock…'
  if (activeTab.value === 'reparacion') return 'Buscar equipo en reparación por nombre, código, ubicación o detalle…'
  return 'Buscar…'
})

const sortedLots = computed(() => sortRows(activeLots.value, 'lots', sortableValue))
const sortedRentals = computed(() => sortRows(filteredRentals.value, 'rentals', rentalSortValue))
const sortedSales = computed(() => sortRows(filteredSales.value, 'sales', saleSortValue))
const sortedBajas = computed(() => sortRows(filteredBajas.value, 'bajas', sortableValue))
const sortedVendidos = computed(() => sortRows(filteredVendidos.value, 'vendidos', sortableValue))
const sortedRentLots = computed(() => sortRows(filteredRentLots.value, 'rentLots', sortableValue))
const sortedRepair = computed(() => sortRows(filteredRepair.value, 'repair', repairSortValue))

function toggleTableSort(table, key) {
  const cur = sortState.value[table]
  if (cur.key === key) {
    sortState.value = {
      ...sortState.value,
      [table]: { key, dir: cur.dir === 'asc' ? 'desc' : 'asc' },
    }
  } else {
    sortState.value = { ...sortState.value, [table]: { key, dir: 'asc' } }
  }
}

function tableSortIndicator(table, key) {
  const s = sortState.value[table]
  if (s.key !== key) return ''
  return s.dir === 'asc' ? ' ↑' : ' ↓'
}

const lotModalOpen = ref(false)
const lotModalMode = ref('create')
const lotSaving = ref(false)
const lotFormError = ref('')
const editingLotId = ref(null)
const lotForm = ref({
  name: '',
  sku: '',
  description: '',
  asset_type: '',
  asset_subcategory: '',
  serial_number: '',
  brand: '',
  model: '',
  location: '',
  condition: '',
  warranty_until: '',
  is_new_asset: true,
  allow_sale: true,
  allow_rental: false,
  quantity_available: 1,
  unit_price: '',
  is_active: true,
  adjustment_note: '',
  owner_user_id: '',
})

const locationOptions = ref(['Bodega principal', 'Taller', 'Oficina administrativa'])
const assetTypeList = ref(['Equipo', 'Herramienta', 'Accesorio', 'Consumible', 'Mobiliario', 'Otro'])

/** Estado físico solo para activos usados (condición de compra «Usado»). */
const USED_PHYSICAL_CONDITIONS = ['Excelente', 'Muy bueno', 'Bueno', 'Regular', 'Requiere mantenimiento']

/** Subcategorías por tipo (mismas etiquetas que `assetTypeList` por defecto). */
const assetSubcategoryByType = {
  Equipo: ['Portátil', 'Desktop', 'Impresora', 'Router', 'Switch', 'Servidor', 'Otro'],
  Herramienta: ['Manual', 'Eléctrica', 'Medición', 'Seguridad', 'Otro'],
  Accesorio: ['Cableado', 'Adaptador', 'Periférico', 'Montaje', 'Otro'],
  Consumible: ['Tinta', 'Papel', 'Limpieza', 'Batería', 'Otro'],
  Mobiliario: ['Escritorio', 'Silla', 'Estantería', 'Archivador', 'Otro'],
  Otro: ['General'],
}

function textMatchesQuery(text, q) {
  const needle = String(q || '').trim().toLowerCase()
  if (!needle) return true
  return String(text || '').toLowerCase().includes(needle)
}

const assetTypeSearchQ = ref('')
const subcategorySearchQ = ref('')
const locationSearchQ = ref('')
const conditionSearchQ = ref('')
const assetTypeDropdownOpen = ref(false)
const subcategoryDropdownOpen = ref(false)
const locationDropdownOpen = ref(false)
const conditionDropdownOpen = ref(false)
const lotSuccessModalOpen = ref(false)
const lotSuccessLines = ref([])

const filteredAssetTypeOptions = computed(() =>
  assetTypeList.value.filter((t) => textMatchesQuery(t, assetTypeSearchQ.value))
)
const subcategoriesForType = computed(() => {
  const t = String(lotForm.value.asset_type || '').trim()
  const list = assetSubcategoryByType[t]
  if (list?.length) return list
  return t ? ['General'] : []
})
const filteredSubcategoryOptions = computed(() =>
  subcategoriesForType.value.filter((s) => textMatchesQuery(s, subcategorySearchQ.value))
)
const filteredLocationOptions = computed(() =>
  locationOptions.value.filter((loc) => textMatchesQuery(loc, locationSearchQ.value))
)
const filteredConditionOptions = computed(() => {
  if (lotForm.value.is_new_asset) return []
  return USED_PHYSICAL_CONDITIONS.filter((c) => textMatchesQuery(c, conditionSearchQ.value))
})

function resetLotPickerSearches() {
  assetTypeSearchQ.value = ''
  subcategorySearchQ.value = ''
  locationSearchQ.value = ''
  conditionSearchQ.value = ''
  assetTypeDropdownOpen.value = false
  subcategoryDropdownOpen.value = false
  locationDropdownOpen.value = false
  conditionDropdownOpen.value = false
}

function closeLotComboboxes() {
  assetTypeDropdownOpen.value = false
  subcategoryDropdownOpen.value = false
  locationDropdownOpen.value = false
  conditionDropdownOpen.value = false
}

function onInventoryFormMousedown(ev) {
  if (!lotModalOpen.value) return
  const el = ev.target
  if (typeof el?.closest === 'function' && el.closest('[data-inventory-combobox]')) return
  if (typeof el?.closest === 'function' && el.closest('[data-inventory-subcategory-combobox]')) return
  closeLotComboboxes()
}

function pickAssetType(label) {
  lotForm.value.asset_type = String(label || '').trim()
  lotForm.value.asset_subcategory = ''
  assetTypeDropdownOpen.value = false
  assetTypeSearchQ.value = ''
  subcategoryDropdownOpen.value = false
  subcategorySearchQ.value = ''
}
function pickSubcategory(label) {
  lotForm.value.asset_subcategory = String(label || '').trim()
  subcategoryDropdownOpen.value = false
  subcategorySearchQ.value = ''
}
function toggleSubcategoryDropdown() {
  if (!lotForm.value.asset_type || !subcategoriesForType.value.length) return
  subcategoryDropdownOpen.value = !subcategoryDropdownOpen.value
  if (subcategoryDropdownOpen.value) {
    assetTypeDropdownOpen.value = false
    conditionDropdownOpen.value = false
  }
}
function pickLocation(loc) {
  lotForm.value.location = String(loc || '').trim()
  locationDropdownOpen.value = false
  locationSearchQ.value = ''
}
function toggleLocationDropdown() {
  locationDropdownOpen.value = !locationDropdownOpen.value
  if (locationDropdownOpen.value) {
    assetTypeDropdownOpen.value = false
    subcategoryDropdownOpen.value = false
    conditionDropdownOpen.value = false
  }
}
function pickCondition(c) {
  lotForm.value.condition = String(c || '').trim()
  conditionDropdownOpen.value = false
  conditionSearchQ.value = ''
}
function toggleAssetTypeDropdown() {
  assetTypeDropdownOpen.value = !assetTypeDropdownOpen.value
  if (assetTypeDropdownOpen.value) {
    conditionDropdownOpen.value = false
    subcategoryDropdownOpen.value = false
  }
}
function toggleConditionDropdown() {
  if (lotForm.value.is_new_asset) return
  conditionDropdownOpen.value = !conditionDropdownOpen.value
  if (conditionDropdownOpen.value) {
    assetTypeDropdownOpen.value = false
    subcategoryDropdownOpen.value = false
  }
}

watch(
  () => lotForm.value.is_new_asset,
  (isNew) => {
    if (isNew) {
      lotForm.value.condition = ''
      conditionSearchQ.value = ''
      conditionDropdownOpen.value = false
    }
  }
)

const generatedAssetCode = ref('')
const generatedAssetTag = computed(() => (generatedAssetCode.value ? `HBM-${generatedAssetCode.value}` : ''))

function moneyCo(value) {
  const n = Number(value)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

function formatDateTime(value) {
  if (!value) return '—'
  const dt = new Date(value)
  if (Number.isNaN(dt.getTime())) return String(value)
  return dt.toLocaleString('es-CO', { dateStyle: 'medium', timeStyle: 'short' })
}

function lotCode(row) {
  return readDescriptionField(row?.description, 'Codigo interno') || row?.sku || '—'
}

function humanizeAuditAction(action) {
  const key = String(action || '').toLowerCase()
  const map = {
    create: 'Registro del activo',
    update: 'Actualización de datos',
    delete: 'Eliminación del activo',
    lifecycle_to_reparacion: 'Cambio a reparación',
    lifecycle_to_activo: 'Reactivación',
    lifecycle_baja_requested: 'Solicitud de baja',
    lifecycle_baja_approval_registered: 'Aprobación parcial de baja',
    lifecycle_baja_approved: 'Baja aprobada',
    lifecycle_baja_rejected: 'Baja rechazada',
  }
  return map[key] || key.replaceAll('_', ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function summarizeAuditEvent(ev) {
  const out = []
  const before = ev?.before || {}
  const after = ev?.after || {}
  if (before?.lifecycle_status && after?.lifecycle_status && before.lifecycle_status !== after.lifecycle_status) {
    out.push(`Estado: ${before.lifecycle_status} -> ${after.lifecycle_status}`)
  }
  if (after?.reason) out.push(`Motivo: ${after.reason}`)
  if (after?.resolution_note) out.push(`Resolución: ${after.resolution_note}`)
  if (after?.repair_reason) out.push(`Detalle reparación: ${after.repair_reason}`)
  if (after?.repair_resolution) out.push(`Cierre reparación: ${after.repair_resolution}`)
  if (after?.decommission_reason) out.push(`Razón de baja: ${after.decommission_reason}`)
  return out.join(' | ')
}

function escapeHtml(raw) {
  return String(raw ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;')
}

function sumInventoryLineTotals(lines) {
  if (!Array.isArray(lines)) return 0
  return lines.reduce((acc, ln) => acc + Number(ln.line_total || 0), 0)
}

function formatRentalLinesSummary(r) {
  const lines = r.lines || []
  if (!lines.length) return '—'
  return lines.map((ln) => `${ln.lot?.name || 'Equipo'} × ${ln.quantity}`).join('; ')
}

function formatSaleLinesSummary(s) {
  const lines = s.lines || []
  if (!lines.length) return '—'
  return lines
    .map((ln) => `${ln.lot?.name || 'Equipo'} × ${ln.quantity} — ${moneyCo(ln.line_total)}`)
    .join('; ')
}

function generateAssetCode() {
  const now = new Date()
  const y = String(now.getUTCFullYear()).slice(-2)
  const m = String(now.getUTCMonth() + 1).padStart(2, '0')
  const d = String(now.getUTCDate()).padStart(2, '0')
  const rand = Math.random().toString(36).slice(2, 6).toUpperCase()
  return `${y}${m}${d}-${rand}`
}

const contextQueryParams = computed(() => {
  if (lockedTenantCompanyId.value) {
    return { tenant_company_id: lockedTenantCompanyId.value }
  }
  return { tenant_scope: 'internal' }
})

function inventoryMutationQuery() {
  const id = lockedTenantCompanyId.value
  if (!id) return {}
  return { tenant_company_id: id }
}

async function loadLots() {
  lotsLoading.value = true
  lotsError.value = ''
  try {
    const params = {
      page: lotsPage.value,
      q: lotsQ.value.trim() || undefined,
      active_only: isAdmin.value ? undefined : '1',
      ...contextQueryParams.value,
    }
    const res = await fetchInventoryLots(params)
    lots.value = res.data || []
    lotsMeta.value = res.meta || null
  } catch (e) {
    lotsError.value = e.message || 'No se pudo cargar el inventario.'
  } finally {
    lotsLoading.value = false
  }
}

async function loadRentals() {
  rentalsLoading.value = true
  rentalsError.value = ''
  try {
    const res = await fetchInventoryRentals({
      page: rentalsPage.value,
      per_page: 15,
      ...contextQueryParams.value,
    })
    rentals.value = res.data || []
    rentalsMeta.value = res.meta || null
  } catch (e) {
    rentalsError.value = e.message || 'No se pudieron cargar los alquileres.'
  } finally {
    rentalsLoading.value = false
  }
}

async function loadSales() {
  salesLoading.value = true
  salesError.value = ''
  try {
    const res = await fetchInventorySales({
      page: salesPage.value,
      per_page: 15,
      ...contextQueryParams.value,
    })
    sales.value = res.data || []
    salesMeta.value = res.meta || null
  } catch (e) {
    salesError.value = e.message || 'No se pudo cargar el historial de ventas.'
  } finally {
    salesLoading.value = false
  }
}

async function openCreateLot() {
  lotModalMode.value = 'create'
  editingLotId.value = null
  resetLotPickerSearches()
  const custodia = isCompanyInventoryRoute.value
  lotForm.value = {
    name: '',
    sku: '',
    description: '',
    asset_type: '',
    asset_subcategory: '',
    serial_number: '',
    mac_address: '',
    brand: '',
    model: '',
    location: '',
    condition: '',
    warranty_until: '',
    is_new_asset: true,
    allow_sale: custodia ? false : true,
    allow_rental: false,
    quantity_available: 1,
    unit_price: custodia ? '0' : '',
    is_active: true,
    lifecycle_status: 'activo',
    adjustment_note: '',
    owner_user_id: '',
  }
  generatedAssetCode.value = generateAssetCode()
  lotFormError.value = ''
  lotModalOpen.value = true
}

async function openEditLot(row) {
  lotModalMode.value = 'edit'
  editingLotId.value = row.id
  resetLotPickerSearches()
  const desc = row.description || ''
  lotForm.value = {
    name: row.name || '',
    sku: row.sku || '',
    description: row.description || '',
    asset_type: readDescriptionField(desc, 'Tipo') || '',
    asset_subcategory: readDescriptionField(desc, 'Subcategoria') || '',
    serial_number: readDescriptionField(desc, 'Serial') || row.sku || '',
    mac_address: row.mac_address || readDescriptionField(desc, 'MAC') || '',
    brand: readDescriptionField(desc, 'Marca') || '',
    model: readDescriptionField(desc, 'Modelo') || '',
    location: readDescriptionField(desc, 'Ubicacion') || '',
    condition: readDescriptionField(desc, 'Estado fisico') || '',
    warranty_until: readDescriptionField(desc, 'Garantia hasta') || '',
    is_new_asset: !String(readDescriptionField(desc, 'Condicion de compra') || '')
      .toLowerCase()
      .includes('usado'),
    allow_sale: readAllowSaleFromRow(row),
    allow_rental: readAllowRentalFromRow(row),
    quantity_available: row.quantity_available,
    unit_price: String(row.unit_price ?? ''),
    is_active: !!row.is_active,
    lifecycle_status: row.lifecycle_status || (row.is_active ? 'activo' : 'baja'),
    adjustment_note: '',
    owner_user_id: String(row.owner_user_id ?? ''),
  }
  if (lotForm.value.is_new_asset) {
    lotForm.value.condition = ''
  }
  generatedAssetCode.value = readDescriptionField(desc, 'Codigo interno') || ''
  lotFormError.value = ''
  lotModalOpen.value = true
}

async function submitLot() {
  lotSaving.value = true
  lotFormError.value = ''
  const savingAsCreate = lotModalMode.value === 'create'
  if (!String(lotForm.value.asset_type || '').trim()) {
    lotFormError.value = 'Seleccione un tipo de activo.'
    lotSaving.value = false
    return
  }
  if (!String(lotForm.value.asset_subcategory || '').trim()) {
    lotFormError.value = 'Seleccione una subcategoría del listado (según el tipo de activo).'
    lotSaving.value = false
    return
  }
  if (!lotForm.value.is_new_asset) {
    if (!String(lotForm.value.condition || '').trim()) {
      lotFormError.value = 'Seleccione el estado físico del activo usado.'
      lotSaving.value = false
      return
    }
    if (!USED_PHYSICAL_CONDITIONS.includes(lotForm.value.condition)) {
      lotFormError.value = 'El estado físico debe ser una de las opciones del listado para equipos usados.'
      lotSaving.value = false
      return
    }
  }
  if (savingAsCreate && isAdmin.value && isAdminInventoryRoute.value && !auth.user?.id) {
    lotFormError.value = 'No se pudo determinar el usuario que registra el activo.'
    lotSaving.value = false
    return
  }
  try {
    const effectiveCode = generatedAssetCode.value || generateAssetCode()
    generatedAssetCode.value = effectiveCode
    const allowSaleFinal = isCompanyInventoryRoute.value ? false : !!lotForm.value.allow_sale
    const allowRentalFinal = isCompanyInventoryRoute.value ? false : !!lotForm.value.allow_rental
    const unitPriceFinal = isCompanyInventoryRoute.value ? 0 : Number(String(lotForm.value.unit_price).replace(',', '.'))

    const detailLines = [
      ...(isCompanyInventoryRoute.value ? [] : [`Codigo interno: ${effectiveCode}`]),
      lotForm.value.asset_type ? `Tipo: ${lotForm.value.asset_type}` : '',
      lotForm.value.asset_subcategory ? `Subcategoria: ${lotForm.value.asset_subcategory}` : '',
      lotForm.value.serial_number ? `Serial: ${lotForm.value.serial_number}` : '',
      lotForm.value.mac_address ? `MAC: ${lotForm.value.mac_address}` : '',
      lotForm.value.brand ? `Marca: ${lotForm.value.brand}` : '',
      lotForm.value.model ? `Modelo: ${lotForm.value.model}` : '',
      lotForm.value.location ? `Ubicacion: ${lotForm.value.location}` : '',
      lotForm.value.is_new_asset
        ? 'Estado fisico: Nuevo'
        : lotForm.value.condition
          ? `Estado fisico: ${lotForm.value.condition}`
          : '',
      lotForm.value.warranty_until ? `Garantia hasta: ${lotForm.value.warranty_until}` : '',
      `Condicion de compra: ${lotForm.value.is_new_asset ? 'Nuevo' : 'Usado'}`,
      `Disponible para venta: ${allowSaleFinal ? 'Si' : 'No'}`,
      `Disponible para alquiler: ${allowRentalFinal ? 'Si' : 'No'}`,
    ].filter(Boolean)
    const fullDescription = [lotForm.value.description?.trim() || '', ...detailLines].filter(Boolean).join('\n')

    const manualSku = lotForm.value.sku?.trim() || lotForm.value.serial_number?.trim() || ''
    const body = {
      name: lotForm.value.name.trim(),
      description: fullDescription || null,
      quantity_available: Number(lotForm.value.quantity_available),
      unit_price: unitPriceFinal,
      is_active: !!lotForm.value.is_active,
      serial_number: lotForm.value.serial_number?.trim() || null,
      mac_address: lotForm.value.mac_address?.trim() || null,
      allow_sale: allowSaleFinal,
      allow_rental: allowRentalFinal,
      tenant_company_id: lockedTenantCompanyId.value ? Number(lockedTenantCompanyId.value) : null,
    }
    if (isCompanyInventoryRoute.value) {
      if (manualSku) body.sku = manualSku
    } else {
      body.sku = manualSku || effectiveCode
    }
    let postCreateLines = null
    if (savingAsCreate) {
      if (isAdmin.value && isAdminInventoryRoute.value) {
        body.owner_user_id = Number(auth.user.id)
      }
      const res = await createInventoryLot(body)
      const payload = res?.data ?? res
      const assignedSku = payload?.sku
      postCreateLines =
        isCompanyInventoryRoute.value && assignedSku
          ? ['Activo registrado correctamente.', `Código de inventario asignado: ${assignedSku}`]
          : ['Activo registrado correctamente.', `Etiqueta sugerida: ${generatedAssetTag.value}`]
    } else {
      const patch = {
        name: body.name,
        sku: body.sku,
        description: body.description,
        unit_price: body.unit_price,
        is_active: body.is_active,
        serial_number: body.serial_number,
        mac_address: body.mac_address,
        allow_sale: body.allow_sale,
        allow_rental: body.allow_rental,
      }
      const origQty = lots.value.find((l) => l.id === editingLotId.value)?.quantity_available
      if (origQty !== undefined && Number(body.quantity_available) !== Number(origQty)) {
        patch.quantity_available = Number(body.quantity_available)
        patch.adjustment_note = lotForm.value.adjustment_note?.trim() || 'Ajuste manual'
      }
      await updateInventoryLot(editingLotId.value, patch, inventoryMutationQuery())
    }
    lotModalOpen.value = false
    closeLotComboboxes()
    await loadLots()
    if (postCreateLines) {
      lotSuccessLines.value = postCreateLines
      lotSuccessModalOpen.value = true
    }
  } catch (e) {
    const msg =
      e.data?.errors && Object.values(e.data.errors).flat()[0]
        ? Object.values(e.data.errors).flat()[0]
        : e.message
    lotFormError.value = msg || 'Error al guardar.'
  } finally {
    lotSaving.value = false
  }
}

const reportModalOpen = ref(false)
const reportSaving = ref(false)
const reportError = ref('')
const reportLot = ref(null)
const reportTargetStatus = ref('reparacion')
const reportReason = ref('')
const reportResolution = ref('')
const reportHistoryLoading = ref(false)
const reportHistory = ref([])
const pendingBajaRequests = ref([])
const assetSheetOpen = ref(false)
const assetSheetLoading = ref(false)
const assetSheetError = ref('')
const assetSheetLot = ref(null)
const assetSheetHistory = ref([])

function lotLifecycleStatusLabel(row) {
  const s = String(row?.lifecycle_status || 'activo')
  if (s === 'baja') return 'Dado de baja'
  if (s === 'vendido') return 'Vendido'
  if (s === 'reparacion') return 'En reparación'
  return 'Activo'
}

function availableLifecycleActions(row) {
  const s = String(row?.lifecycle_status || 'activo')
  if (s === 'vendido') return []
  if (s === 'activo') return ['reparacion', 'baja']
  if (s === 'reparacion') return ['activo', 'baja']
  return []
}

function openReportModal(row, preset = null) {
  reportLot.value = row
  const actions = availableLifecycleActions(row)
  reportTargetStatus.value = preset && actions.includes(preset) ? preset : (actions[0] || 'reparacion')
  reportReason.value = ''
  reportResolution.value = ''
  reportError.value = ''
  reportHistory.value = []
  reportModalOpen.value = true
  void loadLotHistory(row.id)
}

async function loadLotHistory(lotId) {
  reportHistoryLoading.value = true
  try {
    const res = await fetchInventoryAuditEvents({ entity_type: 'inventory_lot', entity_id: lotId, per_page: 20 })
    reportHistory.value = res.data || []
  } catch {
    reportHistory.value = []
  } finally {
    reportHistoryLoading.value = false
  }
}

async function submitLifecycleReport() {
  if (!reportLot.value) return
  if (!String(reportReason.value || '').trim()) {
    reportError.value = 'Debe indicar un motivo.'
    return
  }
  reportSaving.value = true
  reportError.value = ''
  try {
    await reportInventoryLifecycle(reportLot.value.id, {
      target_status: reportTargetStatus.value,
      reason: reportReason.value.trim(),
      resolution_note: reportResolution.value.trim() || undefined,
    })
    reportModalOpen.value = false
    await Promise.all([loadLots(), loadPendingBajaRequests()])
  } catch (e) {
    const msg = e.data?.errors ? Object.values(e.data.errors).flat()[0] : e.message
    reportError.value = msg || 'No se pudo registrar el reporte.'
  } finally {
    reportSaving.value = false
  }
}

const assetSheetDetails = computed(() => {
  const row = assetSheetLot.value
  if (!row) return []
  const desc = row.description || ''
  return [
    ['Código interno', lotCode(row)],
    ['Nombre del activo', row.name || '—'],
    ['Tipo', readDescriptionField(desc, 'Tipo') || '—'],
    ['Subcategoría', readDescriptionField(desc, 'Subcategoria') || '—'],
    ['Serial', readDescriptionField(desc, 'Serial') || row.sku || '—'],
    ['MAC', row.mac_address || readDescriptionField(desc, 'MAC') || '—'],
    ['Marca', readDescriptionField(desc, 'Marca') || '—'],
    ['Modelo', readDescriptionField(desc, 'Modelo') || '—'],
    ['Ubicación', readDescriptionField(desc, 'Ubicacion') || '—'],
    ['Estado físico', readDescriptionField(desc, 'Estado fisico') || '—'],
    ['Condición de compra', readDescriptionField(desc, 'Condicion de compra') || '—'],
    ['Estado ciclo de vida', lotLifecycleStatusLabel(row)],
    ['Stock disponible', String(row.quantity_available ?? '—')],
    ['Precio unitario', moneyCo(row.unit_price)],
    ['Fecha de alta', formatDateTime(row.created_at)],
    ['Titular', row.owner?.nombre || '—'],
  ]
})

const assetSheetTimeline = computed(() =>
  (assetSheetHistory.value || []).map((ev) => ({
    id: ev.id,
    title: humanizeAuditAction(ev.action),
    when: formatDateTime(ev.occurred_at),
    actor: ev.actor?.nombre || 'Sistema',
    note: summarizeAuditEvent(ev),
  }))
)

async function openAssetSheet(row) {
  assetSheetLot.value = row
  assetSheetError.value = ''
  assetSheetHistory.value = []
  assetSheetOpen.value = true
  await loadAssetSheetHistory(row.id)
}

async function loadAssetSheetHistory(lotId) {
  assetSheetLoading.value = true
  try {
    const res = await fetchInventoryAuditEvents({
      entity_type: 'inventory_lot',
      entity_id: lotId,
      per_page: 100,
      ...contextQueryParams.value,
    })
    assetSheetHistory.value = res.data || []
  } catch (e) {
    assetSheetHistory.value = []
    assetSheetError.value = e.message || 'No se pudo cargar la trazabilidad del activo.'
  } finally {
    assetSheetLoading.value = false
  }
}

function printAssetSheet() {
  if (!assetSheetLot.value) return
  const timelineRows = assetSheetTimeline.value
    .map(
      (ev) => `<tr>
        <td>${escapeHtml(ev.when)}</td>
        <td>${escapeHtml(ev.title)}</td>
        <td>${escapeHtml(ev.actor)}</td>
        <td>${escapeHtml(ev.note || '—')}</td>
      </tr>`
    )
    .join('')
  const detailRows = assetSheetDetails.value
    .map(([k, v]) => `<tr><th>${escapeHtml(k)}</th><td>${escapeHtml(v || '—')}</td></tr>`)
    .join('')
  const html = `<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8" />
  <title>Hoja de vida - ${escapeHtml(lotCode(assetSheetLot.value))}</title>
  <style>
    body { font-family: Arial, sans-serif; color: #111827; margin: 20px; }
    h1, h2 { margin: 0 0 8px 0; }
    .meta { color: #4b5563; margin-bottom: 18px; }
    table { width: 100%; border-collapse: collapse; margin-top: 8px; }
    th, td { border: 1px solid #d1d5db; padding: 8px; vertical-align: top; text-align: left; font-size: 12px; }
    th { width: 30%; background: #f3f4f6; font-weight: 600; }
    .timeline th { background: #eef2ff; }
    .section { margin-top: 18px; }
  </style>
</head>
<body>
  <h1>Hoja de vida del activo</h1>
  <p class="meta">
    Equipo: ${escapeHtml(assetSheetLot.value.name || '—')}<br/>
    Código: ${escapeHtml(lotCode(assetSheetLot.value))}<br/>
    Generado: ${escapeHtml(formatDateTime(new Date().toISOString()))}
  </p>

  <div class="section">
    <h2>Ficha técnica</h2>
    <table>${detailRows}</table>
  </div>

  <div class="section">
    <h2>Trazabilidad / línea de tiempo</h2>
    <table class="timeline">
      <thead>
        <tr><th>Fecha</th><th>Evento</th><th>Responsable</th><th>Detalle</th></tr>
      </thead>
      <tbody>${timelineRows || '<tr><td colspan="4">Sin eventos registrados.</td></tr>'}</tbody>
    </table>
  </div>
</body>
</html>`
  const w = window.open('', '_blank', 'noopener,noreferrer,width=1080,height=820')
  if (!w) {
    alert('No se pudo abrir la vista de impresión. Verifique el bloqueador de ventanas emergentes.')
    return
  }
  w.document.open()
  w.document.write(html)
  w.document.close()
  w.focus()
  w.print()
}

async function loadPendingBajaRequests() {
  if (!isAdmin.value) return
  try {
    const res = await fetchInventoryLifecycleRequests({ status: 'pending', target_status: 'baja', per_page: 50 })
    pendingBajaRequests.value = res.data || []
  } catch {
    pendingBajaRequests.value = []
  }
}

async function approveBajaRequest(row, decision) {
  try {
    await approveInventoryLifecycleRequest(row.id, { decision })
    await Promise.all([loadPendingBajaRequests(), loadLots()])
  } catch (e) {
    alert(e.message || 'No se pudo registrar la decisión.')
  }
}

watch(
  () => lotForm.value.asset_type,
  (next, prev) => {
    if (next !== prev) {
      lotForm.value.asset_subcategory = ''
    }
  }
)

async function onDeleteLot(row) {
  if (!confirm(`¿Eliminar el lote «${row.name}»? Solo es posible si no tiene ventas.`)) return
  try {
    await deleteInventoryLot(row.id, inventoryMutationQuery())
    await loadLots()
  } catch (e) {
    alert(e.message || 'No se pudo eliminar.')
  }
}


let searchTimer = null
watch(lotsQ, () => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    lotsPage.value = 1
    loadLots()
  }, 350)
})

watch(lotsPage, () => loadLots())
watch(rentalsPage, () => loadRentals())
watch(salesPage, () => loadSales())
onMounted(async () => {
  document.addEventListener('mousedown', onInventoryFormMousedown)
  try {
    const st = typeof history !== 'undefined' ? history.state : null
    if (st && typeof st.empresaNombre === 'string' && st.empresaNombre.trim()) {
      companyDisplayName.value = st.empresaNombre.trim()
    }
  } catch {
    /* ignore */
  }
  try {
    const opts = await fetchInventoryFormFieldOptions()
    locationOptions.value = opts.locations.slice(0, 5)
    if (opts.asset_types.length) assetTypeList.value = opts.asset_types
  } catch {
    /* defaults en refs */
  }
  const extraLoads = isCompanyInventoryRoute.value ? [] : [loadRentals(), loadSales()]
  await Promise.all([loadLots(), loadPendingBajaRequests(), ...extraLoads])
})

onUnmounted(() => {
  document.removeEventListener('mousedown', onInventoryFormMousedown)
})
</script>

<template>
  <div class="mx-auto w-full max-w-[96rem] space-y-6 px-2 text-slate-200 sm:px-4 lg:px-6">
    <div class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between">
      <div>
        <RouterLink
          v-if="isCompanyInventoryRoute"
          to="/admin/empresas"
          class="mb-2 inline-block text-sm font-medium text-sky-400 hover:text-sky-300"
        >
          ← Volver a empresas
        </RouterLink>
        <h1 class="text-xl font-bold text-white sm:text-2xl">
          <template v-if="isCompanyInventoryRoute">Inventario — {{ companyDisplayLabel }}</template>
          <template v-else-if="isInternalAdminInventory">Inventario interno</template>
          <template v-else>Mi inventario</template>
        </h1>
        <p class="mt-1 max-w-2xl text-sm text-slate-400">
          <template v-if="isCompanyInventoryRoute">
            Activos y equipos de esta empresa. Al registrar un artículo sin número de serie ni código manual, el sistema asigna un
            código de inventario único (sigla de factura de la empresa + correlativo).
          </template>
          <template v-else-if="isInternalAdminInventory">
            Stock y activos de la operación (sin empresa cliente en el lote).
          </template>
          <template v-else>
            Activos donde usted figura como titular. Para altas y ajustes globales consulte con administración.
          </template>
        </p>
      </div>
      <div class="flex w-full flex-wrap gap-2 xl:w-auto xl:justify-end">
        <button
          v-if="isAdmin"
          type="button"
          class="w-full rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-sky-500 sm:w-auto"
          @click="openCreateLot"
        >
          Registrar activo
        </button>
      </div>
    </div>

    <section class="rounded-2xl border border-slate-700/60 bg-[#1a1f2a]/80 p-3 shadow-lg sm:p-5 lg:p-6">
      <div class="mb-6 flex flex-col gap-3 pb-4 xl:flex-row xl:items-center xl:justify-between xl:border-b xl:border-slate-700/35">
        <div class="flex flex-wrap gap-2">
          <button
            type="button"
            class="rounded-xl px-3 py-2 text-sm font-medium transition"
            :class="
              activeTab === 'activos'
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
                : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'
            "
            @click="activeTab = 'activos'"
          >
            Activos
          </button>
          <button
            v-if="!isCompanyInventoryRoute"
            type="button"
            class="rounded-xl px-3 py-2 text-sm font-medium transition"
            :class="
              activeTab === 'alquiler'
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
                : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'
            "
            @click="activeTab = 'alquiler'"
          >
            Alquiler
          </button>
          <button
            v-if="!isCompanyInventoryRoute"
            type="button"
            class="rounded-xl px-3 py-2 text-sm font-medium transition"
            :class="
              activeTab === 'ventas'
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
                : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'
            "
            @click="activeTab = 'ventas'"
          >
            Historial de ventas
          </button>
          <button
            type="button"
            class="rounded-xl px-3 py-2 text-sm font-medium transition"
            :class="
              activeTab === 'vendidos'
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
                : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'
            "
            @click="activeTab = 'vendidos'"
          >
            Vendidos
          </button>
          <button
            v-if="!isCompanyInventoryRoute"
            type="button"
            class="rounded-xl px-3 py-2 text-sm font-medium transition"
            :class="
              activeTab === 'en_alquiler_lotes'
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
                : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'
            "
            @click="activeTab = 'en_alquiler_lotes'"
          >
            En alquiler (lotes)
          </button>
          <button
            type="button"
            class="rounded-xl px-3 py-2 text-sm font-medium transition"
            :class="
              activeTab === 'bajas'
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
                : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'
            "
            @click="activeTab = 'bajas'"
          >
            Equipos dados de baja
          </button>
          <button
            type="button"
            class="rounded-xl px-3 py-2 text-sm font-medium transition"
            :class="
              activeTab === 'reparacion'
                ? 'bg-blue-600 text-white shadow-md shadow-blue-600/20'
                : 'text-slate-400 hover:bg-slate-800/60 hover:text-white'
            "
            @click="activeTab = 'reparacion'"
          >
            En reparación / por reparar
          </button>
        </div>
        <input
          v-model="activeTabSearch"
          type="search"
          :placeholder="activeTabSearchPlaceholder"
          class="w-full rounded-xl border border-slate-600/80 bg-[#13161f] px-3 py-2 text-sm text-slate-200 placeholder:text-slate-500 focus:border-sky-500/60 focus:outline-none focus:ring-1 focus:ring-sky-500/40 xl:max-w-lg"
        />
      </div>
      <div
        v-if="isAdmin && pendingBajaRequests.length"
        class="mb-4 rounded-xl border border-amber-500/40 bg-amber-500/10 p-3"
      >
        <h3 class="mb-2 text-sm font-semibold text-amber-200">Solicitudes de baja pendientes</h3>
        <ul class="space-y-2">
          <li
            v-for="req in pendingBajaRequests"
            :key="`pending-${req.id}`"
            class="flex flex-col gap-2 rounded-lg border border-amber-500/25 bg-black/10 px-3 py-2 text-sm sm:flex-row sm:items-center sm:justify-between"
          >
            <div>
              <p class="text-amber-100">
                {{ req.lot?.name || `Equipo #${req.inventory_lot_id}` }} · Aprobaciones:
                {{ (req.approvals || []).filter((a) => a.decision === 'approved').length }}/{{ req.required_approvals }}
              </p>
              <p class="text-xs text-amber-300/90">{{ req.reason }}</p>
            </div>
            <div class="flex gap-2">
              <button
                type="button"
                class="rounded-md border border-emerald-500/50 px-2 py-1 text-xs text-emerald-200 hover:bg-emerald-500/10"
                @click="approveBajaRequest(req, 'approved')"
              >
                Aprobar
              </button>
              <button
                type="button"
                class="rounded-md border border-rose-500/50 px-2 py-1 text-xs text-rose-200 hover:bg-rose-500/10"
                @click="approveBajaRequest(req, 'rejected')"
              >
                Rechazar
              </button>
            </div>
          </li>
        </ul>
      </div>

      <template v-if="activeTab === 'activos'">
        <p v-if="lotsError" class="mb-3 text-sm text-rose-400">{{ lotsError }}</p>
        <div v-if="lotsLoading" class="py-8 text-center text-slate-500">Cargando…</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-[1080px] w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-700/80 text-slate-400">
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('lots', 'code')">Código{{ tableSortIndicator('lots', 'code') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('lots', 'brand')">Marca{{ tableSortIndicator('lots', 'brand') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('lots', 'name')">Activo / dispositivo{{ tableSortIndicator('lots', 'name') }}</button>
                </th>
                <th v-if="isAdminInventoryRoute" class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('lots', 'owner')">
                    Titular{{ tableSortIndicator('lots', 'owner') }}
                  </button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('lots', 'entry_date')">Fecha de entrada{{ tableSortIndicator('lots', 'entry_date') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('lots', 'location')">Ubicación{{ tableSortIndicator('lots', 'location') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('lots', 'stock')">
                    Stock (disp. / alq. / tot.){{ tableSortIndicator('lots', 'stock') }}
                  </button>
                </th>
                <th v-if="!isCompanyInventoryRoute" class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('lots', 'price')">Precio{{ tableSortIndicator('lots', 'price') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('lots', 'status')">Estado{{ tableSortIndicator('lots', 'status') }}</button>
                </th>
                <th v-if="isAdmin" class="pb-2 font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in sortedLots" :key="row.id" class="border-b border-slate-800/80">
                <td class="py-2 pr-3 align-top font-mono text-slate-300">
                  <button type="button" class="text-sky-300 hover:text-sky-200 hover:underline" @click="openAssetSheet(row)">
                    {{ readDescriptionField(row.description, 'Codigo interno') || row.sku || '—' }}
                  </button>
                </td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ readDescriptionField(row.description, 'Marca') || '—' }}</td>
                <td class="py-2 pr-3 align-top text-white">{{ row.name }}</td>
                <td v-if="isAdminInventoryRoute" class="py-2 pr-3 align-top text-slate-300">
                  {{ row.owner?.nombre || '—' }}
                </td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ row.created_at?.slice(0, 10) || '—' }}</td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ readDescriptionField(row.description, 'Ubicacion') || '—' }}</td>
                <td class="py-2 pr-3 align-top text-xs leading-snug">
                  <span class="font-medium text-emerald-300">{{ row.quantity_available }}</span>
                  <span class="text-slate-600"> / </span>
                  <span class="text-amber-300">{{ Number(row.units_on_rent || 0) }}</span>
                  <span class="text-slate-600"> / </span>
                  <span class="text-slate-200">{{
                    row.quantity_total != null && row.quantity_total !== undefined
                      ? row.quantity_total
                      : Number(row.quantity_available || 0) + Number(row.units_on_rent || 0)
                  }}</span>
                </td>
                <td v-if="!isCompanyInventoryRoute" class="py-2 pr-3 align-top">{{ moneyCo(row.unit_price) }}</td>
                <td class="py-2 pr-3 align-top">
                  <span
                    :class="
                      row.lifecycle_status === 'activo'
                        ? 'text-emerald-400'
                        : row.lifecycle_status === 'reparacion'
                          ? 'text-amber-300'
                          : row.lifecycle_status === 'vendido'
                            ? 'text-sky-300'
                            : 'text-rose-300'
                    "
                  >
                    {{ lotLifecycleStatusLabel(row) }}
                  </span>
                </td>
                <td v-if="isAdmin" class="py-2 align-top">
                  <button type="button" class="mr-2 text-sky-400 hover:text-sky-300" @click="openEditLot(row)">
                    Editar
                  </button>
                  <button
                    v-if="availableLifecycleActions(row).length"
                    type="button"
                    class="mr-2 text-amber-300 hover:text-amber-200"
                    @click="openReportModal(row)"
                  >
                    Reporte
                  </button>
                  <button type="button" class="text-rose-400/90 hover:text-rose-300" @click="onDeleteLot(row)">
                    Eliminar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="lotsMeta && lotsMeta.last_page > 1" class="mt-4 flex items-center justify-center gap-3 text-sm">
          <button
            type="button"
            class="rounded-lg border border-slate-600 px-3 py-1 disabled:opacity-40"
            :disabled="lotsMeta.current_page <= 1"
            @click="lotsPage = lotsMeta.current_page - 1"
          >
            Anterior
          </button>
          <span class="text-slate-500"
            >Página {{ lotsMeta.current_page }} / {{ lotsMeta.last_page }}</span
          >
          <button
            type="button"
            class="rounded-lg border border-slate-600 px-3 py-1 disabled:opacity-40"
            :disabled="lotsMeta.current_page >= lotsMeta.last_page"
            @click="lotsPage = lotsMeta.current_page + 1"
          >
            Siguiente
          </button>
        </div>
      </template>

      <template v-else-if="activeTab === 'alquiler' && !isCompanyInventoryRoute">
        <p v-if="rentalsError" class="mb-2 text-sm text-rose-400">{{ rentalsError }}</p>
        <div v-if="rentalsLoading" class="py-6 text-slate-500">Cargando…</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-[920px] w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-700/80 text-slate-400">
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentals', 'id')">#{{ tableSortIndicator('rentals', 'id') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentals', 'started_at')">Inicio{{ tableSortIndicator('rentals', 'started_at') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentals', 'closed_at')">Cierre{{ tableSortIndicator('rentals', 'closed_at') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentals', 'status')">Estado{{ tableSortIndicator('rentals', 'status') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentals', 'customer_name')">Cliente{{ tableSortIndicator('rentals', 'customer_name') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentals', 'customer_phone')">Teléfono{{ tableSortIndicator('rentals', 'customer_phone') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium min-w-[200px]">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentals', 'equipos')">Equipos{{ tableSortIndicator('rentals', 'equipos') }}</button>
                </th>
                <th class="pb-2 font-medium text-right">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentals', 'total')">Total{{ tableSortIndicator('rentals', 'total') }}</button>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in sortedRentals" :key="r.id" class="border-b border-slate-800/80">
                <td class="py-2 pr-3 align-top font-mono text-slate-300">{{ r.id }}</td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ r.started_at?.slice(0, 10) || '—' }}</td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ r.closed_at?.slice(0, 10) || '—' }}</td>
                <td class="py-2 pr-3 align-top">
                  <span :class="r.status === 'active' ? 'font-medium text-amber-400' : 'font-medium text-emerald-400'">
                    {{ r.status === 'active' ? 'Activo' : 'Cerrado' }}
                  </span>
                </td>
                <td class="py-2 pr-3 align-top text-white">{{ r.customer_name || '—' }}</td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ r.customer_phone || '—' }}</td>
                <td class="py-2 pr-3 align-top text-slate-400">{{ formatRentalLinesSummary(r) }}</td>
                <td class="py-2 align-top text-right text-slate-200">{{ moneyCo(sumInventoryLineTotals(r.lines)) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="!sortedRentals.length" class="mt-3 text-sm text-slate-500">No hay equipos alquilados para mostrar.</p>
        </div>
        <div v-if="rentalsMeta && rentalsMeta.last_page > 1" class="mt-4 flex items-center justify-center gap-3 text-sm">
          <button
            type="button"
            class="rounded-lg border border-slate-600 px-3 py-1 disabled:opacity-40"
            :disabled="rentalsMeta.current_page <= 1"
            @click="rentalsPage = rentalsMeta.current_page - 1"
          >
            Anterior
          </button>
          <span class="text-slate-500">Página {{ rentalsMeta.current_page }} / {{ rentalsMeta.last_page }}</span>
          <button
            type="button"
            class="rounded-lg border border-slate-600 px-3 py-1 disabled:opacity-40"
            :disabled="rentalsMeta.current_page >= rentalsMeta.last_page"
            @click="rentalsPage = rentalsMeta.current_page + 1"
          >
            Siguiente
          </button>
        </div>
      </template>

      <template v-else-if="activeTab === 'ventas' && !isCompanyInventoryRoute">
        <p v-if="salesError" class="mb-2 text-sm text-rose-400">{{ salesError }}</p>
        <div v-if="salesLoading" class="py-6 text-slate-500">Cargando…</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-[880px] w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-700/80 text-slate-400">
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('sales', 'id')">#{{ tableSortIndicator('sales', 'id') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('sales', 'created_at')">Fecha{{ tableSortIndicator('sales', 'created_at') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium text-right">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('sales', 'total')">Total{{ tableSortIndicator('sales', 'total') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('sales', 'vendedor')">Vendedor{{ tableSortIndicator('sales', 'vendedor') }}</button>
                </th>
                <th class="pb-2 font-medium min-w-[220px]">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('sales', 'detalle')">Detalle{{ tableSortIndicator('sales', 'detalle') }}</button>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="s in sortedSales" :key="s.id" class="border-b border-slate-800/80">
                <td class="py-2 pr-3 align-top font-mono text-slate-300">{{ s.id }}</td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ s.created_at?.slice(0, 10) || '—' }}</td>
                <td class="py-2 pr-3 align-top text-right font-medium text-emerald-400">{{ moneyCo(s.total_amount) }}</td>
                <td class="py-2 pr-3 align-top text-white">{{ s.sold_by?.nombre || '—' }}</td>
                <td class="py-2 align-top text-slate-400">{{ formatSaleLinesSummary(s) }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="!sortedSales.length" class="mt-3 text-sm text-slate-500">No hay ventas registradas en el historial.</p>
        </div>
        <div v-if="salesMeta && salesMeta.last_page > 1" class="mt-4 flex items-center justify-center gap-3 text-sm">
          <button
            type="button"
            class="rounded-lg border border-slate-600 px-3 py-1 disabled:opacity-40"
            :disabled="salesMeta.current_page <= 1"
            @click="salesPage = salesMeta.current_page - 1"
          >
            Anterior
          </button>
          <span class="text-slate-500">Página {{ salesMeta.current_page }} / {{ salesMeta.last_page }}</span>
          <button
            type="button"
            class="rounded-lg border border-slate-600 px-3 py-1 disabled:opacity-40"
            :disabled="salesMeta.current_page >= salesMeta.last_page"
            @click="salesPage = salesMeta.current_page + 1"
          >
            Siguiente
          </button>
        </div>
      </template>

      <template v-else-if="activeTab === 'vendidos'">
        <div class="overflow-x-auto">
          <table class="min-w-[880px] w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-700/80 text-slate-400">
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('vendidos', 'code')">
                    Código{{ tableSortIndicator('vendidos', 'code') }}
                  </button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('vendidos', 'name')">
                    Activo / dispositivo{{ tableSortIndicator('vendidos', 'name') }}
                  </button>
                </th>
                <th v-if="isAdminInventoryRoute" class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('vendidos', 'owner')">
                    Titular{{ tableSortIndicator('vendidos', 'owner') }}
                  </button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('vendidos', 'entry_date')">
                    Fecha de entrada{{ tableSortIndicator('vendidos', 'entry_date') }}
                  </button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('vendidos', 'stock')">
                    Stock final{{ tableSortIndicator('vendidos', 'stock') }}
                  </button>
                </th>
                <th class="pb-2 pr-3 font-medium">Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in sortedVendidos" :key="`vend-${row.id}`" class="border-b border-slate-800/80">
                <td class="py-2 pr-3 align-top font-mono text-slate-300">
                  <button type="button" class="text-sky-300 hover:text-sky-200 hover:underline" @click="openAssetSheet(row)">
                    {{ readDescriptionField(row.description, 'Codigo interno') || row.sku || '—' }}
                  </button>
                </td>
                <td class="py-2 pr-3 align-top text-white">{{ row.name }}</td>
                <td v-if="isAdminInventoryRoute" class="py-2 pr-3 align-top text-slate-300">
                  {{ row.owner?.nombre || '—' }}
                </td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ row.created_at?.slice(0, 10) || '—' }}</td>
                <td class="py-2 pr-3 align-top text-xs">
                  <span class="text-emerald-300">{{ row.quantity_available }}</span>
                  <span class="text-slate-600"> / </span>
                  <span class="text-amber-300">{{ Number(row.units_on_rent || 0) }}</span>
                  <span class="text-slate-600"> / </span>
                  <span class="text-slate-200">{{
                    row.quantity_total != null && row.quantity_total !== undefined
                      ? row.quantity_total
                      : Number(row.quantity_available || 0) + Number(row.units_on_rent || 0)
                  }}</span>
                </td>
                <td class="py-2 pr-3 align-top text-sky-300">Vendido</td>
              </tr>
              <tr v-if="!sortedVendidos.length">
                <td :colspan="isAdminInventoryRoute ? 6 : 5" class="py-3 text-slate-500">
                  No hay equipos marcados como vendidos en esta vista.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else-if="activeTab === 'en_alquiler_lotes' && !isCompanyInventoryRoute">
        <div class="overflow-x-auto">
          <table class="min-w-[920px] w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-700/80 text-slate-400">
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentLots', 'code')">
                    Código{{ tableSortIndicator('rentLots', 'code') }}
                  </button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentLots', 'name')">
                    Activo / dispositivo{{ tableSortIndicator('rentLots', 'name') }}
                  </button>
                </th>
                <th v-if="isAdminInventoryRoute" class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentLots', 'owner')">
                    Titular{{ tableSortIndicator('rentLots', 'owner') }}
                  </button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentLots', 'location')">
                    Ubicación{{ tableSortIndicator('rentLots', 'location') }}
                  </button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('rentLots', 'stock')">
                    Stock (disp. / alq. / tot.){{ tableSortIndicator('rentLots', 'stock') }}
                  </button>
                </th>
                <th class="pb-2 pr-3 font-medium">Estado ciclo</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in sortedRentLots" :key="`rentlot-${row.id}`" class="border-b border-slate-800/80">
                <td class="py-2 pr-3 align-top font-mono text-slate-300">
                  <button type="button" class="text-sky-300 hover:text-sky-200 hover:underline" @click="openAssetSheet(row)">
                    {{ readDescriptionField(row.description, 'Codigo interno') || row.sku || '—' }}
                  </button>
                </td>
                <td class="py-2 pr-3 align-top text-white">{{ row.name }}</td>
                <td v-if="isAdminInventoryRoute" class="py-2 pr-3 align-top text-slate-300">
                  {{ row.owner?.nombre || '—' }}
                </td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ readDescriptionField(row.description, 'Ubicacion') || '—' }}</td>
                <td class="py-2 pr-3 align-top text-xs leading-snug">
                  <span class="font-medium text-emerald-300">{{ row.quantity_available }}</span>
                  <span class="text-slate-600"> / </span>
                  <span class="font-medium text-amber-300">{{ Number(row.units_on_rent || 0) }}</span>
                  <span class="text-slate-600"> / </span>
                  <span class="text-slate-200">{{
                    row.quantity_total != null && row.quantity_total !== undefined
                      ? row.quantity_total
                      : Number(row.quantity_available || 0) + Number(row.units_on_rent || 0)
                  }}</span>
                </td>
                <td class="py-2 pr-3 align-top">
                  <span
                    :class="
                      row.lifecycle_status === 'activo'
                        ? 'text-emerald-400'
                        : row.lifecycle_status === 'reparacion'
                          ? 'text-amber-300'
                          : 'text-rose-300'
                    "
                  >
                    {{ lotLifecycleStatusLabel(row) }}
                  </span>
                </td>
              </tr>
              <tr v-if="!sortedRentLots.length">
                <td :colspan="isAdminInventoryRoute ? 6 : 5" class="py-3 text-slate-500">
                  No hay lotes con unidades actualmente en alquiler.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else-if="activeTab === 'bajas'">
        <div class="overflow-x-auto">
          <table class="min-w-[860px] w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-700/80 text-slate-400">
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('bajas', 'name')">Activo / dispositivo{{ tableSortIndicator('bajas', 'name') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('bajas', 'entry_date')">Fecha de entrada{{ tableSortIndicator('bajas', 'entry_date') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('bajas', 'code')">Código{{ tableSortIndicator('bajas', 'code') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('bajas', 'detail')">Detalle{{ tableSortIndicator('bajas', 'detail') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('bajas', 'stock')">Stock{{ tableSortIndicator('bajas', 'stock') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in sortedBajas" :key="`baja-${row.id}`" class="border-b border-slate-800/80">
                <td class="py-2 pr-3 align-top text-white">{{ row.name }}</td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ row.created_at?.slice(0, 10) || '—' }}</td>
                <td class="py-2 pr-3 align-top font-mono text-slate-300">
                  <button type="button" class="text-sky-300 hover:text-sky-200 hover:underline" @click="openAssetSheet(row)">
                    {{ readDescriptionField(row.description, 'Codigo interno') || row.sku || '—' }}
                  </button>
                </td>
                <td class="py-2 pr-3 align-top text-slate-400">{{ row.description || '—' }}</td>
                <td class="py-2 pr-3 align-top">{{ row.quantity_available }}</td>
                <td class="py-2 pr-3 align-top text-amber-400">Dado de baja</td>
              </tr>
              <tr v-if="!sortedBajas.length">
                <td colspan="6" class="py-3 text-slate-500">No hay equipos dados de baja.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else-if="activeTab === 'reparacion'">
        <div class="overflow-x-auto">
          <table class="min-w-[900px] w-full text-left text-sm">
            <thead>
              <tr class="border-b border-slate-700/80 text-slate-400">
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('repair', 'name')">Activo / dispositivo{{ tableSortIndicator('repair', 'name') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('repair', 'code')">Código{{ tableSortIndicator('repair', 'code') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('repair', 'location')">Ubicación{{ tableSortIndicator('repair', 'location') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('repair', 'condition')">Estado físico{{ tableSortIndicator('repair', 'condition') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('repair', 'detail')">Detalle{{ tableSortIndicator('repair', 'detail') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
                  <button type="button" class="hover:text-white" @click="toggleTableSort('repair', 'status')">Estado{{ tableSortIndicator('repair', 'status') }}</button>
                </th>
                <th v-if="isAdmin" class="pb-2 pr-3 font-medium">Acciones</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in sortedRepair" :key="`rep-${row.id}`" class="border-b border-slate-800/80">
                <td class="py-2 pr-3 align-top text-white">{{ row.name }}</td>
                <td class="py-2 pr-3 align-top font-mono text-slate-300">
                  <button type="button" class="text-sky-300 hover:text-sky-200 hover:underline" @click="openAssetSheet(row)">
                    {{ readDescriptionField(row.description, 'Codigo interno') || row.sku || '—' }}
                  </button>
                </td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ readDescriptionField(row.description, 'Ubicacion') || '—' }}</td>
                <td class="py-2 pr-3 align-top text-amber-300">
                  {{ readDescriptionField(row.description, 'Estado fisico') || '—' }}
                </td>
                <td class="py-2 pr-3 align-top text-slate-400">{{ row.description || '—' }}</td>
                <td class="py-2 pr-3 align-top">
                  <span class="text-amber-300">
                    {{ lotLifecycleStatusLabel(row) }}
                  </span>
                </td>
                <td v-if="isAdmin" class="py-2 pr-3 align-top">
                  <button type="button" class="mr-2 text-sky-300 hover:text-sky-200" @click="openReportModal(row, 'activo')">
                    Activar
                  </button>
                  <button type="button" class="text-rose-300 hover:text-rose-200" @click="openReportModal(row, 'baja')">
                    Dar de baja
                  </button>
                </td>
              </tr>
              <tr v-if="!sortedRepair.length">
                <td :colspan="isAdmin ? 7 : 6" class="py-3 text-slate-500">No hay equipos en reparación o pendientes de reparación.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <div
      v-if="reportModalOpen"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 py-8 sm:py-10"
      role="dialog"
      aria-modal="true"
      @click.self="reportModalOpen = false"
    >
      <div class="my-auto w-full max-w-2xl rounded-2xl border border-slate-600 bg-[#1c212c] p-5 shadow-2xl">
        <h3 class="text-lg font-semibold text-white">Reporte de estado del equipo</h3>
        <p class="mt-1 text-sm text-slate-400">
          {{ reportLot?.name || '—' }} · Estado actual: {{ lotLifecycleStatusLabel(reportLot) }}
        </p>
        <p v-if="reportError" class="mt-2 text-sm text-rose-400">{{ reportError }}</p>
        <form class="mt-4 space-y-3" @submit.prevent="submitLifecycleReport">
          <label class="block text-sm">
            <span class="text-slate-400">Acción</span>
            <select
              v-model="reportTargetStatus"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            >
              <option v-for="action in availableLifecycleActions(reportLot)" :key="action" :value="action">
                {{ action === 'reparacion' ? 'Enviar a reparación' : action === 'activo' ? 'Activar' : 'Dar de baja' }}
              </option>
            </select>
          </label>
          <label class="block text-sm">
            <span class="text-slate-400">Motivo / justificación</span>
            <textarea
              v-model="reportReason"
              rows="3"
              required
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <label v-if="reportTargetStatus === 'activo'" class="block text-sm">
            <span class="text-slate-400">Detalle de reparación realizada</span>
            <textarea
              v-model="reportResolution"
              rows="2"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <div class="flex justify-end gap-2 pt-1">
            <button type="button" class="rounded-lg px-4 py-2 text-slate-400 hover:text-white" @click="reportModalOpen = false">
              Cancelar
            </button>
            <button
              type="submit"
              :disabled="reportSaving"
              class="rounded-lg bg-sky-600 px-4 py-2 font-medium text-white hover:bg-sky-500 disabled:opacity-50"
            >
              Guardar reporte
            </button>
          </div>
        </form>
        <div class="mt-5 border-t border-slate-700/80 pt-4">
          <h4 class="mb-2 text-sm font-semibold text-slate-300">Hoja de vida (últimos movimientos)</h4>
          <p v-if="reportHistoryLoading" class="text-xs text-slate-500">Cargando historial…</p>
          <ul v-else class="max-h-44 space-y-2 overflow-y-auto text-xs">
            <li v-for="ev in reportHistory" :key="`ev-${ev.id}`" class="rounded border border-slate-700/70 bg-slate-900/30 px-2 py-1.5">
              <p class="font-semibold text-slate-200">{{ ev.action }}</p>
              <p class="text-slate-400">{{ ev.occurred_at?.slice(0, 19)?.replace('T', ' ') || '—' }}</p>
            </li>
            <li v-if="!reportHistory.length" class="text-slate-500">Sin eventos registrados.</li>
          </ul>
        </div>
      </div>
    </div>

    <div
      v-if="assetSheetOpen"
      class="fixed inset-0 z-[55] flex items-start justify-center overflow-y-auto bg-black/70 p-4 py-8 sm:py-10"
      role="dialog"
      aria-modal="true"
      @click.self="assetSheetOpen = false"
    >
      <div class="my-auto w-full max-w-5xl rounded-2xl border border-slate-600 bg-[#1a202b] p-5 shadow-2xl">
        <div class="flex flex-col gap-3 border-b border-slate-700/80 pb-4 sm:flex-row sm:items-start sm:justify-between">
          <div>
            <h3 class="text-xl font-semibold text-white">Hoja de vida del activo</h3>
            <p class="mt-1 text-sm text-slate-400">
              {{ assetSheetLot?.name || '—' }} · Código: {{ lotCode(assetSheetLot) }} · Estado: {{ lotLifecycleStatusLabel(assetSheetLot) }}
            </p>
          </div>
          <div class="flex gap-2">
            <button
              type="button"
              class="rounded-lg border border-slate-500 bg-slate-800/60 px-4 py-2 text-sm font-medium text-slate-100 hover:bg-slate-700"
              @click="printAssetSheet"
            >
              Imprimir hoja de vida
            </button>
            <button type="button" class="rounded-lg px-3 py-2 text-slate-300 hover:text-white" @click="assetSheetOpen = false">
              Cerrar
            </button>
          </div>
        </div>

        <p v-if="assetSheetError" class="mt-3 text-sm text-rose-400">{{ assetSheetError }}</p>

        <div class="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-3">
          <section class="rounded-xl border border-slate-700/80 bg-[#111723] p-4 lg:col-span-1">
            <h4 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-300">Ficha técnica</h4>
            <dl class="space-y-2 text-sm">
              <div v-for="[label, value] in assetSheetDetails" :key="label" class="rounded-lg border border-slate-800/80 bg-slate-900/30 px-3 py-2">
                <dt class="text-[0.7rem] uppercase tracking-wide text-slate-500">{{ label }}</dt>
                <dd class="mt-0.5 font-medium text-slate-100">{{ value || '—' }}</dd>
              </div>
            </dl>
          </section>

          <section class="rounded-xl border border-slate-700/80 bg-[#111723] p-4 lg:col-span-2">
            <h4 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-300">Trazabilidad y línea de tiempo</h4>
            <p v-if="assetSheetLoading" class="text-sm text-slate-500">Cargando eventos del activo…</p>
            <ol v-else class="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
              <li
                v-for="ev in assetSheetTimeline"
                :key="`asset-ev-${ev.id}`"
                class="rounded-xl border border-slate-700/70 bg-slate-900/35 px-4 py-3"
              >
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <p class="font-semibold text-slate-100">{{ ev.title }}</p>
                  <p class="text-xs text-slate-400">{{ ev.when }}</p>
                </div>
                <p class="mt-1 text-xs text-slate-400">Responsable: {{ ev.actor }}</p>
                <p class="mt-2 text-sm text-slate-300">{{ ev.note || 'Evento registrado sin detalle adicional.' }}</p>
              </li>
              <li v-if="!assetSheetTimeline.length" class="rounded-xl border border-slate-700/60 bg-slate-900/30 px-4 py-3 text-sm text-slate-500">
                Sin eventos de trazabilidad para este activo.
              </li>
            </ol>
          </section>
        </div>
      </div>
    </div>

    <!-- Panel lateral (ventana de registro / edición de activo) -->
    <Teleport to="body">
      <div
        v-if="lotModalOpen"
        class="fixed inset-0 z-50 flex justify-end"
        role="dialog"
        aria-modal="true"
        aria-labelledby="inventory-lot-panel-title"
      >
        <div class="fixed inset-0 bg-black/55 backdrop-blur-[1px]" aria-hidden="true" @click="lotModalOpen = false" />
        <aside
          class="relative flex h-full w-full max-w-lg flex-col border-l border-slate-600 bg-[#1c212c] shadow-[-12px_0_40px_rgba(0,0,0,0.45)]"
          @click.stop
        >
          <header
            class="flex shrink-0 items-center justify-between gap-3 border-b border-slate-700/90 bg-[#232836] px-4 py-3"
          >
            <div>
              <h3 id="inventory-lot-panel-title" class="text-base font-semibold text-white">
                {{ lotModalMode === 'create' ? 'Registrar activo' : 'Editar activo' }}
              </h3>
              <p class="text-[0.7rem] text-slate-500">Ventana de datos del activo</p>
            </div>
            <button
              type="button"
              class="rounded-lg p-2 text-slate-400 transition hover:bg-slate-700/80 hover:text-white"
              aria-label="Cerrar"
              @click="lotModalOpen = false"
            >
              <span class="text-xl leading-none" aria-hidden="true">×</span>
            </button>
          </header>
          <div class="min-h-0 flex-1 overflow-y-auto p-5">
        <p v-if="lotFormError" class="mb-3 text-sm text-rose-400">{{ lotFormError }}</p>
        <form class="space-y-3" @submit.prevent="submitLot">
          <p
            v-if="lotModalMode === 'create' && isAdmin && isAdminInventoryRoute"
            class="rounded-lg border border-slate-600/80 bg-slate-900/40 px-3 py-2 text-xs text-slate-400"
          >
            El titular del activo es quien lo registra (su usuario de administración).
          </p>
          <label class="block text-sm">
            <span class="text-slate-400">Nombre</span>
            <input
              v-model="lotForm.name"
              required
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <div class="relative block text-sm" data-inventory-combobox>
            <span class="text-slate-400">Tipo de activo <span class="text-red-400">*</span></span>
            <button
              type="button"
              class="mt-1 flex w-full items-center justify-between gap-2 rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2.5 text-left text-sm text-white outline-none ring-sky-500/40 focus-visible:ring-2"
              @click.stop="toggleAssetTypeDropdown"
            >
              <span :class="lotForm.asset_type ? 'text-white' : 'text-slate-500'">
                {{ lotForm.asset_type || 'Seleccionar…' }}
              </span>
              <span class="text-slate-500" aria-hidden="true">▾</span>
            </button>
            <div
              v-show="assetTypeDropdownOpen"
              class="absolute left-0 right-0 z-20 mt-1 overflow-hidden rounded-lg border border-slate-600 bg-[#13161f] shadow-xl"
              @click.stop
            >
              <input
                v-model="assetTypeSearchQ"
                type="search"
                autocomplete="off"
                placeholder="Filtrar tipos…"
                class="w-full border-b border-slate-700/80 bg-[#141a22] px-3 py-2 text-sm text-white placeholder:text-slate-500 outline-none"
                @keydown.escape.prevent="assetTypeDropdownOpen = false"
              />
              <ul class="max-h-40 overflow-y-auto py-1" role="listbox">
                <li
                  v-for="opt in filteredAssetTypeOptions"
                  :key="opt"
                  role="option"
                  class="cursor-pointer px-3 py-2 text-sm transition hover:bg-slate-800/90"
                  :class="lotForm.asset_type === opt ? 'bg-sky-900/40 text-sky-200' : 'text-slate-200'"
                  @click="pickAssetType(opt)"
                >
                  {{ opt }}
                </li>
              </ul>
              <p v-if="!filteredAssetTypeOptions.length" class="px-3 py-2 text-xs text-slate-500">Sin coincidencias.</p>
            </div>
            <p class="mt-1 text-[0.7rem] text-slate-500">
              Lista definida en
              <RouterLink to="/admin/configuracion/inventario" class="text-sky-400 hover:underline">Configuración → Inventario</RouterLink>.
            </p>
          </div>
          <div
            v-show="lotForm.asset_type"
            class="relative block text-sm"
            data-inventory-subcategory-combobox
          >
            <span class="text-slate-400">Subcategoría <span class="text-red-400">*</span></span>
            <p class="mt-0.5 text-[0.7rem] text-slate-500">
              Elija una opción del listado según el tipo (evita errores al escribir a mano).
            </p>
            <button
              type="button"
              class="mt-1 flex w-full items-center justify-between gap-2 rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2.5 text-left text-sm text-white outline-none ring-sky-500/40 focus-visible:ring-2"
              :disabled="!subcategoriesForType.length"
              @click.stop="toggleSubcategoryDropdown"
            >
              <span :class="lotForm.asset_subcategory ? 'text-white' : 'text-slate-500'">
                {{ lotForm.asset_subcategory || 'Seleccionar subcategoría…' }}
              </span>
              <span class="text-slate-500" aria-hidden="true">▾</span>
            </button>
            <div
              v-show="subcategoryDropdownOpen"
              class="absolute left-0 right-0 z-20 mt-1 overflow-hidden rounded-lg border border-slate-600 bg-[#13161f] shadow-xl"
              @click.stop
            >
              <input
                v-model="subcategorySearchQ"
                type="search"
                autocomplete="off"
                placeholder="Filtrar subcategorías…"
                class="w-full border-b border-slate-700/80 bg-[#141a22] px-3 py-2 text-sm text-white placeholder:text-slate-500 outline-none"
                @keydown.escape.prevent="subcategoryDropdownOpen = false"
              />
              <ul class="max-h-40 overflow-y-auto py-1" role="listbox">
                <li
                  v-for="opt in filteredSubcategoryOptions"
                  :key="opt"
                  role="option"
                  class="cursor-pointer px-3 py-2 text-sm transition hover:bg-slate-800/90"
                  :class="lotForm.asset_subcategory === opt ? 'bg-sky-900/40 text-sky-200' : 'text-slate-200'"
                  @click="pickSubcategory(opt)"
                >
                  {{ opt }}
                </li>
              </ul>
              <p v-if="!filteredSubcategoryOptions.length" class="px-3 py-2 text-xs text-slate-500">Sin coincidencias.</p>
            </div>
          </div>
          <p v-if="!lotForm.asset_type" class="text-xs text-slate-500">Seleccione primero el tipo de activo para ver las subcategorías.</p>
          <label class="block text-sm">
            <span class="text-slate-400">Número de serie</span>
            <input
              v-model="lotForm.serial_number"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
            <span v-if="isCompanyInventoryRoute && lotModalMode === 'create'" class="mt-1 block text-xs text-slate-500">
              Si lo deja vacío y no hay código manual en SKU, el servidor asignará el código de inventario del activo.
            </span>
          </label>
          <label class="block text-sm">
            <span class="text-slate-400">Dirección MAC (opcional)</span>
            <input
              v-model="lotForm.mac_address"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
              placeholder="AA:BB:CC:DD:EE:FF"
            />
          </label>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="block text-sm">
              <span class="text-slate-400">Marca</span>
              <input
                v-model="lotForm.brand"
                class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
              />
            </label>
            <label class="block text-sm">
              <span class="text-slate-400">Modelo</span>
              <input
                v-model="lotForm.model"
                class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
              />
            </label>
          </div>
          <div class="relative block text-sm" data-inventory-combobox>
            <span class="text-slate-400">Ubicación</span>
            <button
              type="button"
              class="mt-1 flex w-full items-center justify-between gap-2 rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2.5 text-left text-sm text-white outline-none ring-sky-500/40 focus-visible:ring-2"
              @click.stop="toggleLocationDropdown"
            >
              <span :class="lotForm.location ? 'text-white' : 'text-slate-500'">
                {{ lotForm.location || 'Seleccionar ubicación…' }}
              </span>
              <span class="text-slate-500" aria-hidden="true">▾</span>
            </button>
            <div
              v-show="locationDropdownOpen"
              class="absolute left-0 right-0 z-20 mt-1 overflow-hidden rounded-lg border border-slate-600 bg-[#13161f] shadow-xl"
              @click.stop
            >
              <input
                v-model="locationSearchQ"
                type="search"
                autocomplete="off"
                placeholder="Filtrar ubicaciones…"
                class="w-full border-b border-slate-700/80 bg-[#141a22] px-3 py-2 text-sm text-white placeholder:text-slate-500 outline-none"
                @keydown.escape.prevent="locationDropdownOpen = false"
              />
              <ul class="max-h-40 overflow-y-auto py-1" role="listbox">
                <li
                  v-for="loc in filteredLocationOptions"
                  :key="loc"
                  role="option"
                  class="cursor-pointer px-3 py-2 text-sm transition hover:bg-slate-800/90"
                  :class="lotForm.location === loc ? 'bg-sky-900/40 text-sky-200' : 'text-slate-200'"
                  @click="pickLocation(loc)"
                >
                  {{ loc }}
                </li>
              </ul>
              <p v-if="!filteredLocationOptions.length" class="px-3 py-2 text-xs text-slate-500">Sin coincidencias.</p>
            </div>
          </div>
          <div class="block text-sm">
            <span class="text-slate-400">Condición de compra</span>
            <p class="mt-0.5 text-[0.7rem] text-slate-500">
              «Nuevo»: sin estado físico adicional. «Usado» (de segunda): elija calidad entre las opciones del listado.
            </p>
            <div class="mt-2 flex flex-wrap gap-2">
              <button
                type="button"
                class="rounded-lg border px-3 py-2 text-sm font-medium transition"
                :class="
                  lotForm.is_new_asset
                    ? 'border-sky-500 bg-sky-600/25 text-sky-100'
                    : 'border-slate-600 text-slate-300 hover:border-slate-500'
                "
                @click="lotForm.is_new_asset = true"
              >
                Nuevo
              </button>
              <button
                type="button"
                class="rounded-lg border px-3 py-2 text-sm font-medium transition"
                :class="
                  !lotForm.is_new_asset
                    ? 'border-sky-500 bg-sky-600/25 text-sky-100'
                    : 'border-slate-600 text-slate-300 hover:border-slate-500'
                "
                @click="lotForm.is_new_asset = false"
              >
                Usado
              </button>
            </div>
          </div>
          <div v-if="!lotForm.is_new_asset" class="relative block text-sm" data-inventory-combobox>
            <span class="text-slate-400">Estado físico <span class="text-red-400">*</span></span>
            <button
              type="button"
              class="mt-1 flex w-full items-center justify-between gap-2 rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2.5 text-left text-sm text-white outline-none ring-sky-500/40 focus-visible:ring-2"
              @click.stop="toggleConditionDropdown"
            >
              <span :class="lotForm.condition ? 'text-white' : 'text-slate-500'">
                {{ lotForm.condition || 'Seleccionar…' }}
              </span>
              <span class="text-slate-500" aria-hidden="true">▾</span>
            </button>
            <div
              v-show="conditionDropdownOpen"
              class="absolute left-0 right-0 z-20 mt-1 overflow-hidden rounded-lg border border-slate-600 bg-[#13161f] shadow-xl"
              @click.stop
            >
              <input
                v-model="conditionSearchQ"
                type="search"
                autocomplete="off"
                placeholder="Filtrar estados…"
                class="w-full border-b border-slate-700/80 bg-[#141a22] px-3 py-2 text-sm text-white placeholder:text-slate-500 outline-none"
                @keydown.escape.prevent="conditionDropdownOpen = false"
              />
              <ul class="max-h-40 overflow-y-auto py-1" role="listbox">
                <li
                  v-for="opt in filteredConditionOptions"
                  :key="opt"
                  role="option"
                  class="cursor-pointer px-3 py-2 text-sm transition hover:bg-slate-800/90"
                  :class="lotForm.condition === opt ? 'bg-sky-900/40 text-sky-200' : 'text-slate-200'"
                  @click="pickCondition(opt)"
                >
                  {{ opt }}
                </li>
              </ul>
              <p v-if="!filteredConditionOptions.length" class="px-3 py-2 text-xs text-slate-500">Sin coincidencias.</p>
            </div>
          </div>
          <div v-if="!isCompanyInventoryRoute" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <label class="flex items-center gap-2 text-sm text-slate-300">
              <input v-model="lotForm.allow_sale" type="checkbox" class="rounded border-slate-500" />
              Etiqueta para venta
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-300">
              <input v-model="lotForm.allow_rental" type="checkbox" class="rounded border-slate-500" />
              Etiqueta para alquiler
            </label>
          </div>
          <p
            v-else
            class="rounded-lg border border-slate-600/80 bg-slate-900/45 px-3 py-2 text-xs leading-relaxed text-slate-400"
          >
            Inventario de custodia por empresa: sin precio de lista ni habilitación comercial de venta/alquiler desde esta vista.
          </p>
          <label class="block text-sm">
            <span class="text-slate-400">Garantía hasta</span>
            <input
              v-model="lotForm.warranty_until"
              type="date"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <label class="block text-sm">
            <span class="text-slate-400">Descripción</span>
            <textarea
              v-model="lotForm.description"
              rows="2"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <label class="block text-sm">
            <span class="text-slate-400">Cantidad disponible</span>
            <input
              v-model.number="lotForm.quantity_available"
              type="number"
              min="0"
              required
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <label v-if="lotModalMode === 'edit'" class="block text-sm">
            <span class="text-slate-400">Motivo del ajuste (si cambia la cantidad)</span>
            <input
              v-model="lotForm.adjustment_note"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
              placeholder="Ej. inventario físico, devolución…"
            />
          </label>
          <label v-if="!isCompanyInventoryRoute" class="block text-sm">
            <span class="text-slate-400">Precio unitario</span>
            <input
              v-model="lotForm.unit_price"
              required
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <div class="flex justify-end gap-2 border-t border-slate-700/60 pt-4">
            <button type="button" class="rounded-lg px-4 py-2 text-slate-400 hover:text-white" @click="lotModalOpen = false">
              Cancelar
            </button>
            <button
              type="submit"
              :disabled="lotSaving"
              class="rounded-lg bg-sky-600 px-4 py-2 font-medium text-white hover:bg-sky-500 disabled:opacity-50"
            >
              Guardar
            </button>
          </div>
        </form>
          </div>
        </aside>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="lotSuccessModalOpen"
        class="fixed inset-0 z-[60] flex items-center justify-center bg-black/65 p-4 backdrop-blur-[2px]"
        role="presentation"
        @click.self="lotSuccessModalOpen = false"
      >
        <div
          class="w-full max-w-md rounded-2xl border border-emerald-500/30 bg-[#15231a] p-6 shadow-2xl shadow-emerald-950/40"
          role="dialog"
          aria-modal="true"
          aria-labelledby="lot-success-title"
        >
          <div class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-500/20 text-2xl text-emerald-400" aria-hidden="true">✓</div>
          <h3 id="lot-success-title" class="text-lg font-semibold text-white">Activo registrado</h3>
          <div class="mt-3 space-y-2 text-sm leading-relaxed text-slate-300">
            <p v-for="(line, i) in lotSuccessLines" :key="i" :class="i === 0 ? 'font-medium text-emerald-100/95' : ''">
              {{ line }}
            </p>
          </div>
          <button
            type="button"
            class="mt-6 w-full rounded-xl bg-emerald-600 py-3 text-sm font-semibold text-white transition hover:bg-emerald-500"
            @click="lotSuccessModalOpen = false"
          >
            Entendido
          </button>
        </div>
      </div>
    </Teleport>

  </div>
</template>
