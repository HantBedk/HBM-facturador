<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  FALLBACK_ACCOUNT_TYPES,
  FALLBACK_BANKS,
  FALLBACK_DOCUMENT_TYPES,
} from '@/constants/empleadoPerfilCatalogs.js'
import { fetchEmpleadoPerfilForm, saveEmpleadoPerfil } from '@/services/empleadoPerfilApi.js'
import { isEmpleadoPerfilIncomplete, validateEmpleadoPerfilForm } from '@/utils/empleadoPerfil.js'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const loading = ref(true)
const saving = ref(false)
const globalError = ref('')
const fieldErrors = ref({})
/** Al entrar a la pantalla, ¿faltaban datos obligatorios? (define copy y navegación tras guardar) */
const startedMandatory = ref(false)
const toastOk = ref('')

const mandatoryMode = computed(() => isEmpleadoPerfilIncomplete(auth.user))

const banks = ref([])
const documentTypes = ref([])
const accountTypes = ref([])

const form = ref({
  nombre: '',
  telefono: '',
  tipo_documento: '',
  numero_documento: '',
  ciudad: '',
  departamento: '',
  banco_codigo: '',
  cuenta_tipo: '',
  cuenta_numero: '',
})

const bankCodes = computed(() => banks.value.map((b) => b.codigo))

const canSave = computed(() => {
  if (loading.value) return false
  return validateEmpleadoPerfilForm(form.value, bankCodes.value).ok
})

onMounted(async () => {
  startedMandatory.value = isEmpleadoPerfilIncomplete(auth.user)
  globalError.value = ''
  try {
    const data = await fetchEmpleadoPerfilForm()
    banks.value = data.banks?.length ? data.banks : FALLBACK_BANKS
    documentTypes.value = data.document_types?.length ? data.document_types : FALLBACK_DOCUMENT_TYPES
    accountTypes.value = data.account_types?.length ? data.account_types : FALLBACK_ACCOUNT_TYPES
    const p = data.profile || {}
    form.value = {
      nombre: (p.nombre || auth.user?.nombre || '').trim(),
      telefono: p.telefono || '',
      tipo_documento: p.tipo_documento || '',
      numero_documento: p.numero_documento || '',
      ciudad: p.ciudad || '',
      departamento: p.departamento || '',
      banco_codigo: p.banco_codigo || '',
      cuenta_tipo: p.cuenta_tipo || '',
      cuenta_numero: p.cuenta_numero || '',
    }
  } catch (e) {
    globalError.value = e.data?.message || e.message || 'No se pudo cargar el formulario.'
  } finally {
    loading.value = false
  }
})

