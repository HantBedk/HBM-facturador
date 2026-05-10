<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  deleteSystemOrganizationLogo,
  fetchSystemOrganization,
  fetchSystemOrganizationLogoBlob,
  updateSystemOrganization,
  uploadSystemOrganizationLogo,
} from '@/services/adminSystemOrganizationApi.js'

const loading = ref(true)
const saving = ref(false)
const uploadingLogo = ref(false)
const error = ref('')
const toast = ref('')

const legalName = ref('')
const tradeName = ref('')
const nit = ref('')
const email = ref('')
const phone = ref('')
const phoneSecondary = ref('')
const website = ref('')
const addressLine1 = ref('')
const addressLine2 = ref('')
const city = ref('')
const department = ref('')
const country = ref('')
const postalCode = ref('')

const logoConfigured = ref(false)
const logoFilename = ref('')
const logoInput = ref(null)
const logoPreviewUrl = ref('')

function revokeLogoPreview() {
  if (logoPreviewUrl.value && logoPreviewUrl.value.startsWith('blob:')) {
    URL.revokeObjectURL(logoPreviewUrl.value)
  }
  logoPreviewUrl.value = ''
}

async function refreshLogoPreview() {
  revokeLogoPreview()
  if (!logoConfigured.value) return
  try {
    const blob = await fetchSystemOrganizationLogoBlob()
    if (blob) logoPreviewUrl.value = URL.createObjectURL(blob)
  } catch {
    /* ignore */
  }
}

function applyData(d) {
  legalName.value = d.legal_name || ''
  tradeName.value = d.trade_name || ''
  nit.value = d.nit || ''
  email.value = d.email || ''
  phone.value = d.phone || ''
  phoneSecondary.value = d.phone_secondary || ''
  website.value = d.website || ''
  addressLine1.value = d.address_line1 || ''
  addressLine2.value = d.address_line2 || ''
  city.value = d.city || ''
  department.value = d.department || ''
  country.value = d.country || ''
  postalCode.value = d.postal_code || ''
  logoConfigured.value = Boolean(d.logo_configured)
  logoFilename.value = d.logo_filename || ''
}

async function load() {
  error.value = ''
  loading.value = true
  try {
    const d = await fetchSystemOrganization()
    applyData(d)
    await refreshLogoPreview()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo cargar.'
  } finally {
    loading.value = false
  }
}

