<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import ServiceFormFields from '@/components/services/ServiceFormFields.vue'
import ServiceRegisterEmpleadoForm from '@/components/services/ServiceRegisterEmpleadoForm.vue'
import {
  clearServiceDraft,
  createService,
  fetchCompanies,
  fetchServiceCatalogActive,
  getRecentClientNames,
  loadServiceDraft,
  pushRecentClientName,
  saveServiceDraft,
} from '@/services/servicesApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'
import { isLineDescriptionStillTemplate } from '@/utils/serviceLineDescriptionTemplate.js'

const auth = useAuthStore()
const uiDialog = useUiDialogStore()
const router = useRouter()
const route = useRoute()

const companies = ref([])
const catalogItems = ref([])
const clientSuggestions = ref([])
const loading = ref(false)
const fieldErrors = ref({})
const globalError = ref('')
const toast = ref('')
const lastCreated = ref(null)
const formResetKey = ref(0)
/** Archivos locales (solo registro empleado); máx. 4 en UI */
const photoFiles = ref([])

const form = ref({
  company_id: '',
  /** Cliente puntual: sin empresa en lista; el API crea/reutiliza empresa por teléfono. */
  use_quick_client: false,
  quick_telefono: '',
  catalog_id: '',
  client_name: '',
  service_type: '',
  description: '',
  amount: '',
  /** Solo registro empleado: líneas de catálogo / Otro */
  lines: [],
})

const isEmpleadoRegistro = computed(() => route.name === 'emp-registro-servicio')

const basePrefix = computed(() => (isAdminPanelRole(auth.user?.rol) ? '/admin' : '/empleado'))
const cancelTo = computed(() =>
  isAdminPanelRole(auth.user?.rol) ? `${basePrefix.value}/servicios` : '/empleado'
)

let persistTimer = null
let toastTimer = null

function scheduleDraftPersist() {
  if (isEmpleadoRegistro.value) return
  clearTimeout(persistTimer)
  persistTimer = setTimeout(() => {
    saveServiceDraft(form.value)
  }, 400)
}

function showToast(message) {
  toast.value = message
  clearTimeout(toastTimer)
  toastTimer = setTimeout(() => {
    toast.value = ''
  }, 3200)
}

function resetFormToDefaults() {
  form.value = {
    company_id: '',
    use_quick_client: false,
    quick_telefono: '',
    catalog_id: '',
    client_name: '',
    service_type: '',
    description: '',
    amount: '',
    lines: [],
  }
  photoFiles.value = []
  formResetKey.value += 1
}

function digitsOnly(s) {
  return String(s ?? '').replace(/\D/g, '')
}

/** Texto guardado en `services.description`: detalle por concepto (sin campo extra en UI empleado). */
function buildServiceDescriptionFromLines(rawLines) {
  const parts = []
  for (const row of rawLines) {
    const ld = String(row.line_description || '').trim()
    if (!ld) continue
    const head =
      row.catalog_id != null && row.catalog_id !== ''
        ? String(row.label || 'Ítem').trim()
        : String(row.custom_name || '').trim() || 'Concepto'
    parts.push(`${head}: ${ld}`)
  }
  return parts.join('\n\n')
}

function buildPayloadItemsFromLines(rawLines) {
  return rawLines.map((row) => {
    if (row.catalog_id != null && row.catalog_id !== '') {
      const o = { catalog_id: Number(row.catalog_id), amount: Number(row.amount) }
      const d = String(row.line_description || '').trim()
      if (d) o.line_description = d
      return o
    }
    const o = {
      custom_name: String(row.custom_name || '').trim(),
      amount: Number(row.amount),
    }
    const d = String(row.line_description || '').trim()
    if (d) {
      o.custom_description = d
      o.line_description = d
    }
    return o
  })
}

