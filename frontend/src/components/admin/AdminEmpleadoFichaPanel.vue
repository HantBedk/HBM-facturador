<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { clearEmpleadoDatosPago, fetchAdminEmpleadoPerfil } from '@/services/usersApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'

const uiDialog = useUiDialogStore()

const props = defineProps({
  open: { type: Boolean, default: false },
  /** Fila del listado admin (id, nombre, correo, rol, estado, correo_solicitado, …) */
  row: { type: Object, default: null },
})

const emit = defineEmits(['close', 'updated'])

const loading = ref(false)
const error = ref('')
const payload = ref(null)
const clearing = ref(false)
const toast = ref('')

const userId = computed(() => (props.row?.id != null ? String(props.row.id) : ''))
const isTecnico = computed(() => props.row?.rol === 'empleado')

const profile = computed(() => payload.value?.profile)
const userMeta = computed(() => payload.value?.user)
const banks = computed(() => payload.value?.banks || [])
const documentTypes = computed(() => payload.value?.document_types || [])
const accountTypes = computed(() => payload.value?.account_types || [])

const bankLabel = computed(() => {
  const c = profile.value?.banco_codigo
  if (!c) return '—'
  const b = banks.value.find((x) => x.codigo === c)
  return b?.nombre ? `${b.nombre} (${c})` : c
})

const docLabel = computed(() => {
  const c = profile.value?.tipo_documento
  if (!c) return '—'
  const d = documentTypes.value.find((x) => x.codigo === c)
  return d?.nombre || c
})

const cuentaTipoLabel = computed(() => {
  const c = profile.value?.cuenta_tipo
  if (!c) return '—'
  const a = accountTypes.value.find((x) => x.codigo === c)
  return a?.nombre || c
})

const hasDatosPago = computed(() => {
  const p = profile.value
  if (!p) return false
  return !!(p.banco_codigo || p.cuenta_tipo || p.cuenta_numero)
})

const ROL_LABEL = {
  empleado: 'Empleado',
  admin: 'Admin',
  super_admin: 'Super admin',
}

async function loadPerfil() {
  if (!userId.value || !isTecnico.value) return
  error.value = ''
  loading.value = true
  try {
    payload.value = await fetchAdminEmpleadoPerfil(userId.value)
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar la ficha.'
    payload.value = null
  } finally {
    loading.value = false
  }
}

watch(
  () => ({ open: props.open, id: props.row?.id, rol: props.row?.rol }),
  async ({ open, id, rol }) => {
    if (!open) {
      payload.value = null
      error.value = ''
      toast.value = ''
      return
    }
    if (id == null) return
    if (rol === 'empleado') await loadPerfil()
    else {
      payload.value = null
      error.value = ''
    }
  }
)