async function save() {
  error.value = ''
  toast.value = ''
  saving.value = true
  try {
    const r = await updateSystemOrganization({
      legal_name: legalName.value.trim() || null,
      trade_name: tradeName.value.trim() || null,
      nit: nit.value.trim() || null,
      email: email.value.trim() || null,
      phone: phone.value.trim() || null,
      phone_secondary: phoneSecondary.value.trim() || null,
      website: website.value.trim() || null,
      address_line1: addressLine1.value.trim() || null,
      address_line2: addressLine2.value.trim() || null,
      city: city.value.trim() || null,
      department: department.value.trim() || null,
      country: country.value.trim() || null,
      postal_code: postalCode.value.trim() || null,
    })
    toast.value = r.message || 'Guardado.'
    applyData(r.data)
    await refreshLogoPreview()
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

function pickLogo() {
  logoInput.value?.click()
}

async function onLogoSelected(ev) {
  const file = ev.target?.files?.[0]
  ev.target.value = ''
  if (!file) return
  error.value = ''
  uploadingLogo.value = true
  try {
    const fd = new FormData()
    fd.append('file', file)
    const r = await uploadSystemOrganizationLogo(fd)
    toast.value = r.message || 'Logo actualizado.'
    applyData(r.data)
    await refreshLogoPreview()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo subir el logo.'
  } finally {
    uploadingLogo.value = false
  }
}

async function removeLogo() {
  error.value = ''
  uploadingLogo.value = true
  try {
    const r = await deleteSystemOrganizationLogo()
    toast.value = r.message || 'Logo eliminado.'
    applyData(r.data)
    revokeLogoPreview()
  } catch (e) {
    error.value = e.data?.message || e.message || 'No se pudo eliminar.'
  } finally {
    uploadingLogo.value = false
  }
}

onMounted(load)
onUnmounted(revokeLogoPreview)
</script>

<template>
  <section class="mx-auto max-w-3xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold text-white">Empresa del sistema</h1>
      <p class="mt-1 text-sm text-slate-400">
        Datos de su empresa operadora (facturador): distintos de las empresas cliente del directorio. Se usan como respaldo del nombre en
        <RouterLink to="/admin/configuracion/correo-notificaciones" class="text-sky-400 hover:underline">correos</RouterLink>
        <span v-pre> (sustituye {{nombre_sistema}} en correos de bienvenida, factura y mantenimiento).</span>
      </p>
    </div>

    <p v-if="loading" class="text-sm text-slate-500">Cargando…</p>
    <template v-else>
      <p v-if="error" class="rounded-lg border border-rose-500/40 bg-rose-900/20 px-3 py-2 text-sm text-rose-200">{{ error }}</p>
      <p v-if="toast" class="rounded-lg border border-emerald-600/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-100">{{ toast }}</p>

      <div class="rounded-xl border border-slate-700/80 bg-[#111723] p-3 sm:p-4 space-y-4">
        <div class="rounded-lg border border-slate-700/50 bg-slate-900/25 p-3 space-y-3">
          <p class="text-sm font-medium text-white">Identificación</p>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-sm sm:col-span-2">
              <span class="text-slate-400">Razón social / nombre legal</span>
              <input
                v-model="legalName"
                type="text"
                maxlength="255"
                class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
              />
            </label>
            <label class="block text-sm sm:col-span-2">
              <span class="text-slate-400">Nombre comercial</span>
              <input
                v-model="tradeName"
                type="text"
                maxlength="255"
                class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
                placeholder="Si difiere de la razón social"
              />
            </label>
            <label class="block text-sm">
              <span class="text-slate-400">NIT / identificación fiscal</span>
              <input v-model="nit" type="text" maxlength="100" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white" />
            </label>
            <label class="block text-sm">
              <span class="text-slate-400">Correo de contacto</span>
              <input v-model="email" type="email" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white" />
            </label>
          </div>
        </div>

        <div class="rounded-lg border border-slate-700/50 bg-slate-900/25 p-3 space-y-3">
          <p class="text-sm font-medium text-white">Contacto y web</p>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-sm">
              <span class="text-slate-400">Teléfono principal</span>
              <input v-model="phone" type="text" maxlength="64" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white" />
            </label>
            <label class="block text-sm">
              <span class="text-slate-400">Teléfono alternativo</span>
              <input
                v-model="phoneSecondary"
                type="text"
                maxlength="64"
                class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
              />
            </label>
            <label class="block text-sm sm:col-span-2">
              <span class="text-slate-400">Sitio web</span>
              <input v-model="website" type="text" maxlength="255" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white" />
            </label>
          </div>
        </div>

        <div class="rounded-lg border border-slate-700/50 bg-slate-900/25 p-3 space-y-3">
          <p class="text-sm font-medium text-white">Dirección</p>
          <label class="block text-sm">
            <span class="text-slate-400">Línea 1</span>
            <input v-model="addressLine1" type="text" maxlength="255" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white" />
          </label>
          <label class="block text-sm">
            <span class="text-slate-400">Línea 2</span>
            <input v-model="addressLine2" type="text" maxlength="255" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white" />
          </label>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="block text-sm">
              <span class="text-slate-400">Ciudad</span>
              <input v-model="city" type="text" maxlength="120" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white" />
            </label>
            <label class="block text-sm">
              <span class="text-slate-400">Departamento / estado</span>
              <input v-model="department" type="text" maxlength="120" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white" />
            </label>
            <label class="block text-sm">
              <span class="text-slate-400">País</span>
              <input v-model="country" type="text" maxlength="120" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white" />
            </label>
            <label class="block text-sm">
              <span class="text-slate-400">Código postal</span>
              <input v-model="postalCode" type="text" maxlength="32" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white" />
            </label>
          </div>
        </div>

        <div class="rounded-lg border border-slate-700/50 bg-slate-900/25 p-3 space-y-3">
          <p class="text-sm font-medium text-white">Logo</p>
          <p class="text-xs text-slate-500">PNG, JPG, GIF o WebP; máx. 2 MB. Uso previsto: pie de correos, PDFs o pantallas futuras.</p>
          <div v-if="logoPreviewUrl" class="flex items-start gap-3">
            <img :src="logoPreviewUrl" alt="Logo" class="h-16 w-auto max-w-[200px] rounded border border-slate-600 object-contain bg-slate-900" />
            <div class="text-xs text-slate-400">
              <p v-if="logoFilename" class="font-medium text-slate-300">{{ logoFilename }}</p>
            </div>
          </div>
          <input ref="logoInput" type="file" accept="image/png,image/jpeg,image/gif,image/webp" class="sr-only" @change="onLogoSelected" />
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="min-h-[40px] rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700 disabled:opacity-50"
              :disabled="uploadingLogo"
              @click="pickLogo"
            >
              {{ uploadingLogo ? '…' : logoConfigured ? 'Cambiar logo' : 'Subir logo' }}
            </button>
            <button
              v-if="logoConfigured"
              type="button"
              class="min-h-[40px] rounded-lg border border-rose-600/50 bg-rose-950/40 px-3 py-2 text-sm text-rose-100 hover:bg-rose-900/50 disabled:opacity-50"
              :disabled="uploadingLogo"
              @click="removeLogo"
            >
              Quitar logo
            </button>
          </div>
        </div>

        <button
          type="button"
          class="min-h-[44px] rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-sky-500 disabled:opacity-50"
          :disabled="saving"
          @click="save"
        >
          {{ saving ? 'Guardando…' : 'Guardar datos' }}
        </button>
      </div>
    </template>
  </section>
</template>
