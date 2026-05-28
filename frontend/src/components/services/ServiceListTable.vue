<script setup>
import { RouterLink } from 'vue-router'
import {
  adminInvoiceDetailPath,
  clip,
  equipmentCellLabel,
  estadoLabel,
  formatServiceListDate,
  money,
  rowInventoryDetailTo as inventoryLotRouteFromServiceRow,
  serviceEditPath,
  canAdminQuickMaintenanceInvoiceDraft as quickMaintInvoiceDraftAllowed,
} from '@/views/services/servicesListHelpers.js'

const props = defineProps({
  rows: { type: Array, default: () => [] },
  loading: Boolean,
  meta: { type: Object, default: null },
  isAdmin: Boolean,
  showEquipmentColumn: Boolean,
  showServiceActions: Boolean,
  tableColspan: Number,
  sortKey: { type: String, default: '' },
  sortDir: { type: String, default: 'desc' },
  archivingId: { type: [Number, String], default: null },
  invoiceDraftBusyId: { type: [Number, String], default: null },
})

const emit = defineEmits(['toggle-sort', 'go-page', 'open-detail', 'open-delete', 'quick-draft'])

function thAriaSort(key) {
  return props.sortKey === key ? (props.sortDir === 'asc' ? 'ascending' : 'descending') : undefined
}

function sortIndicator(key) {
  if (props.sortKey !== key) return ''
  return props.sortDir === 'asc' ? '▲' : '▼'
}

function rowInventoryDetailTo(s) {
  return inventoryLotRouteFromServiceRow(s, props.isAdmin)
}

function canQuickDraft(s) {
  return quickMaintInvoiceDraftAllowed(s, props.isAdmin)
}

function editPath(id) {
  return serviceEditPath(id, props.isAdmin)
}

const formatDate = formatServiceListDate
</script>