async function onClearDatosPago() {
  const ok = await uiDialog.confirm({
    title: 'Borrar datos de pago',
    message:
      '¿Borrar entidad, tipo de cuenta y número/llave Bre-B de este técnico? Se le pedirá que actualice sus datos de pago en «Mi perfil» y recibirá un aviso.',
    danger: true,
    confirmLabel: 'Borrar',
  })
  if (!ok) return
  clearing.value = true
  toast.value = ''
  try {
    error.value = ''
    const r = await clearEmpleadoDatosPago(userId.value)
    toast.value = r.message || 'Listo.'
    await loadPerfil()
    emit('updated')
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo completar.'
  } finally {
    clearing.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open && row" class="drawer-root" aria-hidden="false">
      <div class="drawer-backdrop" @click.self="emit('close')" />
      <aside
        class="drawer-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="drawer-emp-ficha-title"
      >
        <header class="drawer-header">
          <div class="drawer-header-text">
            <p class="drawer-kicker">Ficha técnica</p>
            <h2 id="drawer-emp-ficha-title" class="drawer-title drawer-title--name">
              {{ row.nombre || '—' }}
            </h2>
            <p v-if="isTecnico" class="muted drawer-sub">Técnico · cuenta y datos de contacto / pago</p>
            <p v-else class="muted drawer-sub">{{ ROL_LABEL[row.rol] || row.rol }} · solo datos de cuenta</p>
          </div>
          <button type="button" class="drawer-close" aria-label="Cerrar panel" @click="emit('close')">
            <span aria-hidden="true">×</span>
          </button>
        </header>

        <div v-if="isTecnico" class="drawer-toolbar">
          <RouterLink
            class="btn secondary btn-compact"
            :to="{ name: 'admin-emp-rendimiento-user', params: { userId } }"
            @click="emit('close')"
          >
            Historial / rendimiento
          </RouterLink>
          <RouterLink class="btn secondary btn-compact" :to="{ name: 'admin-empleado-perfil', params: { userId } }" @click="emit('close')">
            Abrir página completa
          </RouterLink>
        </div>

        <div class="drawer-body">
          <p v-if="toast" class="banner ok">{{ toast }}</p>
          <p v-if="error" class="banner err">{{ error }}</p>

          <template v-if="!isTecnico">
            <div class="card">
              <h2 class="h2">Cuenta</h2>
              <dl class="dl">
                <dt>Nombre</dt>
                <dd>{{ row.nombre }}</dd>
                <dt>Correo</dt>
                <dd>{{ row.correo || '—' }}</dd>
                <dt>Rol</dt>
                <dd>{{ ROL_LABEL[row.rol] || row.rol }}</dd>
                <dt>Estado</dt>
                <dd>{{ row.estado === 'activo' ? 'Activo' : 'Inactivo' }}</dd>
              </dl>
            </div>
            <p class="muted small">
              La ficha extendida (contacto, documento, datos de pago) solo aplica a cuentas con rol técnico.
            </p>
          </template>

          <template v-else>
            <p v-if="loading" class="muted pad">Cargando ficha…</p>

            <template v-else-if="userMeta">
              <div class="card">
                <h2 class="h2">Cuenta</h2>
                <dl class="dl">
                  <dt>Nombre</dt>
                  <dd>{{ userMeta.nombre }}</dd>
                  <dt>Correo (acceso)</dt>
                  <dd>{{ userMeta.correo }}</dd>
                  <dt>Estado</dt>
                  <dd>{{ userMeta.estado === 'activo' ? 'Activo' : 'Inactivo' }}</dd>
                  <dt v-if="userMeta.correo_solicitado">Correo solicitado (pendiente)</dt>
                  <dd v-if="userMeta.correo_solicitado">{{ userMeta.correo_solicitado }}</dd>
                </dl>
              </div>

              <div class="card">
                <h2 class="h2">Contacto e identificación</h2>
                <dl class="dl">
                  <dt>Nombre (perfil)</dt>
                  <dd>{{ profile?.nombre || '—' }}</dd>
                  <dt>Celular</dt>
                  <dd>{{ profile?.telefono || '—' }}</dd>
                  <dt>Ciudad</dt>
                  <dd>{{ profile?.ciudad || '—' }}</dd>
                  <dt>Departamento</dt>
                  <dd>{{ profile?.departamento || '—' }}</dd>
                  <dt>Documento</dt>
                  <dd>{{ docLabel }} · {{ profile?.numero_documento || '—' }}</dd>
                  <dt>Perfil completado</dt>
                  <dd>
                    {{ profile?.perfil_completado_at ? new Date(profile.perfil_completado_at).toLocaleString('es-CO') : '—' }}
                  </dd>
                </dl>
              </div>

              <div class="card">
                <h2 class="h2">Datos para pago</h2>
                <dl class="dl">
                  <dt>Entidad</dt>
                  <dd>{{ bankLabel }}</dd>
                  <dt>Tipo de cuenta / medio</dt>
                  <dd>{{ cuentaTipoLabel }}</dd>
                  <dt>Número / llave Bre-B</dt>
                  <dd class="mono">{{ profile?.cuenta_numero || '—' }}</dd>
                </dl>

                <div v-if="hasDatosPago" class="danger-zone">
                  <p class="danger-title">Pago rechazado o datos incorrectos</p>
                  <p class="danger-text">
                    Borra solo la información de pago. El técnico deberá volver a cargarla en su perfil y recibirá un aviso.
                  </p>
                  <button type="button" class="btn danger" :disabled="clearing" @click="onClearDatosPago">
                    {{ clearing ? 'Procesando…' : 'Borrar datos de pago y notificar' }}
                  </button>
                </div>
                <p v-else class="muted small">No hay datos de pago registrados.</p>
              </div>
            </template>
          </template>
        </div>
      </aside>
    </div>
  </Teleport>
</template>

<style scoped>
.drawer-root {
  position: fixed;
  inset: 0;
  z-index: 90;
  pointer-events: none;
}

.drawer-backdrop {
  position: absolute;
  inset: 0;
  background: rgba(2, 6, 23, 0.65);
  backdrop-filter: blur(4px);
  pointer-events: auto;
}

.drawer-panel {
  position: absolute;
  top: 0;
  right: 0;
  height: 100%;
  width: min(560px, 100vw);
  max-width: 100%;
  background: #0f172a;
  border-left: 1px solid rgba(148, 163, 184, 0.25);
  box-shadow: -12px 0 40px rgba(0, 0, 0, 0.45);
  display: flex;
  flex-direction: column;
  pointer-events: auto;
  animation: drawer-in 0.22s ease-out;
}

@keyframes drawer-in {
  from {
    transform: translateX(100%);
    opacity: 0.9;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

.drawer-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 1rem 1rem 0.75rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.2);
  flex-shrink: 0;
}

.drawer-header-text {
  min-width: 0;
}

.drawer-kicker {
  margin: 0 0 0.2rem;
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #94a3b8;
}

.drawer-title {
  margin: 0;
  font-size: 1.15rem;
  color: #f8fafc;
  word-break: break-word;
}

.drawer-title--name {
  font-family: inherit;
}

.drawer-sub {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
}

.drawer-close {
  width: 2.25rem;
  height: 2.25rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(148, 163, 184, 0.35);
  border-radius: 10px;
  background: rgba(2, 6, 23, 0.4);
  color: #e2e8f0;
  font-size: 1.5rem;
  line-height: 1;
  cursor: pointer;
  flex-shrink: 0;
}

.drawer-close:hover {
  border-color: #38bdf8;
  color: #fff;
}

.drawer-toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  padding: 0.65rem 1rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.15);
  flex-shrink: 0;
}

