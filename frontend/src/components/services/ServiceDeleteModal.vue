<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { estadoLabel, money } from '@/views/services/servicesListHelpers.js'

const props = defineProps({
  open: Boolean,
  target: { type: Object, default: null },
  archivingId: { type: [Number, String], default: null },
})
const emit = defineEmits(['close', 'confirm'])

const confirmInput = ref('')
const error = ref('')
const inputRef = ref(null)

const expectedCode = computed(() => {
  const t = props.target
  if (!t) return ''
  return t.invoices?.[0]?.code ?? t.code ?? ''
})

const usesInvoiceCode = computed(() => Boolean(props.target?.invoices?.[0]?.code))

watch(
  () => props.open,
  async (open) => {
    if (open) {
      confirmInput.value = ''
      error.value = ''
      await nextTick()
      inputRef.value?.focus()
    }
  },
)

function close() {
  emit('close')
}

function submit() {
  if (!props.target || props.archivingId != null) return
  const typed = confirmInput.value.trim()
  if (!expectedCode.value || typed !== expectedCode.value) {
    error.value = 'El texto no coincide exactamente con el código indicado (mayúsculas, números y guiones).'
    return
  }
  error.value = ''
  emit('confirm', props.target.id)
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open && target"
      class="modal-backdrop"
      role="presentation"
      @click.self="close"
    >
      <div
        class="modal-panel"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sdm-title"
        @click.stop
      >
        <h2 id="sdm-title" class="modal-title">Confirmar eliminación del servicio</h2>
        <p class="modal-lede">
          Vas a marcar como <strong>eliminado</strong> el servicio. Revisa los datos y escribe el código que se
          indica abajo para confirmar.
        </p>
        <dl class="modal-dl">
          <div><dt>Servicio</dt><dd>{{ target.code }}</dd></div>
          <div><dt>Empresa</dt><dd>{{ target.company?.nombre || '—' }}</dd></div>
          <div><dt>Cliente</dt><dd>{{ target.client_name || '—' }}</dd></div>
          <div><dt>Valor</dt><dd>{{ money(target.amount) }}</dd></div>
          <div><dt>Estado</dt><dd>{{ estadoLabel(target.status) }}</dd></div>
        </dl>
        <div v-if="usesInvoiceCode" class="modal-highlight">
          <p class="modal-highlight-label">Factura asociada (escribe este código exacto)</p>
          <p class="modal-highlight-code" aria-live="polite">{{ expectedCode }}</p>
        </div>
        <div v-else class="modal-highlight modal-highlight--muted">
          <p class="modal-highlight-label">Sin factura asociada</p>
          <p class="modal-highlight-code">{{ expectedCode }}</p>
          <p class="modal-hint muted">Confirma escribiendo el <strong>código del servicio</strong> mostrado arriba.</p>
        </div>
        <label class="modal-field">
          <span>Confirmación</span>
          <input
            ref="inputRef"
            v-model="confirmInput"
            type="text"
            autocomplete="off"
            :placeholder="usesInvoiceCode ? 'Código de factura' : 'Código del servicio'"
            @keydown.enter.prevent="submit"
          />
        </label>
        <p v-if="error" class="modal-error" role="alert">{{ error }}</p>
        <div class="modal-actions">
          <button type="button" class="btn secondary" :disabled="archivingId != null" @click="close">
            Cancelar
          </button>
          <button
            type="button"
            class="btn danger"
            :disabled="archivingId != null || !confirmInput.trim()"
            @click="submit"
          >
            {{ archivingId != null ? 'Eliminando…' : 'Eliminar servicio' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  z-index: 80;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(2, 6, 23, 0.75);
  backdrop-filter: blur(5px);
}

.modal-panel {
  width: 100%;
  max-width: 26rem;
  max-height: min(92vh, 100%);
  overflow-y: auto;
  padding: 1.25rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.28);
  background: #1e293b;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
}

.modal-title {
  margin: 0 0 0.5rem;
  font-size: 1.1rem;
  color: #f8fafc;
}

.modal-lede {
  margin: 0 0 1rem;
  font-size: 0.8rem;
  color: #94a3b8;
  line-height: 1.45;
}

.modal-dl {
  margin: 0 0 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  font-size: 0.8rem;
}

.modal-dl > div {
  display: grid;
  grid-template-columns: 5.25rem 1fr;
  gap: 0.35rem;
  align-items: baseline;
}

.modal-dl dt {
  margin: 0;
  color: #94a3b8;
  font-weight: 600;
}

.modal-dl dd {
  margin: 0;
  color: #e2e8f0;
  word-break: break-word;
}

.modal-highlight {
  margin: 0 0 1rem;
  padding: 0.75rem 0.85rem;
  border-radius: 10px;
  background: rgba(56, 189, 248, 0.1);
  border: 1px solid rgba(56, 189, 248, 0.38);
}

.modal-highlight--muted {
  background: rgba(148, 163, 184, 0.08);
  border-color: rgba(148, 163, 184, 0.28);
}

.modal-highlight-label {
  margin: 0 0 0.35rem;
  font-size: 0.7rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #94a3b8;
}

.modal-highlight-code {
  margin: 0;
  font-family: ui-monospace, 'Cascadia Code', monospace;
  font-size: 1.05rem;
  font-weight: 700;
  color: #7dd3fc;
  word-break: break-all;
}

.modal-highlight--muted .modal-highlight-code {
  color: #f1f5f9;
}

.modal-hint {
  margin: 0.5rem 0 0;
  font-size: 0.75rem;
  line-height: 1.4;
}

.modal-field {
  display: block;
  margin-bottom: 0.5rem;
}

.modal-field span {
  display: block;
  font-size: 0.78rem;
  color: #94a3b8;
  margin-bottom: 0.35rem;
}

.modal-field input {
  width: 100%;
  box-sizing: border-box;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.4);
  background: rgba(2, 6, 23, 0.55);
  color: #f8fafc;
  padding: 0.55rem 0.65rem;
  font-size: 0.9rem;
}

.modal-error {
  margin: 0 0 0.75rem;
  font-size: 0.78rem;
  color: #fecaca;
}

.modal-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  justify-content: flex-end;
  margin-top: 0.25rem;
}

.muted {
  color: #94a3b8;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0.5rem 0.9rem;
  border-radius: 10px;
  font-weight: 600;
  cursor: pointer;
  border: 1px solid transparent;
  text-decoration: none;
}

.btn.secondary {
  border-color: rgba(148, 163, 184, 0.35);
  color: #e2e8f0;
  background: transparent;
}

.btn.danger {
  background: rgba(220, 38, 38, 0.22);
  border: 1px solid rgba(248, 113, 113, 0.45);
  color: #fecaca;
}

.btn.danger:hover:not(:disabled) {
  background: rgba(220, 38, 38, 0.38);
  color: #fff;
}

.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
