<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { publicApi } from '@/services/api.js'

/** @type {import('vue').Ref<'form' | 'otp' | 'list'>} */
const step = ref('form')
const nit = ref('')
const email = ref('')
const code = ref('')
const accessToken = ref(
  typeof sessionStorage !== 'undefined' ? sessionStorage.getItem('public_company_inv_token') || '' : ''
)
const loading = ref(false)
const errorMessage = ref('')
const lots = ref([])

const hasToken = computed(() => String(accessToken.value || '').trim().length > 0)

function persistToken(t) {
  accessToken.value = t
  try {
    if (t) sessionStorage.setItem('public_company_inv_token', t)
    else sessionStorage.removeItem('public_company_inv_token')
  } catch {
    /* ignore */
  }
}

async function requestOtp() {
  loading.value = true
  errorMessage.value = ''
  try {
    await publicApi('/public/company-inventory/otp/request', {
      method: 'POST',
      body: JSON.stringify({ nit: nit.value.trim(), email: email.value.trim() }),
    })
    step.value = 'otp'
  } catch (e) {
    errorMessage.value = e.message || 'No se pudo solicitar el código.'
  } finally {
    loading.value = false
  }
}

async function verifyOtp() {
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await publicApi('/public/company-inventory/otp/verify', {
      method: 'POST',
      body: JSON.stringify({
        nit: nit.value.trim(),
        email: email.value.trim(),
        code: String(code.value).trim(),
      }),
    })
    const t = res?.access_token
    if (!t) throw new Error('Respuesta inválida del servidor.')
    persistToken(t)
    await loadLots()
    step.value = 'list'
  } catch (e) {
    errorMessage.value = e.message || 'Código incorrecto o expirado.'
  } finally {
    loading.value = false
  }
}

async function loadLots() {
  const t = String(accessToken.value || '').trim()
  if (!t) return
  loading.value = true
  errorMessage.value = ''
  try {
    const res = await publicApi('/public/company-inventory/lots', {
      headers: { Authorization: `Bearer ${t}` },
    })
    lots.value = res?.data || []
  } catch (e) {
    errorMessage.value = e.message || 'No se pudo cargar el inventario.'
    if (e.status === 401) {
      persistToken('')
      step.value = 'form'
    }
  } finally {
    loading.value = false
  }
}

function lifecycleLabel(s) {
  const x = String(s || 'activo')
  if (x === 'baja') return 'Dado de baja'
  if (x === 'vendido') return 'Vendido'
  if (x === 'reparacion') return 'En reparación'
  return 'Activo'
}

function cerrarSesion() {
  persistToken('')
  step.value = 'form'
  lots.value = []
  code.value = ''
}

onMounted(async () => {
  if (hasToken.value) {
    step.value = 'list'
    await loadLots()
  }
})
</script>

<template>
  <div class="page">
    <div class="bg" aria-hidden="true" />
    <div class="wrap">
      <header class="hdr">
        <p class="kicker">Consulta segura</p>
        <h1>Inventario por empresa</h1>
        <p class="lead">
          Ingrese el NIT y el correo corporativo registrado en HBM. Recibirá un código para ver el estado de sus activos
          (sin precios).
        </p>
      </header>

      <section v-if="step === 'form'" class="card">
        <label class="lbl"> NIT </label>
        <input v-model="nit" type="text" class="inp" autocomplete="organization" placeholder="Ej. 901.234.567-8" />
        <label class="lbl mt-3"> Correo electrónico </label>
        <input v-model="email" type="email" class="inp" autocomplete="email" placeholder="correo@empresa.com" />
        <p v-if="errorMessage" class="err">{{ errorMessage }}</p>
        <button type="button" class="btn primary mt-4 w-full" :disabled="loading || !nit.trim() || !email.trim()" @click="requestOtp">
          {{ loading ? 'Enviando…' : 'Enviar código' }}
        </button>
      </section>

      <section v-else-if="step === 'otp'" class="card">
        <p class="muted text-sm">Revise su bandeja (y spam). Introduzca el código de 6 dígitos.</p>
        <label class="lbl mt-2"> Código </label>
        <input v-model="code" type="text" maxlength="6" inputmode="numeric" class="inp mono" placeholder="______" />
        <p v-if="errorMessage" class="err">{{ errorMessage }}</p>
        <button type="button" class="btn primary mt-4 w-full" :disabled="loading || code.trim().length !== 6" @click="verifyOtp">
          {{ loading ? 'Verificando…' : 'Consultar inventario' }}
        </button>
        <button type="button" class="btn ghost mt-2 w-full" @click="step = 'form'">Volver</button>
      </section>

      <section v-else class="card">
        <div class="flex items-center justify-between gap-2">
          <h2 class="m-0 text-lg font-semibold text-white">Activos</h2>
          <button type="button" class="btn ghost btn-sm" @click="cerrarSesion">Salir</button>
        </div>
        <p v-if="errorMessage" class="err">{{ errorMessage }}</p>
        <div v-if="loading" class="muted py-6 text-center">Cargando…</div>
        <div v-else-if="!lots.length" class="muted py-6 text-center">No hay equipos registrados para esta empresa.</div>
        <ul v-else class="mt-3 divide-y divide-slate-700/80 rounded-xl border border-slate-700/60 bg-[#0f141c]">
          <li v-for="row in lots" :key="row.id" class="px-3 py-3 text-sm">
            <p class="m-0 font-medium text-white">{{ row.name }}</p>
            <p class="mt-1 text-xs text-slate-400">
              Código: {{ row.sku || '—' }} · Cantidad: {{ row.quantity_available }} · Estado:
              {{ lifecycleLabel(row.lifecycle_status) }}
            </p>
          </li>
        </ul>
      </section>

      <p class="foot">
        <RouterLink to="/consulta-factura" class="link">Consultar factura</RouterLink>
        <span class="mx-2 text-slate-600">·</span>
        <RouterLink to="/login" class="link">Acceso interno</RouterLink>
      </p>
    </div>
  </div>
