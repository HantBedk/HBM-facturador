<script setup>
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
  deleteMailTemplatePdf,
  fetchMailNotificationsSettings,
  fetchMailNotificationsUnlockStatus,
  unlockMailNotificationsSettings,
  sendMailNotificationsTest,
  updateMailNotificationsSettings,
  uploadMailTemplatePdf,
} from '@/services/adminMailNotificationsApi.js'
import { useUiDialogStore } from '@/stores/uiDialog'

/** Ocultar UI de «Correo de prueba» sin borrar el código (reactivar cuando haga falta). */
const SHOW_MAIL_TEST_SECTION = false

const checkingGate = ref(true)
const gateLocked = ref(true)
const gatePassword = ref('')
const gateError = ref('')
const gateSubmitting = ref(false)
const gateInputKey = ref(0)

const loading = ref(false)
const saving = ref(false)
const uploadingKind = ref(null)
const deletingKind = ref(null)
const error = ref('')
const toast = ref('')
const helpWelcomePdf = ref('')
const helpInvoicePdf = ref('')
const helpMaintenancePdf = ref('')
const helpGmailSmtp = ref('')

const fromAddress = ref('')
const fromName = ref('')
const smtpHost = ref('smtp.gmail.com')
const smtpPort = ref(587)
const smtpEncryption = ref('tls')
const smtpUsername = ref('')
const smtpPassword = ref('')
const hasSmtpPassword = ref(false)
const clearSmtpPassword = ref(false)
const panelSmtpReady = ref(false)
const gmailModalOpen = ref(false)
const gmailModalSaving = ref(false)

const welcomeSubject = ref('')
const welcomeBody = ref('')
const invoiceToCompanySubject = ref('')
const invoiceToCompanyBody = ref('')
const maintenanceSubject = ref('')
const maintenanceBody = ref('')
const companyWelcomePdfConfigured = ref(false)
const companyWelcomePdfFilename = ref('')
const invoiceSupplementPdfConfigured = ref(false)
const invoiceSupplementPdfFilename = ref('')
const maintenanceSupplementPdfConfigured = ref(false)
const maintenanceSupplementPdfFilename = ref('')

const welcomePdfInput = ref(null)
const invoicePdfInput = ref(null)
const maintenancePdfInput = ref(null)

const testSendSubmitting = ref(false)
const uiDialog = useUiDialogStore()

function applyDataFromResponse(r) {
  const d = r.data || {}
  fromAddress.value = d.from_address || ''
  fromName.value = d.from_name || ''
  const smtp = d.smtp || {}
  smtpHost.value = (smtp.host && String(smtp.host).trim()) || 'smtp.gmail.com'
  smtpPort.value = Number(smtp.port || 587)
  smtpEncryption.value = smtp.encryption || 'tls'
  smtpUsername.value = smtp.username || ''
  hasSmtpPassword.value = Boolean(smtp.has_smtp_password)
  panelSmtpReady.value = Boolean(smtp.panel_smtp_ready)
  smtpPassword.value = ''
  clearSmtpPassword.value = false
  welcomeSubject.value = d.welcome_subject || ''
  welcomeBody.value = d.welcome_body || ''
  invoiceToCompanySubject.value = d.invoice_to_company_subject || ''
  invoiceToCompanyBody.value = d.invoice_to_company_body || ''
  maintenanceSubject.value = d.maintenance_subject || ''
  maintenanceBody.value = d.maintenance_body || ''
  companyWelcomePdfConfigured.value = Boolean(d.company_welcome_pdf_configured)
  companyWelcomePdfFilename.value = d.company_welcome_pdf_filename || ''
  invoiceSupplementPdfConfigured.value = Boolean(d.invoice_supplement_pdf_configured)
  invoiceSupplementPdfFilename.value = d.invoice_supplement_pdf_filename || ''
  maintenanceSupplementPdfConfigured.value = Boolean(d.maintenance_supplement_pdf_configured)
  maintenanceSupplementPdfFilename.value = d.maintenance_supplement_pdf_filename || ''
}

function handleMailConfigLocked() {
  gateLocked.value = true
  gatePassword.value = ''
  gateInputKey.value += 1
  toast.value = ''
  error.value = 'La sesión de acceso a esta configuración expiró. Vuelva a confirmar su contraseña.'
}

