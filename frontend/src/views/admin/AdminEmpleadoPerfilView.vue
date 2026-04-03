<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { clearEmpleadoDatosPago, fetchAdminEmpleadoPerfil } from '@/services/usersApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'

const uiDialog = useUiDialogStore()

const props = defineProps({
  userId: { type: String, required: true },
})

const loading = ref(true)
const error = ref('')
const payload = ref(null)
const clearing = ref(false)
const toast = ref('')

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

async function load() {
  error.value = ''
  loading.value = true
  try {
    payload.value = await fetchAdminEmpleadoPerfil(props.userId)
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar el perfil.'
    payload.value = null
  } finally {
    loading.value = false
  }
}

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
    const r = await clearEmpleadoDatosPago(props.userId)
    toast.value = r.message || 'Listo.'
    await load()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo completar.'
  } finally {
    clearing.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="page">
    <header class="head">
      <div>
        <p class="crumb">
          <RouterLink to="/admin/configuracion/cuentas">Configuración · empleados</RouterLink>
        </p>
        <h1>Perfil del técnico</h1>
        <p class="lede">
          Datos de contacto y pago registrados por el técnico. Si un pago falló por datos incorrectos, puedes borrar la
          información bancaria y notificarle que la actualice.
        </p>
      </div>
      <RouterLink class="btn secondary" :to="`/admin/empleados/rendimiento/${userId}`">Historial / rendimiento</RouterLink>
    </header>

    <p v-if="error" class="banner err">{{ error }}</p>
    <p v-if="toast" class="banner ok">{{ toast }}</p>

    <div v-if="loading" class="muted pad">Cargando…</div>

    <template v-else-if="payload && userMeta">
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
          <dd>{{ profile?.perfil_completado_at ? new Date(profile.perfil_completado_at).toLocaleString('es-CO') : '—' }}</dd>
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
            Borra solo la información de pago (banco, tipo y número o llave). El técnico deberá volver a cargarla en su
            perfil y recibirá una notificación.
          </p>
          <button type="button" class="btn danger" :disabled="clearing" @click="onClearDatosPago">
            {{ clearing ? 'Procesando…' : 'Borrar datos de pago y notificar al técnico' }}
          </button>
        </div>
        <p v-else class="muted small">No hay datos de pago registrados.</p>
      </div>
    </template>
  </section>
</template>

<style scoped>
.page {
  max-width: 720px;
  margin: 0 auto;
}

.head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 1rem;
  align-items: flex-start;
  margin-bottom: 1.25rem;
}

.crumb {
  margin: 0 0 0.35rem;
  font-size: 0.8rem;
}

.crumb a {
  color: #7dd3fc;
  text-decoration: none;
}

.crumb a:hover {
  text-decoration: underline;
}

h1 {
  margin: 0 0 0.35rem;
  font-size: 1.35rem;
  color: #f8fafc;
}

.h2 {
  margin: 0 0 0.75rem;
  font-size: 1rem;
  color: #e2e8f0;
}

.lede {
  margin: 0;
  max-width: 40rem;
  font-size: 0.88rem;
  color: #94a3b8;
}

.card {
  padding: 1rem 1.15rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 1rem;
}

.dl {
  display: grid;
  grid-template-columns: minmax(140px, 32%) 1fr;
  gap: 0.5rem 1rem;
  margin: 0;
  font-size: 0.9rem;
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
  margin-bottom: 1rem;
}

.banner.ok {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(52, 211, 153, 0.12);
  border: 1px solid rgba(52, 211, 153, 0.35);
  color: #d1fae5;
  margin-bottom: 1rem;
}

.muted {
  color: #94a3b8;
}

.small {
  font-size: 0.85rem;
}

.pad {
  padding: 1rem;
}

.btn {
  display: inline-flex;
  padding: 0.55rem 1rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  text-decoration: none;
  font-size: 0.9rem;
}

.btn.secondary {
  border-color: rgba(148, 163, 184, 0.35);
  color: #e2e8f0;
  background: transparent;
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
  max-width: 40rem;
}
</style>
