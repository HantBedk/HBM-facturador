<script setup>
import { onMounted, ref } from 'vue'
import { fetchMailNotificationsSettings, updateMailNotificationsSettings } from '@/services/adminMailNotificationsApi.js'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const toast = ref('')
const help = ref('')

const fromAddress = ref('')
const fromName = ref('')
const effectiveAddress = ref('')
const effectiveName = ref('')

const currentPassword = ref('')

async function load() {
  error.value = ''
  toast.value = ''
  loading.value = true
  try {
    const r = await fetchMailNotificationsSettings()
    help.value = r.help || ''
    const d = r.data || {}
    fromAddress.value = d.from_address || ''
    fromName.value = d.from_name || ''
    effectiveAddress.value = d.effective_from_address || ''
    effectiveName.value = d.effective_from_name || ''
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar la configuración.'
  } finally {
    loading.value = false
  }
}

async function save() {
  error.value = ''
  toast.value = ''
  if (!currentPassword.value.trim()) {
    error.value = 'Ingrese su contraseña actual para guardar.'
    return
  }
  saving.value = true
  try {
    const r = await updateMailNotificationsSettings({
      current_password: currentPassword.value,
      from_address: fromAddress.value.trim() || null,
      from_name: fromName.value.trim() || null,
    })
    toast.value = r.message || 'Guardado.'
    currentPassword.value = ''
    await load()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo guardar.'
    const errs = e.data?.errors
    if (errs && typeof errs === 'object') {
      const first = Object.values(errs).flat()[0]
      if (first) error.value = String(first)
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="mx-auto max-w-2xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold text-white">Correo del sistema</h1>
      <p class="mt-1 text-sm text-slate-400">Remitente para notificaciones y envíos (OTP inventario, facturas, etc.).</p>
    </div>

    <p v-if="loading" class="text-sm text-slate-500">Cargando…</p>
    <p v-else-if="error" class="rounded-lg border border-rose-500/40 bg-rose-900/20 px-3 py-2 text-sm text-rose-200">{{ error }}</p>
    <p v-if="toast" class="rounded-lg border border-emerald-600/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-100">{{ toast }}</p>

    <div v-if="!loading" class="rounded-xl border border-slate-700/80 bg-[#111723] p-4 space-y-4">
      <p v-if="help" class="text-xs leading-relaxed text-slate-500">{{ help }}</p>
      <div class="rounded-lg border border-slate-700/60 bg-slate-900/40 px-3 py-2 text-xs text-slate-400">
        <p class="font-medium text-slate-300">Remitente efectivo hoy</p>
        <p class="mt-1"><span class="text-slate-500">Correo:</span> {{ effectiveAddress || '—' }}</p>
        <p><span class="text-slate-500">Nombre:</span> {{ effectiveName || '—' }}</p>
      </div>
      <label class="block text-sm">
        <span class="text-slate-400">Correo remitente (From)</span>
        <input
          v-model="fromAddress"
          type="email"
          autocomplete="off"
          class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white placeholder:text-slate-600"
          placeholder="Vacío = usar MAIL_FROM_ADDRESS del servidor"
        />
      </label>
      <label class="block text-sm">
        <span class="text-slate-400">Nombre visible</span>
        <input
          v-model="fromName"
          type="text"
          maxlength="120"
          class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white placeholder:text-slate-600"
          placeholder="Ej. Facturación HBM"
        />
      </label>
      <label class="block text-sm">
        <span class="text-slate-400">Su contraseña actual (confirmación)</span>
        <input
          v-model="currentPassword"
          type="password"
          autocomplete="current-password"
          class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
        />
      </label>
      <button
        type="button"
        class="min-h-[44px] rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-sky-500 disabled:opacity-50"
        :disabled="saving"
        @click="save"
      >
        {{ saving ? 'Guardando…' : 'Guardar' }}
      </button>
    </div>
  </section>
</template>
