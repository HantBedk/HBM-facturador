<script setup>
import { ref, computed } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { isAdminPanelRole } from '@/utils/roles.js'
import { requestForgotPassword } from '@/services/authPasswordApi.js'
import loginBackground from '@/assets/Login.png'

const router = useRoute()
const nav = useRouter()
const auth = useAuthStore()

const correo = ref('')
const password = ref('')
/** Refs al input nativo: el autocompletado del navegador a veces no dispara eventos y v-model queda vacío. */
const correoInputEl = ref(null)
const passwordInputEl = ref(null)
const remember = ref(true)
const loading = ref(false)
const fieldErrors = ref({})
const globalError = ref('')

const forgotOpen = ref(false)
const forgotDoc = ref('')
const forgotCorreo = ref('')
const forgotLoading = ref(false)
const forgotError = ref('')
const forgotSuccess = ref('')

const redirectTarget = computed(() => {
  const r = router.query.redirect
  return typeof r === 'string' ? r : null
})

/** Misma regla mínima que el backend (AuthController): local@dominio, Unicode con /u. */
const emailPattern = /^[^\s@]+@[^\s@]+$/u

/**
 * Valores reales del formulario (no confiar solo en v-model: autocompletado / extensiones
 * rellenan el DOM sin disparar actualización de Vue).
 */
function readCredentialsFromForm(form) {
  if (!(form instanceof HTMLFormElement)) return null
  const emailEl = form.querySelector('#correo')
  const passEl = form.querySelector('#password')
  const c = emailEl && typeof emailEl.value === 'string' ? emailEl.value.trim() : ''
  const p = passEl && typeof passEl.value === 'string' ? passEl.value : ''
  return { c, p }
}

function syncLoginFieldsFromDom() {
  const form = correoInputEl.value?.form ?? passwordInputEl.value?.form
  const fromForm = readCredentialsFromForm(form)
  if (fromForm) {
    correo.value = fromForm.c
    password.value = fromForm.p
    return
  }
  const emailNode = correoInputEl.value
  const passNode = passwordInputEl.value
  if (emailNode && typeof emailNode.value === 'string') correo.value = emailNode.value.trim()
  if (passNode && typeof passNode.value === 'string') password.value = passNode.value
}

function validateLocal() {
  fieldErrors.value = {}
  globalError.value = ''

  const c = correo.value.trim()
  if (!c) {
    fieldErrors.value = { correo: ['El correo electrónico es obligatorio.'] }
    return false
  }
  if (!emailPattern.test(c)) {
    fieldErrors.value = { correo: ['Introduce un correo electrónico válido.'] }
    return false
  }
  if (!password.value) {
    fieldErrors.value = { password: ['La contraseña es obligatoria.'] }
    return false
  }
  return true
}

function mapServerError(e) {
  if (e?.status === 429) {
    globalError.value =
      'Demasiados intentos. Espere un minuto y vuelva a intentar o reinicie el servidor si está en desarrollo.'
    return
  }
  if (e?.data?.errors) {
    const err = { ...e.data.errors }
    if (err.correo?.length) {
      err.correo = err.correo.map((m) => {
        const s = String(m)
        if (s.includes('incorrectos')) return 'Correo o contraseña incorrectos.'
        if (/formato|válid|valid|regex|correo electr/i.test(s)) {
          return 'Revisa el formato del correo (ej. usuario@empresa.com o usuario@hbm.local).'
        }
        return m
      })
    }
    if (err.password?.length) {
      err.password = err.password.map((m) =>
        String(m).toLowerCase().includes('obligator') || String(m).includes('requerid')
          ? 'La contraseña es obligatoria.'
          : m
      )
    }
    fieldErrors.value = err
    return
  }
  if (e?.status === 403 && e?.data?.message) {
    globalError.value = e.data.message
    return
  }
  if (e?.data?.message) {
    globalError.value = e.data.message
    return
  }
  // Red, CORS o respuesta sin JSON: el mensaje útil suele estar en Error.message, no en data
  if (e?.message && String(e.message).trim() !== '') {
    globalError.value = e.message
    return
  }
  globalError.value = 'No se pudo iniciar sesión. Intenta de nuevo.'
}

