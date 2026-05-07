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
  createInventoryLot,
  deleteInventoryLot,
  fetchInventoryLots,
  fetchInventoryFormFieldOptions,
  fetchInventoryRentals,
  fetchInventorySales,
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

const rentalsLoading = ref(false)
const rentalsError = ref('')
const rentals = ref([])
const rentalsMeta = ref(null)
const rentalsPage = ref(1)

const salesLoading = ref(false)
const salesError = ref('')
const sales = ref([])
const salesMeta = ref(null)
const salesPage = ref(1)

const sortState = ref({
  lots: { key: 'code', dir: 'asc' },
  rentals: { key: 'started_at', dir: 'desc' },
  sales: { key: 'created_at', dir: 'desc' },
  bajas: { key: 'name', dir: 'asc' },
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

const decommissionedLots = computed(() => (lots.value || []).filter((row) => !row.is_active))
const repairLots = computed(() =>
  (lots.value || []).filter((row) => {
    const condition = readDescriptionField(row.description, 'Estado fisico').toLowerCase()
    return condition.includes('requiere mantenimiento') || condition.includes('fuera de servicio')
  })
)

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

const sortedLots = computed(() => sortRows(lots.value, 'lots', sortableValue))
const sortedRentals = computed(() => sortRows(rentals.value, 'rentals', rentalSortValue))
const sortedSales = computed(() => sortRows(sales.value, 'sales', saleSortValue))
const sortedBajas = computed(() => sortRows(decommissionedLots.value, 'bajas', sortableValue))
const sortedRepair = computed(() => sortRows(repairLots.value, 'repair', repairSortValue))

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
const physicalConditionList = ref(['Nuevo', 'Bueno', 'Regular', 'Requiere mantenimiento', 'Fuera de servicio'])

function textMatchesQuery(text, q) {
  const needle = String(q || '').trim().toLowerCase()
  if (!needle) return true
  return String(text || '').toLowerCase().includes(needle)
}

const assetTypeSearchQ = ref('')
const locationSearchQ = ref('')
const conditionSearchQ = ref('')
const assetTypeDropdownOpen = ref(false)
const conditionDropdownOpen = ref(false)
const lotSuccessModalOpen = ref(false)
const lotSuccessLines = ref([])

const filteredAssetTypeOptions = computed(() =>
  assetTypeList.value.filter((t) => textMatchesQuery(t, assetTypeSearchQ.value))
)
const filteredLocationOptions = computed(() =>
  locationOptions.value.filter((loc) => textMatchesQuery(loc, locationSearchQ.value))
)
const filteredConditionOptions = computed(() =>
  physicalConditionList.value.filter((c) => textMatchesQuery(c, conditionSearchQ.value))
)

function resetLotPickerSearches() {
  assetTypeSearchQ.value = ''
  locationSearchQ.value = ''
  conditionSearchQ.value = ''
  assetTypeDropdownOpen.value = false
  conditionDropdownOpen.value = false
}

function closeLotComboboxes() {
  assetTypeDropdownOpen.value = false
  conditionDropdownOpen.value = false
}

function onInventoryFormMousedown(ev) {
  if (!lotModalOpen.value) return
  const el = ev.target
  if (typeof el?.closest === 'function' && el.closest('[data-inventory-combobox]')) return
  closeLotComboboxes()
}

function pickAssetType(label) {
  lotForm.value.asset_type = String(label || '').trim()
  lotForm.value.asset_subcategory = ''
  assetTypeDropdownOpen.value = false
  assetTypeSearchQ.value = ''
}
function pickLocation(loc) {
  lotForm.value.location = loc
}
function pickCondition(c) {
  lotForm.value.condition = String(c || '').trim()
  conditionDropdownOpen.value = false
  conditionSearchQ.value = ''
}
function toggleAssetTypeDropdown() {
  assetTypeDropdownOpen.value = !assetTypeDropdownOpen.value
  if (assetTypeDropdownOpen.value) conditionDropdownOpen.value = false
}
function toggleConditionDropdown() {
  conditionDropdownOpen.value = !conditionDropdownOpen.value
  if (conditionDropdownOpen.value) assetTypeDropdownOpen.value = false
}
const generatedAssetCode = ref('')
const generatedAssetTag = computed(() => (generatedAssetCode.value ? `HBM-${generatedAssetCode.value}` : ''))

function moneyCo(value) {
  const n = Number(value)
  if (Number.isNaN(n)) return '—'
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
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
  lotForm.value = {
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
    adjustment_note: '',
    owner_user_id: String(row.owner_user_id ?? ''),
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
  if (!String(lotForm.value.condition || '').trim()) {
    lotFormError.value = 'Seleccione el estado físico del activo.'
    lotSaving.value = false
    return
  }
  if (savingAsCreate && isAdmin.value && isAdminInventoryRoute.value && !auth.user?.id) {
    lotFormError.value = 'No se pudo determinar el usuario que registra el activo.'
    lotSaving.value = false
    return
  }
  try {
    const effectiveCode = generatedAssetCode.value || generateAssetCode()
    generatedAssetCode.value = effectiveCode
    const detailLines = [
      ...(isCompanyInventoryRoute.value ? [] : [`Codigo interno: ${effectiveCode}`]),
      lotForm.value.asset_type ? `Tipo: ${lotForm.value.asset_type}` : '',
      lotForm.value.asset_subcategory ? `Subcategoria: ${lotForm.value.asset_subcategory}` : '',
      lotForm.value.serial_number ? `Serial: ${lotForm.value.serial_number}` : '',
      lotForm.value.brand ? `Marca: ${lotForm.value.brand}` : '',
      lotForm.value.model ? `Modelo: ${lotForm.value.model}` : '',
      lotForm.value.location ? `Ubicacion: ${lotForm.value.location}` : '',
      lotForm.value.condition ? `Estado fisico: ${lotForm.value.condition}` : '',
      lotForm.value.warranty_until ? `Garantia hasta: ${lotForm.value.warranty_until}` : '',
      `Condicion de compra: ${lotForm.value.is_new_asset ? 'Nuevo' : 'Usado'}`,
      `Disponible para venta: ${lotForm.value.allow_sale ? 'Si' : 'No'}`,
      `Disponible para alquiler: ${lotForm.value.allow_rental ? 'Si' : 'No'}`,
    ].filter(Boolean)
    const fullDescription = [lotForm.value.description?.trim() || '', ...detailLines].filter(Boolean).join('\n')

    const manualSku = lotForm.value.sku?.trim() || lotForm.value.serial_number?.trim() || ''
    const body = {
      name: lotForm.value.name.trim(),
      description: fullDescription || null,
      quantity_available: Number(lotForm.value.quantity_available),
      unit_price: Number(String(lotForm.value.unit_price).replace(',', '.')),
      is_active: !!lotForm.value.is_active,
      allow_sale: !!lotForm.value.allow_sale,
      allow_rental: !!lotForm.value.allow_rental,
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
    if (opts.physical_conditions.length) physicalConditionList.value = opts.physical_conditions
  } catch {
    /* defaults en refs */
  }
  await Promise.all([loadLots(), loadRentals(), loadSales()])
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
      <div class="mb-4 flex flex-wrap gap-2">
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

      <template v-if="activeTab === 'activos'">
        <div class="mb-4 flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
          <h2 class="text-lg font-semibold text-white">Activos</h2>
          <input
            v-model="lotsQ"
            type="search"
            placeholder="Buscar por nombre del dispositivo…"
            class="w-full rounded-xl border border-slate-600/80 bg-[#13161f] px-3 py-2 text-sm text-slate-200 placeholder:text-slate-500 focus:border-sky-500/60 focus:outline-none focus:ring-1 focus:ring-sky-500/40 lg:max-w-lg"
          />
        </div>
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
                  <button type="button" class="hover:text-white" @click="toggleTableSort('lots', 'stock')">Stock{{ tableSortIndicator('lots', 'stock') }}</button>
                </th>
                <th class="pb-2 pr-3 font-medium">
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
                  {{ readDescriptionField(row.description, 'Codigo interno') || row.sku || '—' }}
                </td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ readDescriptionField(row.description, 'Marca') || '—' }}</td>
                <td class="py-2 pr-3 align-top text-white">{{ row.name }}</td>
                <td v-if="isAdminInventoryRoute" class="py-2 pr-3 align-top text-slate-300">
                  {{ row.owner?.nombre || '—' }}
                </td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ row.created_at?.slice(0, 10) || '—' }}</td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ readDescriptionField(row.description, 'Ubicacion') || '—' }}</td>
                <td class="py-2 pr-3 align-top">{{ row.quantity_available }}</td>
                <td class="py-2 pr-3 align-top">{{ moneyCo(row.unit_price) }}</td>
                <td class="py-2 pr-3 align-top">
                  <span :class="row.is_active ? 'text-emerald-400' : 'text-amber-400'">
                    {{ row.is_active ? 'Activo' : 'Inactivo' }}
                  </span>
                </td>
                <td v-if="isAdmin" class="py-2 align-top">
                  <button type="button" class="mr-2 text-sky-400 hover:text-sky-300" @click="openEditLot(row)">
                    Editar
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

      <template v-else-if="activeTab === 'alquiler'">
        <h2 class="mb-4 text-lg font-semibold text-white">Equipos alquilados</h2>
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
          <p v-if="!rentals.length" class="mt-3 text-sm text-slate-500">No hay equipos alquilados para mostrar.</p>
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

      <template v-else-if="activeTab === 'ventas'">
        <h2 class="mb-4 text-lg font-semibold text-white">Historial de ventas</h2>
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
          <p v-if="!sales.length" class="mt-3 text-sm text-slate-500">No hay ventas registradas en el historial.</p>
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

      <template v-else-if="activeTab === 'bajas'">
        <h2 class="mb-4 text-lg font-semibold text-white">Equipos dados de baja</h2>
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
                  {{ readDescriptionField(row.description, 'Codigo interno') || row.sku || '—' }}
                </td>
                <td class="py-2 pr-3 align-top text-slate-400">{{ row.description || '—' }}</td>
                <td class="py-2 pr-3 align-top">{{ row.quantity_available }}</td>
                <td class="py-2 pr-3 align-top text-amber-400">Dado de baja</td>
              </tr>
              <tr v-if="!decommissionedLots.length">
                <td colspan="6" class="py-3 text-slate-500">No hay equipos dados de baja.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <template v-else-if="activeTab === 'reparacion'">
        <h2 class="mb-4 text-lg font-semibold text-white">Equipos en reparación o por reparar</h2>
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
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in sortedRepair" :key="`rep-${row.id}`" class="border-b border-slate-800/80">
                <td class="py-2 pr-3 align-top text-white">{{ row.name }}</td>
                <td class="py-2 pr-3 align-top font-mono text-slate-300">
                  {{ readDescriptionField(row.description, 'Codigo interno') || row.sku || '—' }}
                </td>
                <td class="py-2 pr-3 align-top text-slate-300">{{ readDescriptionField(row.description, 'Ubicacion') || '—' }}</td>
                <td class="py-2 pr-3 align-top text-amber-300">
                  {{ readDescriptionField(row.description, 'Estado fisico') || '—' }}
                </td>
                <td class="py-2 pr-3 align-top text-slate-400">{{ row.description || '—' }}</td>
                <td class="py-2 pr-3 align-top">
                  <span :class="row.is_active ? 'text-amber-300' : 'text-rose-300'">
                    {{ row.is_active ? 'Pendiente de intervención' : 'Fuera de operación' }}
                  </span>
                </td>
              </tr>
              <tr v-if="!repairLots.length">
                <td colspan="6" class="py-3 text-slate-500">No hay equipos en reparación o pendientes de reparación.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>
    </section>

    <!-- Modal lote -->
    <div
      v-if="lotModalOpen"
      class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/60 p-4 py-8 sm:py-10"
      role="dialog"
      aria-modal="true"
      @click.self="lotModalOpen = false"
    >
      <div
        class="my-auto w-full max-w-lg overflow-visible rounded-2xl border border-slate-600 bg-[#1c212c] p-5 shadow-2xl"
        @click.stop
      >
        <h3 class="text-lg font-semibold text-white">
          {{ lotModalMode === 'create' ? 'Registrar activo' : 'Editar activo' }}
        </h3>
        <p v-if="lotFormError" class="mt-2 text-sm text-rose-400">{{ lotFormError }}</p>
        <form class="mt-4 space-y-3" @submit.prevent="submitLot">
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
          <label class="block text-sm">
            <span class="text-slate-400">Subcategoría / detalle (opcional)</span>
            <input
              v-model="lotForm.asset_subcategory"
              maxlength="120"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
              placeholder="Ej. Portátil, Router empresarial…"
            />
          </label>
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
          <div class="block text-sm">
            <span class="text-slate-400">Ubicación</span>
            <input
              v-model="locationSearchQ"
              type="search"
              autocomplete="off"
              placeholder="Buscar ubicación…"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white placeholder:text-slate-500"
            />
            <ul
              class="mt-1 max-h-28 divide-y divide-slate-700/50 overflow-y-auto rounded-lg border border-slate-600 bg-[#13161f]"
              role="listbox"
            >
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
            <p v-if="!filteredLocationOptions.length" class="mt-1 text-xs text-slate-500">Sin coincidencias.</p>
          </div>
          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div class="relative block text-sm" data-inventory-combobox>
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
              <p class="mt-1 text-[0.7rem] text-slate-500">Obligatorio. Lista editable en configuración de inventario.</p>
            </div>
            <div class="block text-sm">
              <span class="text-slate-400">Condición de compra</span>
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
          </div>
          <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <label class="flex items-center gap-2 text-sm text-slate-300">
              <input v-model="lotForm.allow_sale" type="checkbox" class="rounded border-slate-500" />
              Etiqueta para venta
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-300">
              <input v-model="lotForm.allow_rental" type="checkbox" class="rounded border-slate-500" />
              Etiqueta para alquiler
            </label>
          </div>
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
          <label class="block text-sm">
            <span class="text-slate-400">Precio unitario</span>
            <input
              v-model="lotForm.unit_price"
              required
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <div class="flex justify-end gap-2 pt-2">
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
    </div>

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
