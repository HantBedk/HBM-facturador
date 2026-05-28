<script setup>

import { computed } from 'vue'

import { useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'

import { isAdminPanelRole } from '@/utils/roles.js'

import ServiceRegisterEmpleadoForm from '@/components/services/ServiceRegisterEmpleadoForm.vue'

import { useServiceRegisterFlow } from '@/composables/useServiceRegisterFlow.js'



const auth = useAuthStore()

const router = useRouter()

const route = useRoute()



const isEmpleadoRegistro = computed(() => String(route.path || '').startsWith('/empleado/'))



const registerKind = computed(() => {

  const n = route.name

  if (n === 'emp-registro-venta' || n === 'admin-servicios-nuevo-venta') return 'venta'

  if (n === 'emp-registro-alquiler' || n === 'admin-servicios-nuevo-alquiler') return 'alquiler'

  if (n === 'emp-registro-mantenimiento' || n === 'admin-servicios-nuevo-mantenimiento') return 'mantenimiento'

  return 'servicio'

})



const pageTitle = computed(() => {

  if (registerKind.value === 'venta') return 'Registrar venta de equipo'

  if (registerKind.value === 'alquiler') return 'Registrar alquiler de equipo'

  if (registerKind.value === 'mantenimiento') return 'Registrar mantenimiento'

  return 'Registrar servicio'

})

const basePrefix = computed(() => (isAdminPanelRole(auth.user?.rol) ? '/admin' : '/empleado'))

const {

  companies,

  catalogItems,

  inventoryLots,

  clientSuggestions,

  loading,

  fieldErrors,

  globalError,

  toast,

  formResetKey,

  photoFiles,

  form,

  onSubmit,

  allowInventoryCommercialOps,

} = useServiceRegisterFlow({

  isEmpleadoRegistro,

  registerKind,

  panelOpenRef: null,

  async onAdminAfterCreate() {
    if (registerKind.value === 'mantenimiento') {
      await router.push(`${basePrefix.value}/mantenimientos`)
      return
    }
    await router.push(`${basePrefix.value}/servicios`)
  },

  async onEmpleadoAfterCreate() {
    await new Promise((r) => setTimeout(r, 450))
    if (registerKind.value === 'mantenimiento') {
      await router.push({ name: 'emp-mantenimientos' })
      return
    }
    await router.push({ name: 'empleado-dashboard' })
  },

})

const effectiveAllowInventoryCommercialOps = computed(() =>
  registerKind.value === 'venta' || registerKind.value === 'alquiler'
    ? allowInventoryCommercialOps.value
    : false
)

</script>



<template>

  <section class="mx-auto w-full max-w-7xl px-4 pb-8 sm:px-6 lg:px-8">

    <header class="mb-6 text-center lg:text-left">

      <h1 class="text-xl font-bold tracking-tight text-white sm:text-2xl">{{ pageTitle }}</h1>

    </header>



    <p v-if="globalError" class="banner" role="alert">{{ globalError }}</p>



    <form

      class="rounded-3xl border border-slate-800/80 bg-[#121820] p-5 shadow-xl shadow-black/30 sm:p-6"

      @submit.prevent="onSubmit"

    >

      <ServiceRegisterEmpleadoForm

        :key="formResetKey"

        v-model="form"

        v-model:photos="photoFiles"

        wide-layout

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



    <Teleport to="body">

      <Transition name="toast">

        <div

          v-if="toast"

          class="fixed bottom-6 right-4 z-[100] flex items-center gap-2 rounded-2xl border border-emerald-500/40 bg-[#0f1a14] px-4 py-3 text-sm font-medium text-emerald-100 shadow-lg shadow-black/40"

          role="status"

        >

          <span class="flex h-8 w-8 items-center justify-center rounded-full bg-emerald-500/25 text-emerald-400" aria-hidden="true">✓</span>

          {{ toast }}

        </div>

      </Transition>

    </Teleport>

  </section>

</template>



<style scoped>
.banner {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}

.toast-enter-active,
.toast-leave-active {
  transition:
    opacity 0.25s ease,
    transform 0.25s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(12px);
}
</style>