function validateBeforeSubmit() {
  fieldErrors.value = {}
  const e = {}
  if (form.value.use_quick_client) {
    if (digitsOnly(form.value.quick_telefono).length < 7) {
      e.quick_telefono = ['Indica un teléfono con al menos 7 dígitos (identifica al cliente puntual).']
    }
  } else if (!form.value.company_id) {
    e.company_id = ['Selecciona una empresa o activa «Cliente puntual».']
  }
  if (!String(form.value.client_name || '').trim()) {
    e.client_name = ['Indica el nombre del cliente atendido.']
  }
  if (!String(form.value.service_type || '').trim()) {
    e.service_type = ['Indica el tipo de servicio (catálogo o texto libre).']
  }
    if (!isEmpleadoRegistro.value) {
    const desc = String(form.value.description || '').trim()
    if (!desc) e.description = ['La descripción es obligatoria.']
    else if (desc.length < 8) {
      e.description = ['Describe el trabajo con más detalle (mínimo 8 caracteres).']
    }
  }

  if (isEmpleadoRegistro.value) {
    const ls = Array.isArray(form.value.lines) ? form.value.lines : []
    if (!ls.length) {
      e.items = ['Añade al menos un ítem del catálogo o «Otro».']
    }
    for (let i = 0; i < ls.length; i++) {
      const row = ls[i]
      const amt = Number(row.amount)
      if (Number.isNaN(amt) || amt < 0.01) {
        e.items = [`Revisa el importe de la línea ${i + 1}.`]
        break
      }
      if (row.catalog_id != null && row.catalog_id !== '') {
        const ld = String(row.line_description || '').trim()
        if (ld.length < 8) {
          e.items = [`Describe el trabajo en la línea ${i + 1} (mín. 8 caracteres).`]
          break
        }
      } else {
        const name = String(row.custom_name || '').trim()
        if (name.length < 2) {
          e.items = [`Indica el nombre del servicio en la línea ${i + 1} («Otro» o lista orientativa).`]
          break
        }
        const ld = String(row.line_description || '').trim()
        if (ld.length < 8) {
          e.items = [`Describe el trabajo en la línea ${i + 1} (mín. 8 caracteres).`]
          break
        }
      }
    }
    const built = buildServiceDescriptionFromLines(ls)
    if (built.length < 8) {
      e.items = e.items || ['Falta detalle en las líneas para armar la descripción del servicio (mín. 8 caracteres en conjunto).']
    }
  } else {
    const amt = Number(form.value.amount)
    if (Number.isNaN(amt) || amt < 0.01) {
      e.amount = ['Indica un valor numérico mayor a cero.']
    }
  }

  fieldErrors.value = e
  return Object.keys(e).length === 0
}

async function refreshCatalog() {
  try {
    catalogItems.value = await fetchServiceCatalogActive()
  } catch {
    catalogItems.value = []
  }
}

onMounted(async () => {
  clientSuggestions.value = getRecentClientNames()
  if (!isEmpleadoRegistro.value) {
    const draft = loadServiceDraft()
    if (draft && typeof draft === 'object') {
      form.value = {
        company_id: draft.company_id ?? '',
        use_quick_client: !!draft.use_quick_client,
        quick_telefono: draft.quick_telefono ?? '',
        catalog_id: draft.catalog_id ?? '',
        client_name: draft.client_name ?? '',
        service_type: draft.service_type ?? '',
        description: draft.description ?? '',
        amount: draft.amount ?? '',
        lines: [],
      }
    }
  }
  try {
    companies.value = await fetchCompanies()
  } catch (e) {
    globalError.value = e.data?.message || 'No se pudieron cargar las empresas.'
  }
  await refreshCatalog()
})

watch(
  () => form.value.company_id,
  (cid, prev) => {
    if (
      isEmpleadoRegistro.value &&
      !form.value.use_quick_client &&
      prev !== undefined &&
      String(cid) !== String(prev)
    ) {
      form.value.lines = []
      form.value.amount = ''
      form.value.catalog_id = ''
    }
  }
)

watch(
  () => form.value.use_quick_client,
  (quick, prev) => {
    if (!isEmpleadoRegistro.value || prev === undefined) return
    form.value.lines = []
    form.value.amount = ''
    form.value.catalog_id = ''
    if (quick) {
      form.value.company_id = ''
    } else {
      form.value.quick_telefono = ''
    }
  }
)

watch(form, () => scheduleDraftPersist(), { deep: true })

onUnmounted(() => {
  clearTimeout(persistTimer)
  clearTimeout(toastTimer)
})