async function onSubmit(e) {
  const form = e?.target instanceof HTMLFormElement ? e.target : null
  const creds = readCredentialsFromForm(form)
  if (creds) {
    correo.value = creds.c
    password.value = creds.p
  } else {
    syncLoginFieldsFromDom()
  }
  if (!validateLocal()) return

  const correoEnviar = correo.value.trim()
  const passwordEnviar = password.value

  globalError.value = ''
  fieldErrors.value = {}
  loading.value = true

  try {
    const user = await auth.login(
      {
        correo: correoEnviar,
        password: passwordEnviar,
        device_name: 'web',
      },
      { remember: remember.value }
    )
    if (redirectTarget.value) {
      await nav.replace(redirectTarget.value)
      return
    }
    await nav.replace(isAdminPanelRole(user.rol) ? '/admin' : '/empleado')
  } catch (e) {
    mapServerError(e)
  } finally {
    loading.value = false
  }
}

function openForgot() {
  forgotOpen.value = true
  forgotError.value = ''
  forgotSuccess.value = ''
  forgotDoc.value = ''
  forgotCorreo.value = correo.value.trim()
}

function closeForgot() {
  forgotOpen.value = false
  forgotError.value = ''
  forgotSuccess.value = ''
}

async function submitForgot() {
  forgotError.value = ''
  forgotSuccess.value = ''
  const c = forgotCorreo.value.trim()
  const d = forgotDoc.value.trim()
  if (!c) {
    forgotError.value = 'Indique el correo electrónico.'
    return
  }
  if (!emailPattern.test(c)) {
    forgotError.value = 'Introduce un correo electrónico válido.'
    return
  }
  if (!d) {
    forgotError.value = 'Indique el número de cédula o documento.'
    return
  }
  forgotLoading.value = true
  try {
    const res = await requestForgotPassword({ correo: c, numero_documento: d })
    forgotSuccess.value = res?.message || 'Solicitud registrada.'
  } catch (e) {
    forgotError.value = e.data?.message || e.message || 'No se pudo enviar la solicitud.'
  } finally {
    forgotLoading.value = false
  }
}

</script>