async function loadSettings() {
  error.value = ''
  toast.value = ''
  loading.value = true
  try {
    const r = await fetchMailNotificationsSettings()
    helpWelcomePdf.value = r.help_company_welcome_pdf || ''
    helpInvoicePdf.value = r.help_invoice_supplement_pdf || ''
    helpMaintenancePdf.value = r.help_maintenance_supplement_pdf || ''
    helpGmailSmtp.value = r.help_gmail_smtp || ''
    applyDataFromResponse(r)
  } catch (e) {
    if (e.status === 403 && e.code === 'mail_config_locked') {
      handleMailConfigLocked()
    } else {
      error.value = e.data?.message || e.message || 'No se pudo cargar la configuración.'
    }
  } finally {
    loading.value = false
  }
}

async function init() {
  checkingGate.value = true
  gateError.value = ''
  try {
    const st = await fetchMailNotificationsUnlockStatus()
    if (st.unlocked) {
      gateLocked.value = false
      await loadSettings()
    } else {
      gateLocked.value = true
    }
  } catch (e) {
    gateError.value = e.data?.message || e.message || 'No se pudo comprobar el acceso.'
  } finally {
    checkingGate.value = false
  }
}

async function submitGate() {
  gateError.value = ''
  if (!gatePassword.value.trim()) {
    gateError.value = 'Ingrese su contraseña.'
    return
  }
  gateSubmitting.value = true
  try {
    await unlockMailNotificationsSettings({ current_password: gatePassword.value })
    gatePassword.value = ''
    gateInputKey.value += 1
    gateLocked.value = false
    await loadSettings()
  } catch (e) {
    gateError.value = e.data?.message || e.message || 'No se pudo validar la contraseña.'
    const errs = e.data?.errors
    if (errs && typeof errs === 'object') {
      const first = Object.values(errs).flat()[0]
      if (first) gateError.value = String(first)
    }
  } finally {
    gateSubmitting.value = false
  }
}

async function sendTestNotification() {
  error.value = ''
  toast.value = ''
  const raw = await uiDialog.prompt({
    title: 'Enviar correo de prueba',
    message: 'Ingrese el correo destino',
    placeholder: 'correo@ejemplo.com',
    confirmLabel: 'Enviar',
    cancelLabel: 'Cancelar',
  })
  const to = (raw || '').trim().toLowerCase()
  if (!to) {
    if (raw === null) return
    error.value = 'Debe indicar un correo de destino.'
    return
  }
  testSendSubmitting.value = true
  try {
    const r = await sendMailNotificationsTest({ to })
    toast.value = r.message || 'Correo de prueba enviado.'
  } catch (e) {
    if (e.status === 403 && e.code === 'mail_config_locked') {
      handleMailConfigLocked()
    } else {
      error.value = e.data?.message || e.message || 'No se pudo enviar la prueba.'
      const errs = e.data?.errors
      if (errs && typeof errs === 'object') {
        const first = Object.values(errs).flat()[0]
        if (first) error.value = String(first)
      }
    }
  } finally {
    testSendSubmitting.value = false
  }
}

/** Solo plantillas de texto/PDF; no modifica SMTP ni remitente. */
async function saveTemplatesOnly() {
  error.value = ''
  toast.value = ''
  saving.value = true
  try {
    const r = await updateMailNotificationsSettings({
      welcome_subject: welcomeSubject.value.trim() || null,
      welcome_body: welcomeBody.value || null,
      invoice_to_company_subject: invoiceToCompanySubject.value.trim() || null,
      invoice_to_company_body: invoiceToCompanyBody.value || null,
      maintenance_subject: maintenanceSubject.value.trim() || null,
      maintenance_body: maintenanceBody.value || null,
    })
    applyDataFromResponse(r)
    await uiDialog.alert({
      title: 'Plantillas guardadas',
      message: r.message || 'Los textos de bienvenida, factura y mantenimiento se guardaron correctamente.',
    })
  } catch (e) {
    if (e.status === 403 && e.code === 'mail_config_locked') {
      handleMailConfigLocked()
    } else {
      error.value = e.data?.message || e.message || 'No se pudo guardar.'
      const errs = e.data?.errors
      if (errs && typeof errs === 'object') {
        const first = Object.values(errs).flat()[0]
        if (first) error.value = String(first)
      }
    }
  } finally {
    saving.value = false
  }
}

function openGmailModal() {
  gmailModalOpen.value = true
}

async function closeGmailModal() {
  gmailModalOpen.value = false
  await loadSettings()
}