</template>

<style scoped>
.page {
  min-height: 100vh;
  position: relative;
  color: #e8f1ff;
  padding: 2rem 1rem 3rem;
}
.bg {
  position: fixed;
  inset: 0;
  background: linear-gradient(165deg, #0a0e18 0%, #0f172a 40%, #0c1222 100%);
  z-index: 0;
}
.wrap {
  position: relative;
  z-index: 1;
  max-width: 520px;
  margin: 0 auto;
}
.hdr .kicker {
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #38bdf8;
  margin: 0 0 0.35rem;
}
.hdr h1 {
  font-size: 1.5rem;
  font-weight: 700;
  margin: 0 0 0.5rem;
}
.lead {
  margin: 0 0 1.25rem;
  font-size: 0.9rem;
  color: #94a3b8;
  line-height: 1.45;
}
.card {
  border-radius: 1rem;
  border: 1px solid rgba(51, 65, 85, 0.55);
  background: rgba(15, 23, 42, 0.55);
  padding: 1.25rem 1.35rem;
}
.lbl {
  display: block;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: #94a3b8;
}
.inp {
  margin-top: 0.35rem;
  width: 100%;
  border-radius: 0.75rem;
  border: 1px solid rgba(51, 65, 85, 0.9);
  background: #0b1220;
  padding: 0.65rem 0.85rem;
  color: #f8fafc;
  outline: none;
}
.inp:focus {
  border-color: rgba(56, 189, 248, 0.65);
  box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.15);
}
.inp.mono {
  letter-spacing: 0.25em;
  font-variant-numeric: tabular-nums;
}
.btn {
  border-radius: 0.85rem;
  padding: 0.65rem 1rem;
  font-weight: 600;
  border: 1px solid transparent;
  cursor: pointer;
}
.btn.primary {
  background: linear-gradient(90deg, #0ea5e9, #2563eb);
  color: white;
}
.btn.primary:disabled {
  opacity: 0.45;
  cursor: not-allowed;
}
.btn.ghost {
  background: transparent;
  border-color: rgba(71, 85, 105, 0.85);
  color: #cbd5e1;
}
.btn-sm {
  padding: 0.35rem 0.65rem;
  font-size: 0.8rem;
}
.err {
  margin-top: 0.75rem;
  font-size: 0.85rem;
  color: #fca5a5;
}
.muted {
  color: #64748b;
}
.foot {
  margin-top: 2rem;
  text-align: center;
  font-size: 0.85rem;
}
.link {
  color: #38bdf8;
  text-decoration: none;
}
.link:hover {
  text-decoration: underline;
}
.mt-3 {
  margin-top: 0.75rem;
}
.mt-4 {
  margin-top: 1rem;
}
.w-full {
  width: 100%;
}
.flex {
  display: flex;
}
.m-0 {
  margin: 0;
}
.text-lg {
  font-size: 1.05rem;
}
.font-semibold {
  font-weight: 600;
}
.items-center {
  align-items: center;
}
.justify-between {
  justify-content: space-between;
}
.gap-2 {
  gap: 0.5rem;
}
.text-sm {
  font-size: 0.875rem;
}
.text-xs {
  font-size: 0.75rem;
}
.text-white {
  color: #fff;
}
.py-6 {
  padding-top: 1.5rem;
  padding-bottom: 1.5rem;
}
.text-center {
  text-align: center;
}
.mx-2 {
  margin-left: 0.5rem;
  margin-right: 0.5rem;
}
.text-slate-600 {
  color: #475569;
}
.mt-1 {
  margin-top: 0.25rem;
}
.mt-2 {
  margin-top: 0.5rem;
}
</style>