<template>
  <div
    class="relative flex min-h-screen items-center justify-center overflow-hidden bg-gradient-to-r from-[#0b1120] via-[#0f172a] to-[#1e1b4b] px-4 py-10 font-sans text-white"
  >
    <!--
      Imagen desde src/assets: Vite añade hash al nombre en build y evita caché del navegador
      al reemplazar Login.png. Actualiza el archivo en: frontend/src/assets/Login.png
    -->
    <div
      class="pointer-events-none absolute inset-0 bg-cover bg-center bg-no-repeat"
      :style="{ backgroundImage: `url(${loginBackground})` }"
      aria-hidden="true"
    />
    <div
      class="pointer-events-none absolute inset-0 bg-gradient-to-r from-[#0b1120]/85 via-[#0f172a]/70 to-[#1e1b4b]/80"
      aria-hidden="true"
    />

    <div class="relative z-10 w-full max-w-[420px]">
      <div
        class="rounded-[20px] border border-sky-400/35 bg-[#0c1222]/75 p-8 shadow-[0_0_0_1px_rgba(56,189,248,0.12),0_24px_64px_rgba(0,0,0,0.45)] backdrop-blur-xl backdrop-saturate-125"
      >
        <header class="mb-8 text-center">
          <div class="mb-5 flex items-center justify-center gap-3">
            <div
              class="brand-icon-ring flex h-12 w-12 shrink-0 items-center justify-center rounded-full border border-cyan-400/35 bg-slate-950/40 shadow-[0_0_22px_rgba(34,211,238,0.5),0_0_40px_rgba(56,189,248,0.15)]"
              aria-hidden="true"
            >
              <svg class="h-7 w-7 text-white" viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="20" cy="20" r="17" stroke="currentColor" stroke-opacity="0.35" stroke-width="1" />
                <path
                  d="M20 7c7.18 0 13 5.82 13 13 0 3.58-1.45 6.82-3.79 9.17"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                />
                <path
                  d="M25.5 12.5a8.5 8.5 0 1 1-12.02 12.02"
                  stroke="currentColor"
                  stroke-width="1.5"
                  stroke-linecap="round"
                  stroke-opacity="0.95"
                />
                <path
                  d="M14.5 27.5a7 7 0 1 0 9.9-9.9"
                  stroke="currentColor"
                  stroke-width="1.2"
                  stroke-linecap="round"
                  stroke-opacity="0.55"
                />
              </svg>
            </div>
            <span
              class="text-[1.35rem] font-extrabold uppercase tracking-[0.28em] text-white drop-shadow-[0_0_14px_rgba(56,189,248,0.55)]"
            >
              HBM
            </span>
          </div>
          <h1
            class="text-[0.8125rem] font-bold leading-snug tracking-[0.14em] text-white uppercase drop-shadow-[0_0_10px_rgba(56,189,248,0.35)]"
          >
            Bienvenido a HBM
          </h1>
        </header>

        <p
          v-if="globalError"
          class="mb-4 rounded-xl border border-red-400/35 bg-red-500/10 px-3 py-2.5 text-center text-xs font-medium text-red-200"
          role="alert"
        >
          {{ globalError }}
        </p>

        <form
          class="flex flex-col gap-5"
          method="post"
          action="#"
          autocomplete="on"
          novalidate
          @submit.prevent="onSubmit"
        >
          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-slate-400">Correo electrónico</span>
            <div
              class="flex items-center gap-2.5 rounded-xl border border-sky-400/45 bg-[#0a0f1c]/90 px-3.5 py-3 transition-colors focus-within:border-sky-400/70 focus-within:ring-1 focus-within:ring-sky-400/30"
            >
              <span class="shrink-0 text-white" aria-hidden="true">
                <svg class="h-[18px] w-[18px] opacity-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z" />
                  <path d="m22 6-10 7L2 6" />
                </svg>
              </span>
              <input
                id="correo"
                name="correo"
                ref="correoInputEl"
                v-model="correo"
                type="text"
                autocomplete="username"
                inputmode="email"
                placeholder="correo@algo.com"
                class="min-w-0 flex-1 border-0 bg-transparent text-[0.9375rem] text-white outline-none ring-0 placeholder:text-slate-500"
                @change="syncLoginFieldsFromDom"
              />
            </div>
            <small v-if="fieldErrors.correo" class="mt-1 block text-xs text-red-300">{{ fieldErrors.correo[0] }}</small>
          </label>

          <label class="block">
            <span class="mb-1.5 block text-xs font-medium text-slate-400">Contraseña</span>
            <div
              class="flex items-center gap-2.5 rounded-xl border border-sky-400/45 bg-[#0a0f1c]/90 px-3.5 py-3 transition-colors focus-within:border-sky-400/70 focus-within:ring-1 focus-within:ring-sky-400/30"
            >
              <span class="shrink-0 text-amber-400" aria-hidden="true">
                <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                  <rect x="5" y="11" width="14" height="10" rx="2" />
                  <path d="M7 11V7a5 5 0 0 1 10 0v4" />
                </svg>
              </span>
              <input
                id="password"
                name="password"
                ref="passwordInputEl"
                v-model="password"
                type="password"
                autocomplete="current-password"
                placeholder="••••••••"
                class="min-w-0 flex-1 border-0 bg-transparent text-[0.9375rem] text-white outline-none ring-0 placeholder:text-slate-500"
                @change="syncLoginFieldsFromDom"
              />
            </div>
            <small v-if="fieldErrors.password" class="mt-1 block text-xs text-red-300">{{
              fieldErrors.password[0]
            }}</small>
          </label>

          <label class="flex cursor-pointer items-center gap-2.5 text-sm text-slate-400 select-none">
            <input
              v-model="remember"
              type="checkbox"
              class="h-4 w-4 rounded border-slate-500 bg-[#0a0f1c] text-violet-500 accent-violet-500 focus:ring-violet-500/40"
            />
            Recordar sesión
          </label>

          <p class="text-sm text-sky-300/75">
            <button type="button" class="forgot-link" @click="openForgot">¿Olvidaste tu contraseña?</button>
          </p>

          <button
            type="submit"
            :disabled="loading"
            class="w-full rounded-2xl bg-gradient-to-r from-blue-500 to-violet-600 py-3.5 text-sm font-bold uppercase tracking-widest text-white shadow-[0_4px_24px_rgba(99,102,241,0.35)] transition hover:from-blue-400 hover:to-violet-500 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {{ loading ? 'Ingresando…' : 'INGRESAR' }}
          </button>
        </form>

        <div class="mt-8 border-t border-white/10 pt-7">
          <p class="text-center text-xs font-medium text-slate-400">
            ¿Cliente? Revisa tus facturas sin cuenta interna.
          </p>
          <RouterLink
            :to="{ name: 'consulta-factura-publica' }"
            class="group mt-4 flex w-full items-center justify-center gap-3 rounded-xl border border-sky-400/25 bg-gradient-to-b from-sky-500/12 to-sky-600/10 px-4 py-3.5 text-[0.9375rem] font-semibold text-sky-100 shadow-[inset_0_1px_0_rgba(255,255,255,0.06),0_8px_32px_rgba(14,165,233,0.08)] backdrop-blur-sm transition hover:border-sky-400/45 hover:from-sky-500/18 hover:to-sky-600/15 hover:text-white hover:shadow-[inset_0_1px_0_rgba(255,255,255,0.1),0_12px_40px_rgba(14,165,233,0.18)]"
          >
            <span
              class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-sky-400/20 bg-sky-400/10 text-sky-300 transition group-hover:border-sky-300/40 group-hover:bg-sky-400/20 group-hover:text-sky-100"
              aria-hidden="true"
            >
              <svg class="h-[18px] w-[18px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <path d="M14 2v6h6M16 13H8M16 17H8M10 9H8" />
              </svg>
            </span>
            <span class="min-w-0 flex-1 text-center">Consultar factura</span>
            <svg
              class="h-4 w-4 shrink-0 text-sky-400/70 transition group-hover:translate-x-0.5 group-hover:text-sky-200"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              aria-hidden="true"
            >
              <path d="M5 12h14M13 6l6 6-6 6" />
            </svg>
          </RouterLink>
        </div>
      </div>
    </div>

    <Teleport to="body">
      <div
        v-if="forgotOpen"
        class="forgot-backdrop"
        role="presentation"
        aria-hidden="true"
        @click.self="closeForgot"
      />
      <div v-if="forgotOpen" class="forgot-dialog-wrap" role="dialog" aria-modal="true" aria-labelledby="forgot-title">
        <div class="forgot-dialog">
          <h2 id="forgot-title" class="forgot-title">Recuperar acceso</h2>
          <p class="forgot-lede">
            Ingrese el <strong>número de documento</strong> (cédula) y el <strong>correo</strong> asociados a su cuenta en HBM.
            Si los datos coinciden con un usuario activo, se enviará una solicitud interna para que un administrador valide su identidad y asigne una nueva contraseña; no recibirá enlace automático por este medio.
            Por seguridad, la respuesta del sistema es siempre la misma, con o sin coincidencia.
          </p>
          <p v-if="forgotError" class="forgot-banner forgot-banner--err" role="alert">{{ forgotError }}</p>
          <p v-if="forgotSuccess" class="forgot-banner forgot-banner--ok">{{ forgotSuccess }}</p>
          <label class="forgot-field">
            <span>Número de cédula / documento</span>
            <input
              v-model="forgotDoc"
              type="text"
              inputmode="numeric"
              autocomplete="off"
              class="forgot-input"
              placeholder="Solo números, sin puntos"
            />
          </label>
          <label class="forgot-field">
            <span>Correo electrónico</span>
            <input v-model="forgotCorreo" type="text" inputmode="email" autocomplete="email" class="forgot-input" />
          </label>
          <div class="forgot-actions">
            <button type="button" class="forgot-btn forgot-btn--ghost" :disabled="forgotLoading" @click="closeForgot">
              Cerrar
            </button>
            <button
              type="button"
              class="forgot-btn forgot-btn--primary"
              :disabled="forgotLoading"
              @click="submitForgot"
            >
              {{ forgotLoading ? 'Enviando…' : 'Enviar solicitud' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style scoped>
/* Evitar que el autocompletado del navegador cambie el color de fondo a blanco/amarillo */
input:-webkit-autofill,
input:-webkit-autofill:hover,
input:-webkit-autofill:focus,
input:-webkit-autofill:active {
  -webkit-box-shadow: 0 0 0 30px transparent inset !important;
  -webkit-text-fill-color: white !important;
  transition: background-color 5000s ease-in-out 0s;
}

.forgot-link {
  margin: 0;
  padding: 0;
  border: none;
  background: none;
  color: inherit;
  font: inherit;
  font-weight: 600;
  text-decoration: underline;
  text-underline-offset: 3px;
  cursor: pointer;
}
.forgot-link:hover {
  color: #e0f2fe;
}

.forgot-backdrop {
  position: fixed;
  inset: 0;
  z-index: 100;
  background: rgba(2, 6, 23, 0.72);
  backdrop-filter: blur(6px);
}

.forgot-dialog-wrap {
  position: fixed;
  inset: 0;
  z-index: 101;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1.25rem;
  pointer-events: none;
}

.forgot-dialog {
  pointer-events: auto;
  width: 100%;
  max-width: 420px;
  border-radius: 18px;
  border: 1px solid rgba(56, 189, 248, 0.35);
  background: linear-gradient(165deg, rgba(15, 23, 42, 0.98), rgba(12, 18, 34, 0.99));
  box-shadow:
    0 0 0 1px rgba(56, 189, 248, 0.12),
    0 24px 64px rgba(0, 0, 0, 0.55);
  padding: 1.35rem 1.5rem 1.5rem;
  color: #e2e8f0;
}

.forgot-title {
  margin: 0 0 0.65rem;
  font-size: 1.1rem;
  font-weight: 800;
  letter-spacing: 0.02em;
  color: #f8fafc;
}

.forgot-lede {
  margin: 0 0 1rem;
  font-size: 0.82rem;
  line-height: 1.5;
  color: #94a3b8;
}

.forgot-banner {
  margin: 0 0 0.85rem;
  padding: 0.55rem 0.65rem;
  border-radius: 10px;
  font-size: 0.8rem;
  line-height: 1.4;
}
.forgot-banner--err {
  border: 1px solid rgba(248, 113, 113, 0.45);
  background: rgba(248, 113, 113, 0.1);
  color: #fecaca;
}
.forgot-banner--ok {
  border: 1px solid rgba(52, 211, 153, 0.4);
  background: rgba(16, 185, 129, 0.1);
  color: #a7f3d0;
}

.forgot-field {
  display: block;
  margin-bottom: 0.85rem;
}
.forgot-field span {
  display: block;
  margin-bottom: 0.35rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: #94a3b8;
}
.forgot-input {
  width: 100%;
  border-radius: 12px;
  border: 1px solid rgba(56, 189, 248, 0.35);
  background: rgba(10, 15, 28, 0.95);
  color: #f8fafc;
  padding: 0.6rem 0.75rem;
  font: inherit;
  font-size: 0.9rem;
  outline: none;
}
.forgot-input:focus {
  border-color: rgba(56, 189, 248, 0.65);
  box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.15);
}

.forgot-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.65rem;
  flex-wrap: wrap;
  margin-top: 0.25rem;
}
.forgot-btn {
  border-radius: 12px;
  padding: 0.55rem 1rem;
  font: inherit;
  font-size: 0.85rem;
  font-weight: 700;
  cursor: pointer;
  border: 1px solid transparent;
}
.forgot-btn:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}
.forgot-btn--ghost {
  background: transparent;
  border-color: rgba(148, 163, 184, 0.35);
  color: #cbd5e1;
}
.forgot-btn--ghost:hover:not(:disabled) {
  background: rgba(148, 163, 184, 0.1);
}
.forgot-btn--primary {
  background: linear-gradient(135deg, #3b82f6, #6366f1);
  color: #fff;
  border: none;
}
.forgot-btn--primary:hover:not(:disabled) {
  filter: brightness(1.06);
}
</style>