<template>
  <div class="card table-wrap">
    <div v-if="loading" class="muted pad">Cargando…</div>
    <template v-else>
      <p v-if="meta && meta.total" class="meta-line muted">{{ meta.from ?? 0 }}–{{ meta.to ?? 0 }} de {{ meta.total }} servicio(s)</p>
      <table class="table">
        <thead>
          <tr>
            <th scope="col" :aria-sort="thAriaSort('code')">
              <button type="button" class="th-sort" @click="emit('toggle-sort', 'code')">
                Código<span class="sort-ind" aria-hidden="true">{{ sortIndicator('code') }}</span>
              </button>
            </th>
            <th v-if="isAdmin" scope="col" :aria-sort="thAriaSort('service_date')">
              <button type="button" class="th-sort" @click="emit('toggle-sort', 'service_date')">
                Fecha<span class="sort-ind" aria-hidden="true">{{ sortIndicator('service_date') }}</span>
              </button>
            </th>
            <th scope="col" :aria-sort="thAriaSort('company_nombre')">
              <button type="button" class="th-sort" @click="emit('toggle-sort', 'company_nombre')">
                Empresa<span class="sort-ind" aria-hidden="true">{{ sortIndicator('company_nombre') }}</span>
              </button>
            </th>
            <th scope="col" :aria-sort="thAriaSort('client_name')">
              <button type="button" class="th-sort" @click="emit('toggle-sort', 'client_name')">
                Cliente<span class="sort-ind" aria-hidden="true">{{ sortIndicator('client_name') }}</span>
              </button>
            </th>
            <th scope="col" :aria-sort="thAriaSort('description')">
              <button type="button" class="th-sort" @click="emit('toggle-sort', 'description')">
                Descripción<span class="sort-ind" aria-hidden="true">{{ sortIndicator('description') }}</span>
              </button>
            </th>
            <th v-if="showEquipmentColumn" scope="col" class="col-equipo">Equipo</th>
            <th v-if="isAdmin" scope="col" :aria-sort="thAriaSort('user_nombre')">
              <button type="button" class="th-sort" @click="emit('toggle-sort', 'user_nombre')">
                Técnico<span class="sort-ind" aria-hidden="true">{{ sortIndicator('user_nombre') }}</span>
              </button>
            </th>
            <th scope="col" class="num" :aria-sort="thAriaSort('amount')">
              <button type="button" class="th-sort th-sort--end" @click="emit('toggle-sort', 'amount')">
                {{ isAdmin ? 'Valor (factura)' : 'Valor (su referencia)' }}<span class="sort-ind" aria-hidden="true">{{ sortIndicator('amount') }}</span>
              </button>
            </th>
            <th scope="col" :aria-sort="thAriaSort('status')">
              <button type="button" class="th-sort" @click="emit('toggle-sort', 'status')">
                Estado<span class="sort-ind" aria-hidden="true">{{ sortIndicator('status') }}</span>
              </button>
            </th>
            <th v-if="showServiceActions" scope="col" class="actions-col">Acciones</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="s in rows"
            :key="s.id"
            class="row-data"
            :class="{ 'row-data--clickable': !isAdmin }"
            :tabindex="isAdmin ? undefined : 0"
            :role="isAdmin ? undefined : 'link'"
            :title="isAdmin ? undefined : 'Ver detalle ' + s.code"
          >
            <td class="code-cell">
              <div class="code-cell-inner">
                <button
                  v-if="isAdmin"
                  type="button"
                  class="code-link"
                  @click.stop="emit('open-detail', s)"
                >
                  {{ s.code }}
                </button>
                <span v-else class="link">{{ s.code }}</span>
                <span
                  v-if="!isAdmin && s.assignment_status === 'awaiting_completion'"
                  class="assign-pill"
                  title="Pendiente de completar asignación"
                >Asignación</span>
                <template v-if="isAdmin && s.invoices?.length">
                  <RouterLink
                    v-for="inv in s.invoices"
                    :key="inv.id"
                    class="link link-invoice code-cell__invoice"
                    :to="adminInvoiceDetailPath(inv.id)"
                    :title="'Ver factura ' + inv.code"
                    @click.stop
                  >
                    {{ inv.code }}
                  </RouterLink>
                </template>
              </div>
            </td>
            <td v-if="isAdmin">{{ formatDate(s.service_date) }}</td>
            <td>{{ s.company?.nombre || '—' }}</td>
            <td>{{ s.client_name || '—' }}</td>
            <td class="desc">{{ clip(s.description) }}</td>
            <td v-if="showEquipmentColumn" class="col-equipo">
              <RouterLink
                v-if="rowInventoryDetailTo(s) && s.inventory_lot"
                class="link-equipo"
                :to="rowInventoryDetailTo(s)"
                title="Ver hoja de vida del activo"
                @click.stop
              >
                {{ equipmentCellLabel(s) }}
              </RouterLink>
              <span v-else class="muted">{{ equipmentCellLabel(s) }}</span>
            </td>
            <td v-if="isAdmin">{{ s.empleado?.nombre || '—' }}</td>
            <td class="num">{{ money(s.amount) }}</td>
            <td><span class="pill" :data-st="s.status">{{ estadoLabel(s.status) }}</span></td>
            <td v-if="showServiceActions" class="actions-col" @click.stop>
              <div class="actions-icons">
                <button
                  v-if="canQuickDraft(s)"
                  type="button"
                  class="icon-act"
                  :disabled="invoiceDraftBusyId != null"
                  title="Crear borrador de factura con este mantenimiento"
                  aria-label="Crear borrador de factura"
                  @click="emit('quick-draft', s)"
                >
                  <svg class="icon-svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                  </svg>
                </button>
                <RouterLink
                  v-if="!(s.assignment_status === 'awaiting_completion' && !isAdmin)"
                  class="icon-act"
                  :to="editPath(s.id)"
                  title="Editar servicio"
                  aria-label="Editar servicio"
                >
                  <svg class="icon-svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                  </svg>
                </RouterLink>
                <RouterLink
                  v-if="!isAdmin && s.assignment_status === 'awaiting_completion'"
                  class="icon-act"
                  :to="`/empleado/servicio/${s.id}/completar-asignacion`"
                  title="Completar asignación"
                  aria-label="Completar asignación"
                >
                  <svg class="icon-svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                </RouterLink>
                <button
                  type="button"
                  class="icon-act icon-act--danger"
                  :disabled="archivingId === s.id || s.status === 'eliminado' || (!isAdmin && s.assignment_status === 'awaiting_completion')"
                  title="Eliminar servicio (marcar como eliminado)"
                  aria-label="Eliminar servicio"
                  @click="emit('open-delete', s)"
                >
                  <svg class="icon-svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </div>
            </td>
          </tr>
          <tr v-if="!rows.length">
            <td :colspan="tableColspan" class="muted center empty-msg">
              No hay registros SAV con los filtros actuales. Prueba limpiar la búsqueda o cambiar filtros.
            </td>
          </tr>
        </tbody>
      </table>

      <div v-if="meta && meta.last_page > 1" class="pager">
        <button type="button" class="btn secondary" :disabled="meta.current_page <= 1" @click="emit('go-page', meta.current_page - 1)">
          Anterior
        </button>
        <span class="muted">Página {{ meta.current_page }} / {{ meta.last_page }}</span>
        <button type="button" class="btn secondary" :disabled="meta.current_page >= meta.last_page" @click="emit('go-page', meta.current_page + 1)">
          Siguiente
        </button>
      </div>
    </template>
  </div>
</template>

<style scoped>
.card {
  padding: 1rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 1rem;
}

.table-wrap {
  width: 100%;
  min-width: 0;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
  padding-inline-end: 2px;
}

.meta-line {
  font-size: 0.8rem;
  margin: 0 0 0.65rem;
}

.table {
  width: 100%;
  border-collapse: collapse;
  border-spacing: 0;
  font-size: 0.9rem;
}