async function onSubmit() {
  fieldErrors.value = {}
  globalError.value = ''
  if (!validateBeforeSubmit()) return

  if (isEmpleadoRegistro.value) {
    const ls = Array.isArray(form.value.lines) ? form.value.lines : []
    const anyTemplate = ls.some((row) => isLineDescriptionStillTemplate(row, catalogItems.value))
    if (anyTemplate) {
      const proceed = await uiDialog.confirm({
        title: 'Descripciones sin personalizar',
        message:
          'Una o más líneas siguen con el texto automático del catálogo (no lo editaste). Es mejor aclarar qué trabajo hiciste en cada concepto. ¿Deseas enviar igualmente?',
        confirmLabel: 'Enviar igual',
        cancelLabel: 'Revisar líneas',
      })
      if (!proceed) return
    }
  }

  loading.value = true
  try {
    const baseClient = form.value.client_name.trim()
    const payload = {
      client_name: baseClient,
      service_type: form.value.service_type.trim(),
      description: form.value.description.trim(),
      amount: Number(form.value.amount),
    }
    if (form.value.use_quick_client) {
      payload.quick_client = {
        nombre: baseClient,
        telefono: String(form.value.quick_telefono || '').trim(),
      }
    } else {
      payload.company_id = Number(form.value.company_id)
    }
    if (isEmpleadoRegistro.value) {
      const ls = Array.isArray(form.value.lines) ? form.value.lines : []
      payload.items = buildPayloadItemsFromLines(ls)
      payload.description = buildServiceDescriptionFromLines(ls)
      let t = 0
      for (const row of ls) {
        const n = Number(row.amount)
        if (!Number.isNaN(n)) t += n
      }
      payload.amount = Number(t.toFixed(2))
    } else if (form.value.catalog_id !== '' && form.value.catalog_id != null) {
      payload.catalog_id = Number(form.value.catalog_id)
    }
    const created = await createService(
      payload,
      isEmpleadoRegistro.value ? photoFiles.value : []
    )
    pushRecentClientName(form.value.client_name)
    clearServiceDraft()

    if (isEmpleadoRegistro.value) {
      showToast('Servicio guardado')
      lastCreated.value = { id: created.id, code: created.code }
      resetFormToDefaults()
    } else {
      await uiDialog.alert({
        title: 'Servicio registrado',
        message: `Servicio registrado correctamente.\nCódigo: ${created.code}`,
      })
      await router.push(`${basePrefix.value}/servicios/${created.id}`)
    }
  } catch (e) {
    if (e.data?.errors) fieldErrors.value = e.data.errors
    else globalError.value = e.data?.message || e.message || 'No se pudo guardar.'
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <section :class="isEmpleadoRegistro ? 'mx-auto max-w-md pb-8' : 'page'">
    <header :class="isEmpleadoRegistro ? 'mb-6 text-center' : 'head'">
      <h1
        :class="
          isEmpleadoRegistro
            ? 'text-xl font-bold tracking-tight text-white sm:text-2xl'
            : ''
        "
      >
        Registrar servicio
      </h1>
      <p v-if="!isEmpleadoRegistro" class="muted">
        Completa los datos del trabajo realizado. La fecha del servicio y el código (SERV-…) los asigna el servidor al guardar.
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
      :class="isEmpleadoRegistro ? 'rounded-3xl border border-slate-800/80 bg-[#121820] p-5 shadow-xl shadow-black/30 sm:p-6' : 'card'"
      @submit.prevent="onSubmit"
    >
      <ServiceRegisterEmpleadoForm
        v-if="isEmpleadoRegistro"
        :key="formResetKey"
        v-model="form"
        v-model:photos="photoFiles"
        :companies="companies"
        :catalog-items="catalogItems"
        :client-suggestions="clientSuggestions"
        :field-errors="fieldErrors"
        :disabled="loading"
      />

      <template v-else>
        <ServiceFormFields
          v-model="form"
          :companies="companies"
          :catalog-items="catalogItems"
          :client-suggestions="clientSuggestions"
          :field-errors="fieldErrors"
          :disabled="loading"
          service-date-from-server
        />
        <div class="actions">
          <RouterLink class="btn secondary" :to="cancelTo">Cancelar</RouterLink>
          <button class="btn primary" type="submit" :disabled="loading">
            {{ loading ? 'Guardando…' : 'Guardar servicio' }}
          </button>
        </div>
      </template>
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
.page {
  max-width: 920px;
  margin: 0 auto;
}

.head h1 {
  margin: 0 0 0.35rem;
}

.muted {
  color: #94a3b8;
  margin: 0 0 1rem;
}

.banner {
  padding: 0.65rem 0.85rem;
  border-radius: 10px;
  background: rgba(248, 113, 113, 0.12);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
  margin-bottom: 1rem;
}

.card {
  padding: 1.25rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
  justify-content: flex-end;
  margin-top: 1.25rem;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.55rem 1rem;
  border-radius: 10px;
  text-decoration: none;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
}

.btn.secondary {
  border-color: rgba(148, 163, 184, 0.35);
  color: #e2e8f0;
  background: transparent;
}

.btn.primary {
  background: linear-gradient(90deg, #2563eb, #7c3aed);
  color: #fff;
}

.btn.primary:disabled {
  opacity: 0.65;
  cursor: not-allowed;
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
