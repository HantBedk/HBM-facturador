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

function volverFormulario() {
  step.value = 'form'
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
    <div class="bg no-print" aria-hidden="true" />

    <div class="stack">
      <div class="consulta-hero">
        <section class="search-card" aria-labelledby="titulo-inv">
          <template v-if="step === 'form'">
            <h1 id="titulo-inv" class="search-title">Inventario por empresa</h1>
            <p class="search-sub">
              Ingrese el <strong>NIT</strong> y el <strong>correo corporativo</strong> registrado en HBM. Recibirá un código para ver el estado de sus activos
              <strong>sin precios</strong>.
            </p>

            <div class="inv-fields">
              <label class="search-field">
                <span class="search-label">NIT</span>
                <input v-model="nit" type="text" autocomplete="organization" placeholder="Ej. 901.234.567-8" />
              </label>
              <label class="search-field">
                <span class="search-label">Correo electrónico</span>
                <input v-model="email" type="email" autocomplete="email" placeholder="correo@empresa.com" />
              </label>
            </div>
            <p v-if="errorMessage" class="alert" role="alert">{{ errorMessage }}</p>
            <button
              type="button"
              class="btn-search btn-search--block"
              :disabled="loading || !nit.trim() || !email.trim()"
              @click="requestOtp"
            >
              {{ loading ? 'Enviando…' : 'Enviar código' }}
            </button>
          </template>

          <template v-else-if="step === 'otp'">
            <h1 id="titulo-inv" class="search-title">Verificar código</h1>
            <p class="search-sub">
              Revise su bandeja (y spam). Introduzca el <strong>código de 6 dígitos</strong> enviado al correo indicado.
            </p>

            <div class="inv-fields">
              <label class="search-field">
                <span class="search-label">Código</span>
                <input
                  v-model="code"
                  type="text"
                  maxlength="6"
                  inputmode="numeric"
                  class="mono"
                  autocomplete="one-time-code"
                  placeholder="______"
                />
              </label>
            </div>
            <p v-if="errorMessage" class="alert" role="alert">{{ errorMessage }}</p>
            <button
              type="button"
              class="btn-search btn-search--block"
              :disabled="loading || code.trim().length !== 6"
              @click="verifyOtp"
            >
              {{ loading ? 'Verificando…' : 'Consultar inventario' }}
            </button>
            <button type="button" class="btn-ghost-block" @click="volverFormulario">Volver</button>
          </template>

          <template v-else>
            <h1 id="titulo-inv" class="search-title">Inventario por empresa</h1>
            <p class="search-sub">
              Sesión activa. A continuación aparecen sus equipos <strong>sin precios</strong>. Puede cerrar sesión cuando termine.
            </p>
            <button type="button" class="btn-ghost-block btn-ghost-block--narrow" @click="cerrarSesion">Cerrar sesión</button>
          </template>
        </section>

        <aside class="quick-actions no-print" aria-label="Otros accesos">
          <p class="quick-actions-kicker">Otros accesos</p>
          <RouterLink to="/login" class="quick-btn quick-btn--primary">
            <span class="quick-btn-icon" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4" />
                <polyline points="10 17 15 12 10 7" />
                <line x1="15" y1="12" x2="3" y2="12" />
              </svg>
            </span>
            <span class="quick-btn-body">
              <span class="quick-btn-title">Login</span>
            </span>
          </RouterLink>
          <RouterLink to="/consulta-factura" class="quick-btn quick-btn--primary">
            <span class="quick-btn-icon" aria-hidden="true">
              <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="16" y1="13" x2="8" y2="13" />
                <line x1="16" y1="17" x2="8" y2="17" />
                <polyline points="10 9 9 9 8 9" />
              </svg>
            </span>
            <span class="quick-btn-body">
              <span class="quick-btn-title">Consultar factura</span>
            </span>
          </RouterLink>
        </aside>
      </div>

      <section v-if="step === 'list'" class="detail-card">
        <div class="list-head">
          <h2 class="list-title">Equipos</h2>
        </div>
        <p v-if="errorMessage" class="alert" role="alert">{{ errorMessage }}</p>
        <div v-if="loading" class="muted inv-loading">Cargando…</div>
        <div v-else-if="!lots.length" class="muted inv-empty">No hay equipos registrados para esta empresa.</div>
        <ul v-else class="inv-ul">
          <li v-for="row in lots" :key="row.id" class="inv-li">
            <p class="inv-li-name">{{ row.name }}</p>
            <p class="inv-li-meta">
              Código interno: {{ row.internal_code || '—' }} · Cantidad: {{ row.quantity_available }} · Estado:
              {{ lifecycleLabel(row.lifecycle_status) }}
            </p>
          </li>
        </ul>
      </section>
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

.stack {
  position: relative;
  z-index: 1;
  margin: 0 auto;
  max-width: 1100px;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.consulta-hero {
  display: grid;
  gap: 1.25rem;
  grid-template-columns: 1fr;
  /* Aside arriba: no se estira al alto del card izquierdo, así los botones
     mantienen el mismo tamaño que en /consulta-factura aunque el card crezca. */
  align-items: start;
  width: 100%;
}

@media (min-width: 900px) {
  .consulta-hero {
    grid-template-columns: minmax(0, 3fr) minmax(0, 1fr);
    gap: 1.25rem;
  }
}

.search-card {
  border-radius: 12px;
  padding: 1.25rem 1.35rem 1.35rem;
  background: rgba(19, 26, 43, 0.92);
  border: 1px solid rgba(59, 130, 246, 0.28);
  box-shadow:
    0 0 0 1px rgba(59, 130, 246, 0.08),
    0 12px 40px rgba(0, 0, 0, 0.35);
}

.search-title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 700;
  color: #fff;
  letter-spacing: -0.02em;
}

.search-sub {
  margin: 0.45rem 0 0;
  font-size: 0.8125rem;
  line-height: 1.5;
  color: #94a3b8;
}

.search-sub strong {
  color: #cbd5e1;
  font-weight: 600;
}

.inv-fields {
  margin-top: 1.1rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.search-field {
  display: block;
}

.search-label {
  display: block;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #fff;
  margin-bottom: 0.35rem;
}

.search-field input {
  width: 100%;
  border-radius: 8px;
  border: 1px solid rgba(100, 116, 139, 0.55);
  background: rgba(8, 12, 24, 0.9);
  color: #f1f5f9;
  padding: 0.55rem 0.75rem;
  font: inherit;
  font-size: 0.9rem;
}

.search-field input:focus {
  outline: none;
  border-color: rgba(59, 130, 246, 0.65);
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2);
}

.search-field input.mono {
  letter-spacing: 0.22em;
  font-variant-numeric: tabular-nums;
}

.alert {
  margin: 1rem 0 0;
  padding: 0.65rem 0.75rem;
  border-radius: 8px;
  background: rgba(127, 29, 29, 0.35);
  border: 1px solid rgba(248, 113, 113, 0.35);
  color: #fecaca;
  font-size: 0.875rem;
}

.btn-search {
  margin-top: 1rem;
  border: none;
  border-radius: 8px;
  padding: 0.6rem 1.35rem;
  font: inherit;
  font-weight: 600;
  font-size: 0.9rem;
  cursor: pointer;
  color: #fff;
  background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
  box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
}

.btn-search--block {
  display: block;
  width: 100%;
}

.btn-search:hover:not(:disabled) {
  filter: brightness(1.06);
}

.btn-search:disabled {
  opacity: 0.65;
  cursor: not-allowed;
}

.btn-ghost-block {
  margin-top: 0.65rem;
  width: 100%;
  padding: 0.55rem 1rem;
  border-radius: 8px;
  border: 1px solid rgba(100, 116, 139, 0.55);
  background: rgba(30, 41, 59, 0.55);
  color: #e2e8f0;
  font: inherit;
  font-weight: 600;
  font-size: 0.88rem;
  cursor: pointer;
}

.btn-ghost-block:hover {
  border-color: rgba(148, 163, 184, 0.65);
  background: rgba(51, 65, 85, 0.55);
}

.btn-ghost-block--narrow {
  margin-top: 1rem;
  max-width: 14rem;
}

.quick-actions {
  display: flex;
  flex-direction: column;
  gap: 0.85rem;
  padding: 1rem 0.75rem 1.1rem;
  border-radius: 12px;
  border: 1px solid rgba(59, 130, 246, 0.22);
  background: linear-gradient(180deg, rgba(15, 23, 42, 0.72) 0%, rgba(15, 23, 42, 0.42) 100%);
  box-shadow: 0 8px 28px rgba(0, 0, 0, 0.22);
}

.quick-actions-kicker {
  margin: 0 0 0.15rem;
  font-size: 0.7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: #94a3b8;
}

.quick-btn {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  text-align: center;
  gap: 0.55rem;
  /* Tamaño fijo: sin flex-grow para que la altura no dependa del card hermano. */
  flex: 0 0 auto;
  padding: 1rem 0.65rem;
  border-radius: 12px;
  border: 1px solid transparent;
  text-decoration: none;
  color: inherit;
  cursor: pointer;
  transition:
    transform 0.12s ease,
    background 0.16s ease,
    border-color 0.16s ease,
    box-shadow 0.16s ease;
  background: rgba(15, 23, 42, 0.55);
}

.quick-btn:focus-visible {
  outline: none;
  box-shadow: 0 0 0 2px rgba(96, 165, 250, 0.55);
}

.quick-btn:hover {
  transform: translateY(-1px);
}

.quick-btn--primary {
  border-color: rgba(59, 130, 246, 0.45);
  background: linear-gradient(180deg, rgba(37, 99, 235, 0.22) 0%, rgba(29, 78, 216, 0.14) 100%);
  color: #e2e8f0;
}

.quick-btn--primary:hover {
  border-color: rgba(96, 165, 250, 0.7);
  background: linear-gradient(180deg, rgba(37, 99, 235, 0.32) 0%, rgba(29, 78, 216, 0.22) 100%);
}

.quick-btn-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  flex-shrink: 0;
  border-radius: 10px;
  background: rgba(15, 23, 42, 0.7);
  color: #93c5fd;
}