.drawer-body {
  flex: 1;
  overflow-y: auto;
  padding: 0.85rem 1rem 1.5rem;
  -webkit-overflow-scrolling: touch;
}

.h2 {
  margin: 0 0 0.75rem;
  font-size: 1rem;
  color: #e2e8f0;
}

.card {
  padding: 1rem 1.1rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 0.85rem;
}

.dl {
  display: grid;
  grid-template-columns: minmax(120px, 34%) 1fr;
  gap: 0.5rem 0.85rem;
  margin: 0;
  font-size: 0.88rem;
}

.dl dt {
  color: #94a3b8;
  margin: 0;
}

.dl dd {
  margin: 0;
  color: #f1f5f9;
}

.mono {
  font-family: ui-monospace, monospace;
  word-break: break-all;
}

.banner.err {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 0.85rem;
}

.banner.ok {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(52, 211, 153, 0.12);
  border: 1px solid rgba(52, 211, 153, 0.35);
  color: #d1fae5;
  margin-bottom: 0.85rem;
}

.muted {
  color: #94a3b8;
}

.small {
  font-size: 0.85rem;
}

.pad {
  padding: 0.5rem 0;
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
  font-size: 0.88rem;
}

.btn-compact {
  padding: 0.4rem 0.7rem;
  font-size: 0.82rem;
}

.btn.secondary {
  background: rgba(2, 6, 23, 0.35);
}

.btn.danger {
  background: rgba(185, 28, 28, 0.35);
  border-color: rgba(248, 113, 113, 0.5);
  color: #fecaca;
}

.btn.danger:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.danger-zone {
  margin-top: 1.25rem;
  padding-top: 1rem;
  border-top: 1px solid rgba(248, 113, 113, 0.2);
}

.danger-title {
  margin: 0 0 0.35rem;
  font-size: 0.95rem;
  font-weight: 600;
  color: #fecaca;
}

.danger-text {
  margin: 0 0 0.75rem;
  font-size: 0.85rem;
  color: #94a3b8;
}
</style>
