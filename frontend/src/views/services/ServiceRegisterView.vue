<script setup>
import { computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import ServiceRegisterEmpleadoForm from '@/components/services/ServiceRegisterEmpleadoForm.vue'
import { useServiceRegisterFlow } from '@/composables/useServiceRegisterFlow.js'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const isEmpleadoRegistro = computed(() => route.name === 'emp-registro-servicio')

const basePrefix = computed(() => (isAdminPanelRole(auth.user?.rol) ? '/admin' : '/empleado'))
const cancelTo = computed(() =>
  isAdminPanelRole(auth.user?.rol) ? `${basePrefix.value}/servicios` : '/empleado'
)

const {
  companies,
  catalogItems,
  clientSuggestions,
  loading,
  fieldErrors,
  globalError,
  toast,
  lastCreated,
  formResetKey,
  photoFiles,
  form,
  onSubmit,
} = useServiceRegisterFlow({
  isEmpleadoRegistro,
  panelOpenRef: null,
  async onAdminAfterCreate(created) {
    await router.push(`${basePrefix.value}/servicios/${created.id}`)
  },
  async onEmpleadoAfterCreate() {
    await new Promise((r) => setTimeout(r, 450))
    await router.push({ name: 'empleado-dashboard' })
  },
})
</script>

<template>
  <section class="mx-auto max-w-md px-3 pb-8 sm:px-0">
    <header class="mb-6 text-center">
      <p v-if="!isEmpleadoRegistro" class="mb-4">
        <RouterLink
          class="text-sm font-medium text-slate-400 underline decoration-slate-600 underline-offset-2 hover:text-slate-200"
          :to="cancelTo"
        >
          ← Volver al listado
        </RouterLink>
      </p>
      <h1 class="text-xl font-bold tracking-tight text-white sm:text-2xl">Registrar servicio</h1>
      <p class="mt-2 text-[0.8rem] leading-snug text-slate-500">
        La fecha del servicio y el código (SERV-…) los asigna el servidor al guardar.
      </p>
    </header>

    <p v-if="globalError" class="banner" role="alert">{{ globalError }}</p>

    <p
      v-if="isEmpleadoRegistro && lastCreated"
      class="mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-center text-sm text-emerald-200"
    >
      Último guardado:
      <RouterLink
        :to="`/empleado/servicio/${lastCreated.id}`"
        class="font-semibold text-sky-300 underline decoration-sky-500/50 hover:text-sky-200"
      >
        {{ lastCreated.code }}
      </RouterLink>
    </p>

    <form
      class="rounded-3xl border border-slate-800/80 bg-[#121820] p-5 shadow-xl shadow-black/30 sm:p-6"
      @submit.prevent="onSubmit"
    >
      <ServiceRegisterEmpleadoForm
        :key="formResetKey"
        v-model="form"
        v-model:photos="photoFiles"
        :companies="companies"
        :catalog-items="catalogItems"
        :client-suggestions="clientSuggestions"
        :field-errors="fieldErrors"
        :disabled="loading"
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