.quick-btn-body {
  display: flex;
  flex-direction: column;
  align-items: center;
  min-width: 0;
  line-height: 1.3;
}

.quick-btn-title {
  font-weight: 700;
  font-size: 0.9rem;
  color: #f1f5f9;
  letter-spacing: -0.005em;
}

.detail-card {
  border-radius: 12px;
  padding: 1.35rem 1.25rem 1.5rem;
  background: rgba(17, 24, 39, 0.94);
  border: 1px solid rgba(59, 130, 246, 0.35);
  box-shadow:
    0 0 0 1px rgba(59, 130, 246, 0.12),
    0 0 48px rgba(37, 99, 235, 0.12),
    0 20px 50px rgba(0, 0, 0, 0.4);
}

.list-head {
  margin-bottom: 0.75rem;
}

.list-title {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 700;
  color: #fff;
}

.inv-loading,
.inv-empty {
  padding: 1.5rem 0;
  text-align: center;
  font-size: 0.9rem;
}

.inv-ul {
  list-style: none;
  margin: 0.5rem 0 0;
  padding: 0;
  border-radius: 12px;
  border: 1px solid rgba(71, 85, 105, 0.45);
  overflow: hidden;
}

.inv-li {
  padding: 0.85rem 1rem;
  border-bottom: 1px solid rgba(71, 85, 105, 0.35);
  background: rgba(10, 15, 28, 0.45);
}

.inv-li:last-child {
  border-bottom: none;
}

.inv-li-name {
  margin: 0;
  font-weight: 600;
  font-size: 0.9rem;
  color: #f8fafc;
}

.inv-li-meta {
  margin: 0.35rem 0 0;
  font-size: 0.78rem;
  color: #94a3b8;
  line-height: 1.45;
}

.muted {
  color: #94a3b8;
}
</style>
