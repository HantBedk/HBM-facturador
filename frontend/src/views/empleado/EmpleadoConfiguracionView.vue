<script setup>
import { onMounted, ref } from 'vue'
import {
  fetchEmpleadoNotificacionesPreferencias,
  saveEmpleadoNotificacionesPreferencias,
  updateEmpleadoPassword,
} from '@/services/empleadoCuentaApi.js'

const loadingPrefs = ref(true)
const prefsError = ref('')
const prefsHelp = ref('')
const prefItems = ref([])
const savingPrefs = ref(false)
const prefsMsg = ref('')

const pwd = ref({
  current_password: '',
  password: '',
  password_confirmation: '',
})
const savingPwd = ref(false)
const pwdMsg = ref('')
const pwdErr = ref('')
const pwdFieldErrors = ref({})

async function loadPrefs() {
  prefsError.value = ''
  try {
    const data = await fetchEmpleadoNotificacionesPreferencias()
    prefItems.value = data.items || []
    prefsHelp.value = data.help || ''
  } catch (e) {
    prefsError.value = e.data?.message || e.message || 'No se pudieron cargar las preferencias.'
  } finally {
    loadingPrefs.value = false
  }
}

onMounted(async () => {
  await loadPrefs()
})

async function onSavePrefs() {
  prefsMsg.value = ''
  const types = {}
  for (const it of prefItems.value) {
    if (it.can_edit) types[it.type] = it.user_wants
  }
  savingPrefs.value = true
  try {
    const data = await saveEmpleadoNotificacionesPreferencias(types)
    prefItems.value = data.items || prefItems.value
    prefsMsg.value = data.message || 'Preferencias guardadas.'
  } catch (e) {
    prefsError.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    savingPrefs.value = false
  }
}

async function onSavePassword() {
  pwdMsg.value = ''
  pwdErr.value = ''
  pwdFieldErrors.value = {}
  savingPwd.value = true
  try {
    const data = await updateEmpleadoPassword({
      current_password: pwd.value.current_password,
      password: pwd.value.password,
      password_confirmation: pwd.value.password_confirmation,
    })
    pwdMsg.value = data.message || 'Contraseña actualizada.'
    pwd.value = { current_password: '', password: '', password_confirmation: '' }
  } catch (e) {
    const err = e.data?.errors || {}
    pwdFieldErrors.value = err
    pwdErr.value =
      e.data?.message ||
      (typeof err.current_password?.[0] === 'string' ? err.current_password[0] : null) ||
      (typeof err.password?.[0] === 'string' ? err.password[0] : null) ||
      e.message ||
      'No se pudo actualizar la contraseña.'
  } finally {
    savingPwd.value = false
  }
}
</script>