async function saveGmailFromModal() {
  error.value = ''
  toast.value = ''
  gmailModalSaving.value = true
  try {
    const r = await updateMailNotificationsSettings({
      from_address: fromAddress.value.trim() || null,
      from_name: fromName.value.trim() || null,
      smtp_host: smtpHost.value.trim() || null,
      smtp_port: Number(smtpPort.value) || 587,
      smtp_encryption: smtpEncryption.value || null,
      smtp_username: smtpUsername.value.trim() || null,
      smtp_password: smtpPassword.value.trim() || null,
      clear_smtp_password: clearSmtpPassword.value,
    })
    toast.value = r.message || 'Conexión Gmail guardada.'
    applyDataFromResponse(r)
    gmailModalOpen.value = false
  } catch (e) {
    if (e.status === 403 && e.code === 'mail_config_locked') {
      handleMailConfigLocked()
      gmailModalOpen.value = false
    } else {
      error.value = e.data?.message || e.message || 'No se pudo guardar la conexión.'
      const errs = e.data?.errors
      if (errs && typeof errs === 'object') {
        const first = Object.values(errs).flat()[0]
        if (first) error.value = String(first)
      }
    }
  } finally {
    gmailModalSaving.value = false
  }
}

function pickWelcomePdf() {
  error.value = ''
  welcomePdfInput.value?.click()
}

function pickInvoicePdf() {
  error.value = ''
  invoicePdfInput.value?.click()
}

function pickMaintenancePdf() {
  error.value = ''
  maintenancePdfInput.value?.click()
}

async function onTemplatePdfSelected(ev, kind) {
  const file = ev.target?.files?.[0]
  if (ev.target) ev.target.value = ''
  if (!file) return
  error.value = ''
  toast.value = ''
  uploadingKind.value = kind
  try {
    const fd = new FormData()
    fd.append('kind', kind)
    fd.append('file', file)
    const r = await uploadMailTemplatePdf(fd)
    toast.value = r.message || 'PDF guardado.'
    applyDataFromResponse(r)
  } catch (e) {
    if (e.status === 403 && e.code === 'mail_config_locked') {
      handleMailConfigLocked()
    } else {
      error.value = e.data?.message || e.message || 'No se pudo subir el PDF.'
      const errs = e.data?.errors
      if (errs && typeof errs === 'object') {
        const first = Object.values(errs).flat()[0]
        if (first) error.value = String(first)
      }
    }
  } finally {
    uploadingKind.value = null
  }
}

async function removeTemplatePdf(kind) {
  error.value = ''
  toast.value = ''
  deletingKind.value = kind
  try {
    const r = await deleteMailTemplatePdf({ kind })
    toast.value = r.message || 'PDF eliminado.'
    applyDataFromResponse(r)
  } catch (e) {
    if (e.status === 403 && e.code === 'mail_config_locked') {
      handleMailConfigLocked()
    } else {
      error.value = e.data?.message || e.message || 'No se pudo eliminar.'
      const errs = e.data?.errors
      if (errs && typeof errs === 'object') {
        const first = Object.values(errs).flat()[0]
        if (first) error.value = String(first)
      }
    }
  } finally {
    deletingKind.value = null
  }
}

onMounted(init)
</script>

