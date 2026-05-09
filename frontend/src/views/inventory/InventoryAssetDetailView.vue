<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import {
  deleteInventoryLotAttachment,
  downloadInventoryLotLifecycleSheetPdf,
  fetchInventoryAuditEvents,
  fetchInventoryLot,
  fetchInventoryLotAttachments,
  fetchInventoryMovements,
  uploadInventoryLotAttachment,
} from '@/services/inventoryApi.js'
import { openPdfBlobInNewTab } from '@/utils/pdfBlob.js'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const loading = ref(false)
const error = ref('')
const lot = ref(null)
const historyRows = ref([])
const movementRows = ref([])
const attachments = ref([])
const attachmentsLoading = ref(false)
const uploadBusy = ref(false)
const uploadMsg = ref('')
const fileInput = ref(null)
const pdfBusy = ref(false)
/** Si no es null, la siguiente subida queda vinculada a este `inventory_audit_events.id`. */
const linkAuditEventId = ref(null)

const isCompanyScope = computed(() => route.name === 'admin-empresa-inventario-activo-detalle')

/** Empresa cliente para mantenimiento: param admin o `tenant_company_id` del lote (técnico en custodia). */
const maintenanceCompanyId = computed(() => {
  if (isCompanyScope.value) return String(route.params.companyId || '')
  const tid = lot.value?.tenant_company_id
  if (tid == null || tid === '') return ''
  return String(tid)
})

const maintenanceRegisterTo = computed(() => {
  const cid = maintenanceCompanyId.value
  const lid = lot.value?.id
  if (!cid || !lid) return null
  const query = { company_id: cid, inventory_lot_id: String(lid) }
  if (isAdmin.value) {
    return { name: 'admin-servicios-nuevo-mantenimiento', query }
  }
  return { name: 'emp-registro-mantenimiento', query }
})

const showMaintenanceRegisterCta = computed(() => {
  if (!lot.value || !maintenanceRegisterTo.value) return false
  if (String(lot.value.lifecycle_status || 'activo') !== 'activo') return false
  return Boolean(maintenanceCompanyId.value)
})

const contextParams = computed(() => {
  if (isCompanyScope.value) {
    const base = { tenant_company_id: Number(route.params.companyId || 0) || undefined }
    if (!isAdmin.value) base.for_maintenance = '1'
    return base
  }
  const qTenant = route.query.tenant_company_id
  if (qTenant != null && String(qTenant).trim() !== '') {
    const base = { tenant_company_id: Number(qTenant) || undefined }
    if (!isAdmin.value) base.for_maintenance = '1'
    return base
  }
  return { tenant_scope: 'internal' }
})

const isAdmin = computed(() => isAdminPanelRole(auth.user?.rol))

const showAttachmentUpload = computed(() => {
  const l = lot.value
  if (!l) return false
  if (isAdmin.value) return true
  return String(l.lifecycle_status || 'activo') === 'activo'
})