<template>
  <div class="space-y-10">
    <div>
      <h1 class="text-xl font-bold text-white sm:text-2xl">Configuración</h1>
      <p class="mt-1 text-sm text-slate-400">
        Cambio de contraseña y, para las notificaciones del panel, elección de qué recibir entre lo que el administrador
        habilita para tu equipo.
      </p>
    </div>

    <section class="rounded-2xl border border-slate-700/60 bg-[#0f1419]/80 p-5 shadow-lg shadow-black/20 sm:p-6">
      <h2 class="text-base font-semibold text-slate-100">Contraseña</h2>
      <p class="mt-1 text-sm text-slate-500">Cambia tu contraseña de acceso al panel.</p>

      <form class="mt-5 max-w-md space-y-4" @submit.prevent="onSavePassword">
        <div>
          <label class="block text-xs font-medium text-slate-400">Contraseña actual</label>
          <input
            v-model="pwd.current_password"
            type="password"
            autocomplete="current-password"
            class="mt-1 w-full rounded-lg border border-slate-600/80 bg-[#0b0f14] px-3 py-2 text-sm text-slate-200 transition focus:border-sky-500/60 focus:outline-none focus:ring-2 focus:ring-sky-500/30"
          />
          <p v-if="pwdFieldErrors.current_password?.[0]" class="mt-1 text-xs text-red-400">
            {{ pwdFieldErrors.current_password[0] }}
          </p>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-400">Nueva contraseña</label>
          <input
            v-model="pwd.password"
            type="password"
            autocomplete="new-password"
            class="mt-1 w-full rounded-lg border border-slate-600/80 bg-[#0b0f14] px-3 py-2 text-sm text-slate-200 transition focus:border-sky-500/60 focus:outline-none focus:ring-2 focus:ring-sky-500/30"
          />
          <p v-if="pwdFieldErrors.password?.[0]" class="mt-1 text-xs text-red-400">{{ pwdFieldErrors.password[0] }}</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-slate-400">Confirmar nueva contraseña</label>
          <input
            v-model="pwd.password_confirmation"
            type="password"
            autocomplete="new-password"
            class="mt-1 w-full rounded-lg border border-slate-600/80 bg-[#0b0f14] px-3 py-2 text-sm text-slate-200 transition focus:border-sky-500/60 focus:outline-none focus:ring-2 focus:ring-sky-500/30"
          />
        </div>
        <p v-if="pwdErr" class="text-sm text-red-400">{{ pwdErr }}</p>
        <p v-if="pwdMsg" class="text-sm text-emerald-400/90">{{ pwdMsg }}</p>
        <button
          type="submit"
          :disabled="savingPwd"
          class="rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-sky-500 disabled:opacity-50"
        >
          {{ savingPwd ? 'Guardando…' : 'Actualizar contraseña' }}
        </button>
      </form>
    </section>

    <section class="rounded-2xl border border-slate-700/60 bg-[#0f1419]/80 p-5 shadow-lg shadow-black/20 sm:p-6">
      <h2 class="text-base font-semibold text-slate-100">Notificaciones del panel</h2>
      <p class="mt-1 text-sm text-slate-500">
        Solo ves y puedes activar los tipos que el administrador dejó disponibles; dentro de eso eliges cuáles quieres
        recibir.
      </p>
      <p v-if="prefsHelp" class="mt-2 text-sm text-slate-500">{{ prefsHelp }}</p>

      <p v-if="loadingPrefs" class="mt-4 text-sm text-slate-500">Cargando…</p>
      <p v-else-if="prefsError && !prefItems.length" class="mt-4 text-sm text-red-400">{{ prefsError }}</p>
      <div v-else class="mt-5 space-y-3">
        <div
          v-for="it in prefItems"
          :key="it.type"
          class="flex items-start justify-between gap-4 rounded-xl border border-slate-700/40 bg-slate-900/30 px-4 py-3"
        >
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-slate-200">{{ it.label }}</p>
            <p v-if="!it.admin_allows" class="mt-0.5 text-xs text-slate-500">
              El administrador ha desactivado este tipo para todo el equipo.
            </p>
          </div>
          <label class="relative inline-flex cursor-pointer items-center">
            <input
              v-model="it.user_wants"
              type="checkbox"
              class="peer sr-only"
              :disabled="!it.can_edit"
            />
            <div
              class="relative h-6 w-11 shrink-0 rounded-full bg-slate-700 after:absolute after:left-[2px] after:top-[2px] after:h-5 after:w-5 after:rounded-full after:border after:border-slate-600 after:bg-slate-300 after:transition-all peer-checked:bg-sky-600 peer-checked:after:translate-x-full peer-checked:after:border-white peer-focus:ring-2 peer-focus:ring-sky-500/40 peer-disabled:cursor-not-allowed peer-disabled:opacity-40"
            />
          </label>
        </div>

        <p v-if="prefsError" class="text-sm text-red-400">{{ prefsError }}</p>
        <p v-if="prefsMsg" class="text-sm text-emerald-400/90">{{ prefsMsg }}</p>

        <button
          type="button"
          :disabled="savingPrefs || loadingPrefs"
          class="rounded-xl border border-slate-600/80 bg-slate-800/50 px-4 py-2.5 text-sm font-semibold text-slate-200 transition hover:border-slate-500 hover:bg-slate-800 disabled:opacity-50"
          @click="onSavePrefs"
        >
          {{ savingPrefs ? 'Guardando…' : 'Guardar preferencias de avisos' }}
        </button>
      </div>
    </section>
  </div>
</template>