async function onSubmit() {
  globalError.value = ''
  fieldErrors.value = {}
  toastOk.value = ''

  const local = validateEmpleadoPerfilForm(form.value, bankCodes.value)
  if (!local.ok) {
    fieldErrors.value = local.fieldErrors
    globalError.value = 'Completa todos los campos obligatorios con datos válidos antes de guardar.'
    return
  }

  saving.value = true
  try {
    await saveEmpleadoPerfil({ ...form.value })
    await auth.refreshUser()
    if (isEmpleadoPerfilIncomplete(auth.user)) {
      globalError.value = 'Aún faltan datos obligatorios. Revisa el formulario.'
      return
    }
    if (startedMandatory.value) {
      const redir = route.query.redirect
      const path =
        typeof redir === 'string' && redir && !redir.includes('/empleado/perfil') ? redir : '/empleado'
      await router.replace(path)
    } else {
      toastOk.value = 'Datos actualizados correctamente.'
      window.setTimeout(() => {
        toastOk.value = ''
      }, 4000)
    }
  } catch (e) {
    if (e.data?.errors) fieldErrors.value = e.data.errors
    else globalError.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="mx-auto max-w-2xl pb-12">
    <RouterLink
      v-if="!mandatoryMode"
      to="/empleado"
      class="mb-4 inline-flex items-center gap-1 text-sm font-semibold text-sky-400/90 hover:text-sky-300"
    >
      ← Volver al panel
    </RouterLink>
    <header class="mb-8 text-center sm:text-left">
      <p class="text-xs font-semibold uppercase tracking-wide text-sky-400/90">
        {{ mandatoryMode ? 'Datos obligatorios' : 'Tu perfil' }}
      </p>
      <h1 class="mt-1 text-2xl font-bold tracking-tight text-white sm:text-[1.65rem]">
        {{
          mandatoryMode
            ? 'Completa tus datos de contacto y pago'
            : 'Actualiza tus datos de contacto y pago'
        }}
      </h1>
      <p class="mt-2 text-sm leading-relaxed text-slate-400">
        {{
          mandatoryMode
            ? 'Necesitamos esta información para comunicarnos contigo y procesar tus pagos. Los datos bancarios deben coincidir con una cuenta a tu nombre.'
            : 'Puedes corregir tu celular, documento o cuenta bancaria cuando lo necesites.'
        }}
      </p>
    </header>

    <p
      v-if="toastOk"
      class="mb-6 rounded-xl border border-emerald-500/35 bg-emerald-950/35 px-4 py-3 text-sm text-emerald-100"
      role="status"
    >
      {{ toastOk }}
    </p>

    <p
      v-if="globalError"
      class="mb-6 rounded-xl border border-red-400/35 bg-red-950/35 px-4 py-3 text-sm text-red-100"
      role="alert"
    >
      {{ globalError }}
    </p>

    <p v-if="loading" class="text-slate-500">Cargando…</p>

    <form v-else class="space-y-8" @submit.prevent="onSubmit">
      <!-- Contacto -->
      <section class="rounded-2xl border border-slate-700/50 bg-[#141b26] p-5 sm:p-6">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Identificación y contacto</h2>
        <div class="space-y-4">
          <div>
            <label class="mb-1.5 block text-xs font-medium text-slate-400">Nombre completo <span class="text-red-400">*</span></label>
            <input
              v-model="form.nombre"
              type="text"
              autocomplete="name"
              required
              maxlength="255"
              placeholder="Como debe figurar en pagos y comunicaciones"
              class="w-full rounded-xl border border-slate-600/80 bg-[#0d1219] px-4 py-3 text-slate-100 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30"
            />
            <p class="mt-1 text-xs text-slate-500">Revisa mayúsculas y tildes; así lo usaremos en nómina y mensajes.</p>
            <p v-if="fieldErrors.nombre" class="mt-1 text-sm text-red-400">{{ fieldErrors.nombre[0] }}</p>
          </div>
          <div>
            <label class="mb-1.5 block text-xs font-medium text-slate-400">Correo (solo lectura)</label>
            <input
              :value="auth.user?.correo || ''"
              type="email"
              disabled
              class="w-full cursor-not-allowed rounded-xl border border-slate-700/80 bg-slate-900/50 px-4 py-3 text-slate-500"
            />
          </div>
          <div>
            <label class="mb-1.5 block text-xs font-medium text-slate-400">Celular / WhatsApp <span class="text-red-400">*</span></label>
            <input
              v-model="form.telefono"
              type="tel"
              inputmode="tel"
              autocomplete="tel"
              placeholder="Ej. 300 123 4567"
              required
              class="w-full rounded-xl border border-slate-600/80 bg-[#0d1219] px-4 py-3 text-slate-100 outline-none ring-sky-500/30 focus:border-sky-500 focus:ring-2"
            />
            <p v-if="fieldErrors.telefono" class="mt-1 text-sm text-red-400">{{ fieldErrors.telefono[0] }}</p>
          </div>
          <div>
            <label class="mb-1.5 block text-xs font-medium text-slate-400">Ciudad de residencia <span class="text-red-400">*</span></label>
            <input
              v-model="form.ciudad"
              type="text"
              autocomplete="address-level2"
              required
              class="w-full rounded-xl border border-slate-600/80 bg-[#0d1219] px-4 py-3 text-slate-100 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30"
            />
            <p v-if="fieldErrors.ciudad" class="mt-1 text-sm text-red-400">{{ fieldErrors.ciudad[0] }}</p>
          </div>
          <div>
            <label class="mb-1.5 block text-xs font-medium text-slate-400">Departamento (opcional)</label>
            <input
              v-model="form.departamento"
              type="text"
              autocomplete="address-level1"
              class="w-full rounded-xl border border-slate-600/80 bg-[#0d1219] px-4 py-3 text-slate-100 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30"
            />
            <p v-if="fieldErrors.departamento" class="mt-1 text-sm text-red-400">{{ fieldErrors.departamento[0] }}</p>
          </div>
        </div>
      </section>

      <!-- Identificación -->
      <section class="rounded-2xl border border-slate-700/50 bg-[#141b26] p-5 sm:p-6">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Identificación</h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <div class="sm:col-span-1">
            <label class="mb-1.5 block text-xs font-medium text-slate-400">Tipo de documento <span class="text-red-400">*</span></label>
            <select
              v-model="form.tipo_documento"
              required
              class="perfil-select w-full rounded-xl border border-slate-600/80 bg-[#0d1219] px-4 py-3 text-slate-100 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30"
            >
              <option value="" disabled>Seleccione tipo de documento…</option>
              <option v-for="d in documentTypes" :key="d.codigo" :value="d.codigo">{{ d.nombre }}</option>
            </select>
            <p v-if="fieldErrors.tipo_documento" class="mt-1 text-sm text-red-400">{{ fieldErrors.tipo_documento[0] }}</p>
          </div>
          <div class="sm:col-span-1">
            <label class="mb-1.5 block text-xs font-medium text-slate-400">Número de documento <span class="text-red-400">*</span></label>
            <input
              v-model="form.numero_documento"
              type="text"
              autocomplete="off"
              required
              class="w-full rounded-xl border border-slate-600/80 bg-[#0d1219] px-4 py-3 text-slate-100 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30"
            />
            <p v-if="fieldErrors.numero_documento" class="mt-1 text-sm text-red-400">{{ fieldErrors.numero_documento[0] }}</p>
          </div>
        </div>
      </section>

      <!-- Datos bancarios -->
      <section class="rounded-2xl border border-slate-700/50 bg-[#141b26] p-5 sm:p-6">
        <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-slate-500">Datos para pago</h2>
        <p class="mb-4 text-xs text-slate-500">
          Selecciona el banco o app desde la lista para evitar errores. En Nequi / DaviPlata el número suele ser tu celular.
        </p>
        <div class="space-y-4">
          <div>
            <label class="mb-1.5 block text-xs font-medium text-slate-400">Entidad financiera (Colombia) <span class="text-red-400">*</span></label>
            <select
              v-model="form.banco_codigo"
              required
              class="perfil-select w-full rounded-xl border border-slate-600/80 bg-[#0d1219] px-4 py-3 text-slate-100 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30"
            >
              <option disabled value="">Seleccione entidad…</option>
              <option v-for="b in banks" :key="b.codigo" :value="b.codigo">{{ b.nombre }}</option>
            </select>
            <p v-if="fieldErrors.banco_codigo" class="mt-1 text-sm text-red-400">{{ fieldErrors.banco_codigo[0] }}</p>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="mb-1.5 block text-xs font-medium text-slate-400">Tipo de cuenta / medio <span class="text-red-400">*</span></label>
              <select
                v-model="form.cuenta_tipo"
                required
                class="perfil-select w-full rounded-xl border border-slate-600/80 bg-[#0d1219] px-4 py-3 text-slate-100 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30"
              >
                <option value="" disabled>Seleccione tipo…</option>
                <option v-for="a in accountTypes" :key="a.codigo" :value="a.codigo">{{ a.nombre }}</option>
              </select>
              <p v-if="form.cuenta_tipo === 'llave_breb'" class="mt-1 text-xs text-sky-400/90">
                Llave Bre-B: indica tu clave o identificador según tu banco (mismo sistema de pagos instantáneos Bre-B).
              </p>
              <p v-if="fieldErrors.cuenta_tipo" class="mt-1 text-sm text-red-400">{{ fieldErrors.cuenta_tipo[0] }}</p>
            </div>
            <div>
              <label class="mb-1.5 block text-xs font-medium text-slate-400">Número de cuenta, celular o llave <span class="text-red-400">*</span></label>
              <input
                v-model="form.cuenta_numero"
                type="text"
                inputmode="numeric"
                autocomplete="off"
                required
                class="w-full rounded-xl border border-slate-600/80 bg-[#0d1219] px-4 py-3 text-slate-100 outline-none focus:border-sky-500 focus:ring-2 focus:ring-sky-500/30"
              />
              <p v-if="fieldErrors.cuenta_numero" class="mt-1 text-sm text-red-400">{{ fieldErrors.cuenta_numero[0] }}</p>
            </div>
          </div>
        </div>
      </section>

      <p
        v-if="!canSave && !saving"
        class="text-center text-xs text-slate-500"
      >
        Completa y valida todos los campos obligatorios (marcados con *) para habilitar el guardado.
      </p>
      <button
        type="submit"
        class="w-full rounded-2xl bg-gradient-to-r from-sky-500 to-blue-600 py-4 text-base font-bold text-white shadow-lg shadow-sky-500/20 transition hover:brightness-110 disabled:opacity-50"
        :disabled="saving || !canSave"
      >
        {{
          saving
            ? 'Guardando…'
            : startedMandatory
              ? 'Guardar y continuar al panel'
              : 'Guardar cambios'
        }}
      </button>
    </form>
  </div>
</template>

<style scoped>
.perfil-select {
  color-scheme: dark;
}
/* Opciones visibles en lista nativa (tema oscuro) */
.perfil-select option {
  background-color: #1a222d;
  color: #e2e8f0;
}
</style>