<template>
  <section class="mx-auto max-w-3xl space-y-4">
    <div>
      <h1 class="text-xl font-semibold text-white">Correo del sistema</h1>
      <p class="mt-1 text-sm text-slate-400">
        Gmail por SMTP desde el panel (contraseña de aplicación cifrada) o respaldo con MAIL_* en .env. Plantillas de bienvenida, factura y mantenimiento. El acceso requiere confirmar su contraseña.
      </p>
    </div>

    <p v-if="checkingGate" class="text-sm text-slate-500">Comprobando acceso…</p>

    <div
      v-else-if="gateLocked"
      class="mx-auto max-w-md rounded-xl border border-slate-700/80 bg-[#111723] p-5 space-y-4"
    >
      <h2 class="text-lg font-semibold text-white">Acceso a configuración de correo</h2>
      <p class="text-sm text-slate-400">Por seguridad, confirme su contraseña de usuario para ver y editar remitente, textos y PDFs.</p>
      <p v-if="gateError" class="rounded-lg border border-rose-500/40 bg-rose-900/20 px-3 py-2 text-sm text-rose-200">{{ gateError }}</p>
      <label class="block text-sm">
        <span class="text-slate-400">Contraseña actual</span>
        <input
          :key="'gate-pw-' + gateInputKey"
          v-model="gatePassword"
          type="password"
          autocomplete="current-password"
          class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
          @keydown.enter.prevent="submitGate"
        />
      </label>
      <button
        type="button"
        class="min-h-[44px] w-full rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-sky-500 disabled:opacity-50"
        :disabled="gateSubmitting"
        @click="submitGate"
      >
        {{ gateSubmitting ? 'Comprobando…' : 'Acceder' }}
      </button>
    </div>

    <template v-else>
    <p v-if="loading" class="text-sm text-slate-500">Cargando…</p>
    <p v-else-if="error" class="rounded-lg border border-rose-500/40 bg-rose-900/20 px-3 py-2 text-sm text-rose-200">{{ error }}</p>
    <p v-if="toast" class="rounded-lg border border-emerald-600/40 bg-emerald-900/20 px-3 py-2 text-sm text-emerald-100">{{ toast }}</p>

    <div v-if="!loading" class="rounded-xl border border-slate-700/80 bg-[#111723] p-3 sm:p-4 space-y-3">
      <div class="rounded-lg border border-sky-700/50 bg-slate-900/25 overflow-hidden">
        <div
          class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-700/50 px-3 py-2.5"
        >
          <h3 class="text-sm font-medium text-white">Correo Gmail (SMTP)</h3>
          <span
            v-if="panelSmtpReady"
            class="inline-flex items-center rounded-full border border-emerald-600/50 bg-emerald-950/40 px-2.5 py-0.5 text-xs font-medium text-emerald-200"
          >
            Conectado
          </span>
        </div>
        <div class="px-3 pb-3 pt-3 space-y-3">
          <template v-if="panelSmtpReady">
            <div
              class="rounded-lg border border-emerald-700/45 bg-emerald-950/20 px-3 py-3 space-y-2"
              role="status"
            >
              <p class="text-sm font-medium text-emerald-100">Sesión SMTP configurada</p>
              <p class="text-xs leading-relaxed text-slate-400">
                El envío usa credenciales guardadas de forma cifrada en el servidor. Use «Reconfigurar» para cambiar usuario, contraseña de aplicación o remitente.
              </p>
              <p v-if="SHOW_MAIL_TEST_SECTION" class="text-xs text-slate-500">
                Use «Enviar prueba» más abajo para comprobar el envío.
              </p>
            </div>
            <button
              type="button"
              class="min-h-[40px] rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700"
              @click="openGmailModal"
            >
              Reconfigurar cuenta de correo
            </button>
          </template>
          <template v-else>
            <p v-if="helpGmailSmtp" class="text-xs leading-relaxed text-slate-500">{{ helpGmailSmtp }}</p>
            <p class="text-sm text-slate-400">
              Conecte una cuenta Gmail con contraseña de aplicación para enviar bienvenidas, facturas y avisos desde el panel.
            </p>
            <button
              type="button"
              class="min-h-[44px] rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-sky-500"
              @click="openGmailModal"
            >
              Conectar Gmail (SMTP)
            </button>
          </template>
        </div>
      </div>

      <div
        v-if="SHOW_MAIL_TEST_SECTION"
        class="rounded-lg border border-amber-600/40 bg-amber-950/20 px-3 py-3 space-y-3"
        role="region"
        aria-label="Correo de prueba"
      >
        <h3 class="text-sm font-medium text-amber-100/95">Correo de prueba</h3>
        <p class="text-xs leading-relaxed text-slate-400">
          Usa el SMTP guardado (modo Conectado) o el .env si no hay panel. Asunto «Prueba HBM», cuerpo «Hola». Tras reconfigurar Gmail, guarde antes de probar.
        </p>
        <button
          type="button"
          class="min-h-[44px] rounded-lg border border-amber-500/60 bg-amber-900/40 px-4 py-2.5 text-sm font-medium text-amber-50 hover:bg-amber-800/50 disabled:opacity-50"
          :disabled="testSendSubmitting"
          @click="sendTestNotification"
        >
          {{ testSendSubmitting ? 'Enviando…' : 'Enviar prueba' }}
        </button>
      </div>

      <details class="rounded-lg border border-slate-700/55 bg-slate-900/25 px-3 py-2">
        <summary
          class="cursor-pointer list-none text-sm font-medium text-sky-200 outline-none marker:content-none [&::-webkit-details-marker]:hidden focus-visible:ring-2 focus-visible:ring-sky-500/60 rounded-lg"
        >
          <span class="inline-block align-middle">›</span>
          Cómo hacer tu plantilla
        </summary>
        <div class="mt-3 space-y-3 border-t border-slate-700/50 pt-3 text-xs leading-relaxed text-slate-400">
          <p>
            Escriba en <strong class="text-slate-300">texto plano</strong> (sin HTML). Los saltos de línea del cuadro se respetan en el correo.
          </p>
          <p>
            <span v-pre class="font-mono text-slate-300">{{nombre_sistema}}</span>
            — en <strong class="text-slate-300">bienvenida, factura y mantenimiento</strong>: se sustituye por el
            <strong class="text-slate-300">nombre comercial</strong>
            o, si no hay, la
            <strong class="text-slate-300">razón social</strong>
            de
            <RouterLink to="/admin/configuracion/empresa-sistema" class="text-sky-400 hover:underline">Empresa sistema</RouterLink>.
            Si esos datos están vacíos, se usa
            <code class="rounded bg-slate-800 px-1">APP_NAME</code>
            del servidor.
          </p>
          <p>
            <span v-pre class="font-mono text-slate-300">{{nombre_empresa}}</span>
            — nombre de la <strong class="text-slate-300">empresa cliente</strong> del directorio (la que recibe el correo).
          </p>
          <p class="font-medium text-slate-300">Otros placeholders (según plantilla)</p>
          <ul class="list-disc space-y-1 pl-5">
            <li v-pre><span class="font-mono text-slate-300">{{codigo_factura}}</span> — código de la factura</li>
            <li v-pre><span class="font-mono text-slate-300">{{mes_facturado}}</span> — mes y año del periodo (texto)</li>
            <li v-pre><span class="font-mono text-slate-300">{{periodo_facturado}}</span> — periodo corto (ej. 01/2026)</li>
            <li v-pre>
              <span class="font-mono text-slate-300">{{enlace_consulta_factura}}</span> — enlace público de consulta (en HTML sale como enlace clicable)
            </li>
            <li v-pre><span class="font-mono text-slate-300">{{nombre_equipo}}</span> — equipo de inventario</li>
            <li v-pre><span class="font-mono text-slate-300">{{codigo_servicio}}</span> — referencia del servicio</li>
            <li v-pre><span class="font-mono text-slate-300">{{tipo_servicio}}</span> — tipo de servicio</li>
            <li v-pre><span class="font-mono text-slate-300">{{fecha_mantenimiento}}</span> — fecha registrada</li>
            <li v-pre><span class="font-mono text-slate-300">{{descripcion}}</span> — detalle del trabajo</li>
          </ul>
          <p>
            La URL base del enlace de consulta la define
            <code class="rounded bg-slate-800 px-1">FRONTEND_PUBLIC_URL</code>
            en el servidor (.env).
          </p>
          <p v-pre class="text-slate-500">
            Escriba los placeholders exactamente como se muestran (doble llave, sin espacios dentro: {{nombre_empresa}}, no
            {{ nombre_empresa }}).
          </p>
        </div>
      </details>

      <!-- Bienvenida -->
      <details class="group rounded-lg border border-slate-700/55 bg-slate-900/25 open:border-slate-600/55">
        <summary
          class="flex cursor-pointer list-none items-center gap-2 px-3 py-2.5 text-sm font-medium text-white outline-none marker:content-none [&::-webkit-details-marker]:hidden focus-visible:ring-2 focus-visible:ring-sky-500/60 rounded-lg"
        >
          <span class="inline-block text-slate-400 transition-transform duration-150 group-open:rotate-90" aria-hidden="true">›</span>
          <span class="min-w-0 text-left">
            1. Bienvenida (nueva empresa)
            <span v-if="companyWelcomePdfConfigured" class="ml-1 font-normal text-emerald-400/90">· PDF</span>
          </span>
        </summary>
        <div class="space-y-3 border-t border-slate-700/50 px-3 pb-3 pt-3">
          <p v-if="helpWelcomePdf" class="text-xs text-slate-500">{{ helpWelcomePdf }}</p>
          <p class="text-xs text-slate-500">
            El valor de
            <span v-pre class="whitespace-nowrap">{{nombre_sistema}}</span>
            en todas las plantillas (bienvenida, factura, mantenimiento) sale de
            <RouterLink to="/admin/configuracion/empresa-sistema" class="text-sky-400 hover:underline">Empresa sistema</RouterLink>
            (nombre comercial o razón social); si están vacíos, de APP_NAME.
          </p>
          <label class="block text-sm">
            <span class="text-slate-400">Asunto</span>
            <input
              v-model="welcomeSubject"
              type="text"
              maxlength="200"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <label class="block text-sm">
            <span v-pre class="text-slate-400">Cuerpo ({{nombre_empresa}}, {{nombre_sistema}})</span>
            <textarea
              v-model="welcomeBody"
              rows="6"
              maxlength="8000"
              class="mt-1 w-full resize-y rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-sm text-white"
            />
          </label>
          <div class="rounded-lg border border-slate-700/50 bg-slate-900/30 px-3 py-2 text-sm text-slate-300">
            <template v-if="companyWelcomePdfConfigured">
              PDF: <span class="font-medium text-white">{{ companyWelcomePdfFilename || 'documento.pdf' }}</span>
            </template>
            <span v-else class="text-slate-500">Sin PDF de condiciones: el correo de bienvenida se envía solo con texto.</span>
          </div>
          <input
            ref="welcomePdfInput"
            type="file"
            accept="application/pdf,.pdf"
            class="sr-only"
            @change="onTemplatePdfSelected($event, 'welcome')"
          />
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="min-h-[40px] rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700 disabled:opacity-50"
              :disabled="uploadingKind !== null"
              @click="pickWelcomePdf"
            >
              {{ uploadingKind === 'welcome' ? 'Subiendo…' : companyWelcomePdfConfigured ? 'Reemplazar PDF' : 'Subir PDF' }}
            </button>
            <button
              v-if="companyWelcomePdfConfigured"
              type="button"
              class="min-h-[40px] rounded-lg border border-rose-600/50 bg-rose-950/40 px-3 py-2 text-sm text-rose-100 hover:bg-rose-900/50 disabled:opacity-50"
              :disabled="deletingKind !== null"
              @click="removeTemplatePdf('welcome')"
            >
              {{ deletingKind === 'welcome' ? '…' : 'Quitar PDF' }}
            </button>
          </div>
        </div>
      </details>

      <!-- Factura -->
      <details class="group rounded-lg border border-slate-700/55 bg-slate-900/25 open:border-slate-600/55">
        <summary
          class="flex cursor-pointer list-none items-center gap-2 px-3 py-2.5 text-sm font-medium text-white outline-none marker:content-none [&::-webkit-details-marker]:hidden focus-visible:ring-2 focus-visible:ring-sky-500/60 rounded-lg"
        >
          <span class="inline-block text-slate-400 transition-transform duration-150 group-open:rotate-90" aria-hidden="true">›</span>
          <span class="min-w-0 text-left">
            2. Factura enviada a la empresa
            <span v-if="invoiceSupplementPdfConfigured" class="ml-1 font-normal text-emerald-400/90">· PDF extra</span>
          </span>
        </summary>
        <div class="space-y-3 border-t border-slate-700/50 px-3 pb-3 pt-3">
          <p v-if="helpInvoicePdf" class="text-xs text-slate-500">{{ helpInvoicePdf }}</p>
          <p class="text-xs text-slate-500">
            Al enviar desde el panel se adjunta siempre el PDF generado de la factura. Puede añadir aquí un segundo PDF (p. ej. anexo).
          </p>
          <label class="block text-sm">
            <span v-pre class="text-slate-400">Asunto (use {{codigo_factura}}, {{mes_facturado}}, …)</span>
            <input
              v-model="invoiceToCompanySubject"
              type="text"
              maxlength="200"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <label class="block text-sm">
            <span v-pre class="text-slate-400">Cuerpo (incluye {{enlace_consulta_factura}} para el enlace público)</span>
            <textarea
              v-model="invoiceToCompanyBody"
              rows="7"
              maxlength="8000"
              class="mt-1 w-full resize-y rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-sm text-white"
            />
          </label>
          <div class="rounded-lg border border-slate-700/50 bg-slate-900/30 px-3 py-2 text-sm text-slate-300">
            <template v-if="invoiceSupplementPdfConfigured">
              PDF adicional: <span class="font-medium text-white">{{ invoiceSupplementPdfFilename || 'anexo.pdf' }}</span>
            </template>
            <span v-else class="text-slate-500">Sin PDF adicional (solo el PDF de la factura generado).</span>
          </div>
          <input
            ref="invoicePdfInput"
            type="file"
            accept="application/pdf,.pdf"
            class="sr-only"
            @change="onTemplatePdfSelected($event, 'invoice_supplement')"
          />
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="min-h-[40px] rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700 disabled:opacity-50"
              :disabled="uploadingKind !== null"
              @click="pickInvoicePdf"
            >
              {{ uploadingKind === 'invoice_supplement' ? 'Subiendo…' : invoiceSupplementPdfConfigured ? 'Reemplazar PDF extra' : 'Subir PDF extra' }}
            </button>
            <button
              v-if="invoiceSupplementPdfConfigured"
              type="button"
              class="min-h-[40px] rounded-lg border border-rose-600/50 bg-rose-950/40 px-3 py-2 text-sm text-rose-100 hover:bg-rose-900/50 disabled:opacity-50"
              :disabled="deletingKind !== null"
              @click="removeTemplatePdf('invoice_supplement')"
            >
              {{ deletingKind === 'invoice_supplement' ? '…' : 'Quitar PDF extra' }}
            </button>
          </div>
        </div>
      </details>

      <!-- Mantenimiento -->
      <details class="group rounded-lg border border-slate-700/55 bg-slate-900/25 open:border-slate-600/55">
        <summary
          class="flex cursor-pointer list-none items-center gap-2 px-3 py-2.5 text-sm font-medium text-white outline-none marker:content-none [&::-webkit-details-marker]:hidden focus-visible:ring-2 focus-visible:ring-sky-500/60 rounded-lg"
        >
          <span class="inline-block text-slate-400 transition-transform duration-150 group-open:rotate-90" aria-hidden="true">›</span>
          <span class="min-w-0 text-left">
            3. Mantenimiento (al registrar servicio al equipo)
            <span v-if="maintenanceSupplementPdfConfigured" class="ml-1 font-normal text-emerald-400/90">· PDF</span>
          </span>
        </summary>
        <div class="space-y-3 border-t border-slate-700/50 px-3 pb-3 pt-3">
          <p v-if="helpMaintenancePdf" class="text-xs text-slate-500">{{ helpMaintenancePdf }}</p>
          <p class="text-xs text-slate-500">
            Se envía al correo de la empresa del servicio cuando el técnico o admin registra un mantenimiento vinculado a un equipo de inventario.
          </p>
          <label class="block text-sm">
            <span class="text-slate-400">Asunto</span>
            <input
              v-model="maintenanceSubject"
              type="text"
              maxlength="200"
              class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
            />
          </label>
          <label class="block text-sm">
            <span class="text-slate-400">Cuerpo (equipo, empresa, código de servicio, etc.)</span>
            <textarea
              v-model="maintenanceBody"
              rows="7"
              maxlength="8000"
              class="mt-1 w-full resize-y rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-sm text-white"
            />
          </label>
          <div class="rounded-lg border border-slate-700/50 bg-slate-900/30 px-3 py-2 text-sm text-slate-300">
            <template v-if="maintenanceSupplementPdfConfigured">
              PDF: <span class="font-medium text-white">{{ maintenanceSupplementPdfFilename || 'documento.pdf' }}</span>
            </template>
            <span v-else class="text-slate-500">Sin PDF complementario.</span>
          </div>
          <input
            ref="maintenancePdfInput"
            type="file"
            accept="application/pdf,.pdf"
            class="sr-only"
            @change="onTemplatePdfSelected($event, 'maintenance_supplement')"
          />
          <div class="flex flex-wrap gap-2">
            <button
              type="button"
              class="min-h-[40px] rounded-lg border border-slate-600 bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700 disabled:opacity-50"
              :disabled="uploadingKind !== null"
              @click="pickMaintenancePdf"
            >
              {{ uploadingKind === 'maintenance_supplement' ? 'Subiendo…' : maintenanceSupplementPdfConfigured ? 'Reemplazar PDF' : 'Subir PDF' }}
            </button>
            <button
              v-if="maintenanceSupplementPdfConfigured"
              type="button"
              class="min-h-[40px] rounded-lg border border-rose-600/50 bg-rose-950/40 px-3 py-2 text-sm text-rose-100 hover:bg-rose-900/50 disabled:opacity-50"
              :disabled="deletingKind !== null"
              @click="removeTemplatePdf('maintenance_supplement')"
            >
              {{ deletingKind === 'maintenance_supplement' ? '…' : 'Quitar PDF' }}
            </button>
          </div>
        </div>
      </details>

      <div class="rounded-lg border border-slate-700/60 bg-slate-900/20 px-3 py-3">
        <button
          type="button"
          class="min-h-[44px] w-full rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-sky-500 disabled:opacity-50 sm:w-auto"
          :disabled="saving"
          @click="saveTemplatesOnly"
        >
          {{ saving ? 'Guardando…' : 'Guardar plantillas' }}
        </button>
        <p class="mt-2 text-xs text-slate-500">
          La conexión Gmail se configura aparte con «Conectar Gmail» o «Reconfigurar».
        </p>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="gmailModalOpen"
        class="fixed inset-0 z-[80] flex items-center justify-center bg-black/60 p-4"
        role="dialog"
        aria-modal="true"
        aria-labelledby="gmail-modal-title"
        @click.self="closeGmailModal"
      >
        <div
          class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-xl border border-slate-600 bg-[#111723] shadow-xl"
          @click.stop
        >
          <div class="border-b border-slate-700/50 px-4 py-3">
            <h2 id="gmail-modal-title" class="text-base font-semibold text-white">Correo Gmail (SMTP)</h2>
            <p class="mt-1 text-xs text-slate-400">Credenciales cifradas en el servidor. No se muestran tras guardar.</p>
          </div>
          <div class="space-y-3 px-4 py-3">
            <details class="rounded-lg border border-sky-700/40 bg-slate-900/40 px-3 py-2">
              <summary class="cursor-pointer text-sm font-medium text-sky-200">Cómo conectar y obtener contraseña de aplicación</summary>
              <ol class="mt-2 list-decimal space-y-2 pl-5 text-xs leading-relaxed text-slate-400">
                <li>
                  Use una cuenta Gmail con
                  <strong class="text-slate-300">verificación en dos pasos</strong>
                  activada (Seguridad de Google).
                </li>
                <li>
                  En Google: cuenta → Seguridad → «Contraseñas de aplicaciones» (o busque «App passwords»). Cree una contraseña de 16 caracteres para «Correo» y «Otro (HBM)».
                </li>
                <li>
                  Copie la clave
                  <strong class="text-slate-300">sin espacios</strong>
                  y péguela aquí como «Contraseña de aplicación». No use su contraseña normal de Gmail.
                </li>
                <li>
                  Usuario SMTP = su correo completo (@gmail.com). Servidor
                  <code class="rounded bg-slate-800 px-1">smtp.gmail.com</code>
                  , puerto
                  <code class="rounded bg-slate-800 px-1">587</code>
                  , cifrado
                  <code class="rounded bg-slate-800 px-1">tls</code>
                  .
                </li>
                <li>El «Correo remitente» debe ser esa misma cuenta o un alias verificado en Google.</li>
              </ol>
            </details>

            <div class="grid gap-3 sm:grid-cols-2">
              <label class="block text-sm sm:col-span-2">
                <span class="text-slate-400">Servidor SMTP</span>
                <input
                  v-model="smtpHost"
                  type="text"
                  class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
                  placeholder="smtp.gmail.com"
                />
              </label>
              <label class="block text-sm">
                <span class="text-slate-400">Puerto</span>
                <input
                  v-model.number="smtpPort"
                  type="number"
                  min="1"
                  max="65535"
                  class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
                />
              </label>
              <label class="block text-sm">
                <span class="text-slate-400">Cifrado</span>
                <select v-model="smtpEncryption" class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white">
                  <option value="tls">tls / STARTTLS (587)</option>
                  <option value="starttls">starttls</option>
                  <option value="ssl">ssl (465)</option>
                </select>
              </label>
              <label class="block text-sm sm:col-span-2">
                <span class="text-slate-400">Usuario SMTP (correo Gmail)</span>
                <input
                  v-model="smtpUsername"
                  type="email"
                  autocomplete="username"
                  class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
                  placeholder="su cuenta@gmail.com"
                />
              </label>
              <label class="block text-sm sm:col-span-2">
                <span class="text-slate-400">Contraseña de aplicación (Google)</span>
                <input
                  v-model="smtpPassword"
                  type="password"
                  autocomplete="new-password"
                  class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
                />
              </label>
            </div>
            <label class="inline-flex items-center gap-2 text-xs text-slate-400">
              <input v-model="clearSmtpPassword" type="checkbox" class="h-4 w-4 rounded border-slate-500 bg-slate-900 text-sky-500" />
              Borrar contraseña guardada ({{ hasSmtpPassword ? 'hay valor' : 'vacía' }})
            </label>
            <div class="border-t border-slate-700/40 pt-3 space-y-3">
              <p class="text-xs font-medium text-slate-400">Remitente visible (From)</p>
              <p class="text-xs text-slate-500">Debe coincidir con el correo Gmail o un alias verificado en Google.</p>
              <label class="block text-sm">
                <span class="text-slate-400">Correo remitente</span>
                <input
                  v-model="fromAddress"
                  type="email"
                  autocomplete="off"
                  class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
                />
              </label>
              <label class="block text-sm">
                <span class="text-slate-400">Nombre visible</span>
                <input
                  v-model="fromName"
                  type="text"
                  maxlength="120"
                  class="mt-1 w-full rounded-lg border border-slate-600 bg-[#13161f] px-3 py-2 text-white"
                  placeholder="Ej. Facturación HBM"
                />
              </label>
            </div>
          </div>
          <div class="flex flex-wrap justify-end gap-2 border-t border-slate-700/50 px-4 py-3">
            <button
              type="button"
              class="min-h-[40px] rounded-lg border border-slate-600 bg-slate-800 px-4 py-2 text-sm text-slate-200 hover:bg-slate-700"
              :disabled="gmailModalSaving"
              @click="closeGmailModal"
            >
              Cerrar
            </button>
            <button
              type="button"
              class="min-h-[40px] rounded-lg bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-500 disabled:opacity-50"
              :disabled="gmailModalSaving"
              @click="saveGmailFromModal"
            >
              {{ gmailModalSaving ? 'Guardando…' : 'Guardar conexión' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
    </template>
  </section>
</template>