/** Títulos de auditoría alineados con InventoryView (hoja de vida: primero el registro en inventario). */
function humanizeAuditAction(action) {
  const key = String(action || '').toLowerCase()
  const map = {
    create: 'Registro del activo',
    import_csv_row: 'Registro del activo (importación CSV)',
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

const timeline = computed(() => {
  const audits = (historyRows.value || []).map((ev) => ({
    id: `audit-${ev.id}`,
    source: 'audit',
    auditEventId: ev.id,
    atRaw: ev.occurred_at || '',
    when: (ev.occurred_at || '').replace('T', ' ').slice(0, 19) || '—',
    action: humanizeAuditAction(ev.action),
    actor: ev.actor?.nombre || 'Sistema',
    note: summarizeAudit(ev, isCompanyScope.value),
  }))
  const movements = (movementRows.value || []).map((mv) => ({
    id: `mov-${mv.id}`,
    source: 'movement',
    auditEventId: null,
    atRaw: mv.created_at || '',
    when: (mv.created_at || '').replace('T', ' ').slice(0, 19) || '—',
    action: humanizeMovementType(mv.type),
    actor: mv.user?.nombre || 'Sistema',
    note: summarizeMovement(mv, isCompanyScope.value),
  }))
  return [...audits, ...movements].sort((a, b) => {
    const t = String(a.atRaw).localeCompare(String(b.atRaw))
    if (t !== 0) return t
    if (a.source !== b.source) return a.source === 'audit' ? -1 : 1
    return String(a.id).localeCompare(String(b.id))
  })
})

function summarizeAudit(ev, omitLifecycleStatus = false) {
  const b = ev?.before || {}
  const a = ev?.after || {}
  const out = []
  if (
    !omitLifecycleStatus &&
    b.lifecycle_status &&
    a.lifecycle_status &&
    b.lifecycle_status !== a.lifecycle_status
  ) {
    out.push(`Estado: ${b.lifecycle_status} -> ${a.lifecycle_status}`)
  }
  if (a.reason) out.push(`Motivo: ${a.reason}`)
  if (a.resolution_note) out.push(`Resolución: ${a.resolution_note}`)
  if (a.service_id) out.push(`Mantenimiento vinculado: #${a.service_id}`)
  return out.join(' | ')
}

function humanizeMovementType(type) {
  const map = {
    alta: 'Alta de inventario',
    ajuste: 'Ajuste de existencias',
    venta: 'Salida por venta',
    venta_reversa: 'Reversa de venta',
    alquiler_salida: 'Salida por alquiler',
    alquiler_devolucion: 'Devolución de alquiler',
    alquiler_reversa: 'Reversa de alquiler',
    reparacion: 'Ingreso a reparación',
    reactivacion: 'Reactivación',
    baja: 'Baja técnica',
  }
  return map[String(type || '')] || `Movimiento: ${type || 'desconocido'}`
}

function summarizeMovement(mv, omitStockDelta = false) {
  const delta = Number(mv?.quantity_delta || 0)
  const pieces = []
  if (!omitStockDelta && delta !== 0) pieces.push(`Delta stock: ${delta > 0 ? '+' : ''}${delta}`)
  if (mv?.note) pieces.push(`Nota: ${mv.note}`)
  return pieces.join(' | ')
}

async function loadAttachmentsList(lotId) {
  attachmentsLoading.value = true
  try {
    const res = await fetchInventoryLotAttachments(lotId, contextParams.value)
    attachments.value = res?.data || []
  } catch {
    attachments.value = []
  } finally {
    attachmentsLoading.value = false
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    const lotId = Number(route.params.lotId || 0)
    const lotRes = await fetchInventoryLot(lotId, contextParams.value)
    const [historyRes, movementRes] = await Promise.all([
      fetchInventoryAuditEvents({
        entity_type: 'inventory_lot',
        entity_id: lotId,
        per_page: 100,
        ...contextParams.value,
      }).catch(() => ({ data: [] })),
      fetchInventoryMovements({
        inventory_lot_id: lotId,
        per_page: 100,
        ...contextParams.value,
      }).catch(() => ({ data: [] })),
    ])
    lot.value = lotRes?.data || null
    historyRows.value = historyRes?.data || []
    movementRows.value = movementRes?.data || []
    if (lot.value) await loadAttachmentsList(lotId)
    else attachments.value = []
  } catch (e) {
    error.value = e.message || 'No se pudo cargar el detalle del activo.'
    lot.value = null
    historyRows.value = []
    movementRows.value = []
    attachments.value = []
  } finally {
    loading.value = false
  }
}

async function onPickFile(ev) {
  const list = ev.target?.files ? Array.from(ev.target.files) : []
  if (!list.length || !lot.value?.id) return
  uploadBusy.value = true
  uploadMsg.value = ''
  const meta =
    linkAuditEventId.value != null && String(linkAuditEventId.value).trim() !== ''
      ? { inventory_audit_event_id: linkAuditEventId.value }
      : {}
  try {
    for (const f of list) {
      await uploadInventoryLotAttachment(lot.value.id, f, contextParams.value, meta)
    }
    await load()
    linkAuditEventId.value = null
    if (fileInput.value) fileInput.value.value = ''
  } catch (e) {
    uploadMsg.value = e.message || 'No se pudo subir el archivo.'
  } finally {
    uploadBusy.value = false
  }
}

async function removeAttachment(row) {
  if (!lot.value?.id || !row?.id) return
  if (!confirm(`¿Eliminar «${row.original_filename || 'adjunto'}»?`)) return
  uploadMsg.value = ''
  try {
    await deleteInventoryLotAttachment(lot.value.id, row.id, contextParams.value)
    await load()
  } catch (e) {
    uploadMsg.value = e.message || 'No se pudo eliminar.'
  }
}

async function openLifecyclePdf() {
  if (!lot.value?.id) return
  pdfBusy.value = true
  uploadMsg.value = ''
  try {
    const blob = await downloadInventoryLotLifecycleSheetPdf(lot.value.id, contextParams.value)
    openPdfBlobInNewTab(blob)
  } catch (e) {
    uploadMsg.value = e.message || 'No se pudo generar el PDF de la hoja de vida.'
  } finally {
    pdfBusy.value = false
  }
}

function goBack() {
  if (window.history.length > 1) {
    router.back()
    return
  }
  if (isCompanyScope.value) {
    router.push({ name: 'admin-empresa-inventario', params: { companyId: route.params.companyId } })
    return
  }
  router.push({ name: route.path.startsWith('/admin') ? 'admin-inventario' : 'empleado-inventario' })
}

onMounted(load)
</script>

<template>
  <section class="space-y-4">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-xl font-semibold text-white">Detalle del activo</h1>
        <p class="text-sm text-slate-400">Hoja de vida y trazabilidad del equipo.</p>
      </div>
      <div class="flex flex-wrap gap-2">
        <button
          type="button"
          :disabled="pdfBusy || loading"
          class="rounded-lg border border-sky-600/60 bg-sky-900/30 px-3 py-2 text-sm text-sky-200 hover:bg-sky-900/50 disabled:opacity-50"
          @click="openLifecyclePdf"
        >
          {{ pdfBusy ? 'Generando PDF…' : 'PDF hoja de vida' }}
        </button>
        <RouterLink
          v-if="showMaintenanceRegisterCta"
          :to="maintenanceRegisterTo"
          class="rounded-lg border border-teal-500/50 bg-teal-900/25 px-3 py-2 text-sm font-medium text-teal-100 hover:bg-teal-900/40"
        >
          Registrar mantenimiento
        </RouterLink>
        <button type="button" class="rounded-lg border border-slate-600 px-3 py-2 text-sm text-slate-200 hover:bg-slate-800" @click="goBack">
          Volver
        </button>
      </div>
    </div>

    <p v-if="error" class="rounded-lg border border-rose-500/40 bg-rose-900/20 px-3 py-2 text-sm text-rose-300">{{ error }}</p>
    <p v-else-if="loading" class="text-sm text-slate-400">Cargando activo…</p>

    <div v-else-if="lot" class="grid grid-cols-1 gap-4 lg:grid-cols-3">
      <section class="rounded-xl border border-slate-700/80 bg-[#111723] p-4 lg:col-span-1">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-300">Ficha técnica</h2>
        <dl class="space-y-2 text-sm">
          <div class="rounded-lg border border-slate-800/80 bg-slate-900/30 px-3 py-2"><dt class="text-slate-500">Nombre</dt><dd class="text-slate-100">{{ lot.name || '—' }}</dd></div>
          <div class="rounded-lg border border-slate-800/80 bg-slate-900/30 px-3 py-2"><dt class="text-slate-500">Código interno</dt><dd class="text-slate-100">{{ lot.internal_code || `LOT-${lot.id}` }}</dd></div>
          <div class="rounded-lg border border-slate-800/80 bg-slate-900/30 px-3 py-2"><dt class="text-slate-500">Serial</dt><dd class="text-slate-100">{{ lot.serial_number || '—' }}</dd></div>
          <div class="rounded-lg border border-slate-800/80 bg-slate-900/30 px-3 py-2"><dt class="text-slate-500">MAC</dt><dd class="text-slate-100">{{ lot.mac_address || '—' }}</dd></div>
          <div v-if="!isCompanyScope" class="rounded-lg border border-slate-800/80 bg-slate-900/30 px-3 py-2"><dt class="text-slate-500">Estado</dt><dd class="text-slate-100">{{ lot.lifecycle_status || 'activo' }}</dd></div>
          <div v-if="!isCompanyScope" class="rounded-lg border border-slate-800/80 bg-slate-900/30 px-3 py-2"><dt class="text-slate-500">Stock</dt><dd class="text-slate-100">{{ lot.quantity_available }} / {{ lot.units_on_rent || 0 }} / {{ lot.quantity_total || lot.quantity_available }}</dd></div>
          <div v-if="!isCompanyScope" class="rounded-lg border border-slate-800/80 bg-slate-900/30 px-3 py-2"><dt class="text-slate-500">Precio unitario</dt><dd class="text-slate-100">{{ lot.unit_price || '0.00' }}</dd></div>
        </dl>
      </section>

      <section class="rounded-xl border border-slate-700/80 bg-[#111723] p-4 lg:col-span-2">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-300">Línea de tiempo</h2>
        <ol class="max-h-[60vh] space-y-3 overflow-y-auto pr-1">
          <li v-for="ev in timeline" :key="ev.id" class="rounded-xl border border-slate-700/70 bg-slate-900/35 px-4 py-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
              <p class="font-semibold text-slate-100">{{ ev.action }}</p>
              <p class="text-xs text-slate-400">{{ ev.when }}</p>
            </div>
            <p class="mt-1 text-xs text-slate-400">Responsable: {{ ev.actor }}</p>
            <p class="mt-2 text-sm text-slate-300">{{ ev.note || 'Evento registrado.' }}</p>
            <div
              v-if="showAttachmentUpload && ev.source === 'audit' && ev.auditEventId != null"
              class="mt-3 flex flex-wrap items-center gap-2"
            >
              <button
                type="button"
                class="min-h-[44px] rounded-lg border border-amber-600/50 bg-amber-900/20 px-3 py-2 text-xs font-medium text-amber-100 hover:bg-amber-900/35"
                :class="linkAuditEventId === ev.auditEventId ? 'ring-2 ring-amber-400/60' : ''"
                @click="linkAuditEventId = linkAuditEventId === ev.auditEventId ? null : ev.auditEventId"
              >
                {{ linkAuditEventId === ev.auditEventId ? 'Dejar de vincular' : 'Adjuntos a este evento' }}
              </button>
            </div>
          </li>
          <li v-if="!timeline.length" class="rounded-xl border border-slate-700/60 bg-slate-900/30 px-4 py-3 text-sm text-slate-500">
            Sin eventos de trazabilidad para este activo.
          </li>
        </ol>
      </section>

      <section class="rounded-xl border border-slate-700/80 bg-[#111723] p-4 lg:col-span-3">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-300">Adjuntos</h2>
        <p class="mb-3 text-xs text-slate-500">
          Imágenes o PDF (máx. 10&nbsp;MB; puede elegir varios). Use «Adjuntos a este evento» en la línea de tiempo para
          vincular archivos a un evento de auditoría. Sin importes en custodia.
        </p>
        <p
          v-if="linkAuditEventId != null"
          class="mb-2 rounded-lg border border-amber-600/40 bg-amber-900/20 px-3 py-2 text-xs text-amber-100"
        >
          Próxima subida quedará vinculada al evento #{{ linkAuditEventId }}. Pulse «Subir archivo» o cancele desde la
          línea de tiempo.
        </p>
        <p v-if="uploadMsg" class="mb-2 text-sm text-rose-400">{{ uploadMsg }}</p>
        <div v-if="showAttachmentUpload" class="mb-4 flex flex-wrap items-center gap-3">
          <input
            ref="fileInput"
            type="file"
            multiple
            accept="image/jpeg,image/png,image/gif,image/webp,application/pdf,.jpg,.jpeg,.png,.gif,.webp,.pdf"
            class="hidden"
            @change="onPickFile"
          />
          <button
            type="button"
            :disabled="uploadBusy"
            class="min-h-[44px] rounded-lg border border-slate-600 bg-slate-800/60 px-4 py-2.5 text-sm text-slate-100 hover:bg-slate-700 disabled:opacity-50"
            @click="fileInput?.click()"
          >
            {{ uploadBusy ? 'Subiendo…' : 'Subir archivo' }}
          </button>
        </div>
        <p v-if="attachmentsLoading" class="text-sm text-slate-500">Cargando adjuntos…</p>
        <ul v-else class="divide-y divide-slate-700/60 rounded-xl border border-slate-700/60">
          <li v-for="a in attachments" :key="a.id" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
            <div>
              <a :href="a.url" target="_blank" rel="noopener noreferrer" class="font-medium text-sky-300 hover:text-sky-200 hover:underline">
                {{ a.original_filename || 'Archivo' }}
              </a>
              <p class="text-xs text-slate-500">
                {{ a.uploaded_by?.nombre || '—' }} · {{ (a.created_at || '').replace('T', ' ').slice(0, 19) }}
              </p>
              <p v-if="a.linked_audit_event" class="mt-1 text-xs text-amber-200/90">
                Vinculado a evento #{{ a.linked_audit_event.id }} ({{ a.linked_audit_event.action }})
              </p>
            </div>
            <button type="button" class="text-xs text-rose-400 hover:text-rose-300" @click="removeAttachment(a)">Eliminar</button>
          </li>
          <li v-if="!attachments.length" class="px-3 py-4 text-center text-slate-500">Sin adjuntos.</li>
        </ul>
      </section>
    </div>
  </section>
</template>