.table th,
.table td {
  padding: 0.55rem 0.45rem;
  text-align: left;
  vertical-align: top;
}

.table thead tr {
  border-bottom: 1px solid rgba(148, 163, 184, 0.42);
}

.table thead th {
  border-bottom: none;
}

.table tbody tr {
  border-bottom: 1px solid rgba(148, 163, 184, 0.3);
}

.table tbody td {
  border-bottom: none;
}

.table th {
  color: #94a3b8;
  font-weight: 600;
}

.table thead th:first-child {
  white-space: nowrap;
}

.th-sort {
  display: inline-flex;
  align-items: center;
  gap: 0.2rem;
  max-width: 100%;
  margin: 0;
  padding: 0;
  border: none;
  background: transparent;
  font: inherit;
  font-weight: 600;
  color: inherit;
  cursor: pointer;
  text-align: inherit;
  border-radius: 6px;
}

.th-sort:hover {
  color: #e2e8f0;
}

.th-sort:focus-visible {
  outline: 2px solid rgba(56, 189, 248, 0.55);
  outline-offset: 2px;
}

.th-sort--end {
  justify-content: flex-end;
  width: 100%;
}

.sort-ind {
  font-size: 0.7rem;
  opacity: 0.85;
  white-space: nowrap;
}

.row-data {
  transition: background 0.12s ease;
}

.row-data--clickable {
  cursor: pointer;
}

.row-data--clickable:hover {
  background: rgba(56, 189, 248, 0.06);
}

.row-data--clickable:focus-visible {
  outline: 2px solid rgba(56, 189, 248, 0.5);
  outline-offset: -2px;
}

.desc {
  max-width: min(36rem, 42vw);
  word-break: break-word;
}

@media (max-width: 900px) {
  .desc {
    max-width: none;
  }
}

.num {
  white-space: nowrap;
  text-align: right;
}

.link {
  color: #7dd3fc;
  font-weight: 600;
  text-decoration: none;
}

.link:hover {
  text-decoration: underline;
}

.code-link {
  margin: 0;
  padding: 0;
  border: none;
  background: none;
  font: inherit;
  font-family: ui-monospace, monospace;
  font-size: inherit;
  font-weight: 600;
  color: #7dd3fc;
  cursor: pointer;
  text-align: left;
  text-decoration: underline;
  text-decoration-color: rgba(125, 211, 252, 0.45);
}

.code-link:hover {
  color: #bae6fd;
}

.code-cell {
  vertical-align: top;
}

.code-cell-inner {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.2rem;
}

.code-cell-inner > :first-child {
  white-space: nowrap;
  flex-shrink: 0;
}

.assign-pill {
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  padding: 0.15rem 0.4rem;
  border-radius: 6px;
  background: rgba(14, 165, 233, 0.2);
  color: #7dd3fc;
  border: 1px solid rgba(56, 189, 248, 0.35);
}

.code-cell__invoice {
  font-size: 0.8rem;
  font-weight: 500;
  white-space: nowrap;
}

.col-equipo {
  max-width: 13.5rem;
  font-size: 0.8125rem;
  vertical-align: top;
}

.link-equipo {
  color: #7dd3fc;
  font-weight: 500;
  text-decoration: none;
}

.link-equipo:hover {
  text-decoration: underline;
}

.actions-col {
  white-space: nowrap;
  vertical-align: middle;
  width: 1%;
}

.table thead th.actions-col {
  vertical-align: bottom;
}

.actions-icons {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.15rem;
}

.icon-act {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.35rem;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: #94a3b8;
  cursor: pointer;
  text-decoration: none;
  line-height: 0;
}

.icon-act:hover:not(:disabled) {
  color: #7dd3fc;
  background: rgba(56, 189, 248, 0.08);
}

.icon-act--danger:hover:not(:disabled) {
  color: #fecaca;
  background: rgba(248, 113, 113, 0.1);
}

.icon-act:disabled {
  opacity: 0.4;
  cursor: not-allowed;
}

.icon-svg {
  width: 1.15rem;
  height: 1.15rem;
}

.pill {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  border-radius: 999px;
  font-size: 0.75rem;
  text-transform: capitalize;
  border: 1px solid rgba(148, 163, 184, 0.35);
}

.pill[data-st='activo'] {
  border-color: rgba(74, 222, 128, 0.45);
  color: #bbf7d0;
}

.pill[data-st='corregido'] {
  border-color: rgba(56, 189, 248, 0.45);
  color: #bae6fd;
}

.pill[data-st='eliminado'] {
  border-color: rgba(248, 113, 113, 0.45);
  color: #fecaca;
}

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin-top: 1rem;
}

.muted {
  color: #94a3b8;
}

.pad {
  padding: 1rem;
}

.center {
  text-align: center;
}

.empty-msg {
  padding: 1.5rem 1rem;
  line-height: 1.5;
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

.btn.secondary {
  border-color: rgba(148, 163, 184, 0.35);
  color: #e2e8f0;
  background: transparent;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
