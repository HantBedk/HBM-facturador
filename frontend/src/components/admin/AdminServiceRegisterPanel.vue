<script setup>
import { computed, toRef } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import ServiceRegisterEmpleadoForm from '@/components/services/ServiceRegisterEmpleadoForm.vue'
import { useServiceRegisterFlow } from '@/composables/useServiceRegisterFlow.js'

const props = defineProps({
  open: { type: Boolean, default: false },
  registerKind: { type: String, default: 'servicio' },
  isEmpleado: { type: Boolean, default: false },
  /** z-index sobre otros overlays */
  overlayZIndex: { type: Number, default: 95 },
})

const emit = defineEmits(['close', 'created'])

const router = useRouter()
const panelOpenRef = toRef(props, 'open')
const isEmpleadoRegistro = computed(() => props.isEmpleado)
const registerKind = computed(() => String(props.registerKind || 'servicio'))
const panelTitle = computed(() =>
  registerKind.value === 'venta'
    ? 'Registrar venta'
    : registerKind.value === 'alquiler'
      ? 'Registrar alquiler'
      : registerKind.value === 'mantenimiento'
        ? 'Registrar mantenimiento'
      : 'Registrar servicio'
)
const panelKicker = computed(() =>
  registerKind.value === 'venta'
    ? 'Nueva venta'
    : registerKind.value === 'alquiler'
      ? 'Nuevo alquiler'
      : registerKind.value === 'mantenimiento'
        ? 'Nuevo mantenimiento'
      : 'Nuevo servicio'
)
const fullPageTo = computed(() =>
  props.isEmpleado
    ? registerKind.value === 'venta'
      ? '/empleado/registro-venta'
      : registerKind.value === 'alquiler'
        ? '/empleado/registro-alquiler'
        : registerKind.value === 'mantenimiento'
          ? '/empleado/registro-mantenimiento'
        : '/empleado/registro-servicio'
    : registerKind.value === 'venta'
      ? '/admin/servicios/nuevo-venta'
      : registerKind.value === 'alquiler'
        ? '/admin/servicios/nuevo-alquiler'
        : registerKind.value === 'mantenimiento'
          ? '/admin/servicios/nuevo-mantenimiento'
        : '/admin/servicios/nuevo'
)
const effectiveAllowInventoryCommercialOps = computed(() =>
  registerKind.value === 'venta' || registerKind.value === 'alquiler'
    ? allowInventoryCommercialOps.value
    : false
)

const {
  companies,
  catalogItems,
  inventoryLots,
  clientSuggestions,
  loading,
  fieldErrors,
  globalError,
  formResetKey,
  photoFiles,
  form,
  onSubmit,
  allowInventoryCommercialOps,
} = useServiceRegisterFlow({
  isEmpleadoRegistro,
  registerKind,
  panelOpenRef,
  async onAdminAfterCreate(created) {
    emit('created', created)
    emit('close')
    if (registerKind.value === 'mantenimiento') {
      await router.push('/admin/mantenimientos')
      return
    }
    await router.push('/admin/servicios')
  },
  async onEmpleadoAfterCreate(created) {
    emit('created', created)
    emit('close')
    if (registerKind.value === 'mantenimiento') {
      await router.push({ name: 'emp-mantenimientos' })
      return
    }
    await router.push(`/empleado/servicio/${created.id}`)
  },
})

function onBackdropClick() {
  if (!loading.value) emit('close')
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="drawer-root register-drawer-root"
      :style="{ zIndex: overlayZIndex }"
      aria-hidden="false"
    >
      <div class="drawer-backdrop" @click.self="onBackdropClick" />
      <aside
        class="drawer-panel drawer-panel--register"
        role="dialog"
        aria-modal="true"
        aria-labelledby="register-panel-title"
      >
        <header class="drawer-header">
          <div class="drawer-header-text">
            <p class="drawer-kicker">{{ panelKicker }}</p>
            <h2 id="register-panel-title" class="drawer-title drawer-title--register">{{ panelTitle }}</h2>
          </div>
          <div class="drawer-header-actions">
            <RouterLink
              class="btn secondary btn-compact"
              :to="fullPageTo"
              @click="emit('close')"
            >
              Pantalla completa
            </RouterLink>
            <button type="button" class="drawer-close" aria-label="Cerrar" :disabled="loading" @click="emit('close')">
              <span aria-hidden="true">×</span>
            </button>
          </div>
        </header>

        <div class="drawer-body register-drawer-body">
          <p v-if="globalError" class="banner err">{{ globalError }}</p>

          <form
            class="rounded-2xl border border-slate-800/80 bg-[#121820] p-4 shadow-inner sm:p-5"
            @submit.prevent="onSubmit"
          >
            <ServiceRegisterEmpleadoForm
              :key="formResetKey"
              v-model="form"
              v-model:photos="photoFiles"
              :register-kind="registerKind"
              :companies="companies"
              :catalog-items="catalogItems"
              :inventory-lots="inventoryLots"
              :client-suggestions="clientSuggestions"
              :field-errors="fieldErrors"
              :disabled="loading"
              :allow-inventory-commercial-ops="effectiveAllowInventoryCommercialOps"
            />
          </form>
        </div>
      </aside>
    </div>
  </Teleport>
</template>

<style scoped>
.drawer-root {
  position: fixed;
  inset: 0;
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

.drawer-panel--register {
  width: min(28rem, 100vw);
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

.drawer-title--register {
  font-family: inherit;
}

.drawer-sub {
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
}

.drawer-header-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: flex-end;
  gap: 0.5rem;
  flex-shrink: 0;
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
}

.drawer-close:hover:not(:disabled) {
  border-color: #38bdf8;
  color: #fff;
}

.drawer-close:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.drawer-body {
  flex: 1;
  overflow-y: auto;
  padding: 0.85rem 1rem 1.5rem;
  -webkit-overflow-scrolling: touch;
}

.register-drawer-body {
  padding-bottom: 1.5rem;
}

.muted {
  color: #94a3b8;
}

.banner.err {
  margin-bottom: 0.75rem;
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
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
</style>
