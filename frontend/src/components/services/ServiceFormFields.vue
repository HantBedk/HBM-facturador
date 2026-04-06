<script setup>
import { computed } from 'vue'
import { templateLineDescription } from '@/utils/serviceLineDescriptionTemplate.js'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  companies: { type: Array, default: () => [] },
  /** Ítems de GET /service-catalog/active */
  catalogItems: { type: Array, default: () => [] },
  clientSuggestions: { type: Array, default: () => [] },
  fieldErrors: { type: Object, default: () => ({}) },
  disabled: { type: Boolean, default: false },
  /** Si es true, no se envía fecha desde el formulario: el API usa la fecha del servidor. */
  serviceDateFromServer: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])

const inner = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

function patch(partial) {
  emit('update:modelValue', { ...props.modelValue, ...partial })
}

function moneyShort(v) {
  const n = Number(v)
  if (Number.isNaN(n)) return v
  return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n)
}

function descFromCatalog(c) {
  return templateLineDescription(c.name, c.description)
}

function onCatalogChange(ev) {
  const v = ev.target.value
  if (!v) {
    patch({ catalog_id: '' })
    return
  }
  const item = props.catalogItems.find((x) => String(x.id) === v)
  if (!item) return
  patch({
    catalog_id: item.id,
    service_type: item.name,
    description: descFromCatalog(item),
    amount: String(item.base_price),
  })
}
</script>

<template>
  <div class="grid">
    <label v-if="catalogItems.length" class="field wide">
      <span>Catálogo (opcional)</span>
      <select
        :value="modelValue.catalog_id != null && modelValue.catalog_id !== '' ? String(modelValue.catalog_id) : ''"
        :disabled="disabled"
        @change="onCatalogChange"
      >
        <option value="">— Personalizado (sin catálogo) —</option>
        <option v-for="c in catalogItems" :key="c.id" :value="String(c.id)">
          {{ c.name }} — {{ moneyShort(c.base_price) }}
        </option>
      </select>
      <small class="hint">Al elegir un ítem se rellenan tipo, descripción y valor; puede editarlos antes de guardar.</small>
    </label>

    <label class="field">
      <span>Empresa <abbr title="obligatorio">*</abbr></span>
      <select
        :value="inner.company_id"
        :disabled="disabled"
        required
        @change="patch({ company_id: $event.target.value ? Number($event.target.value) : '' })"
      >
        <option value="" disabled>Seleccione…</option>
        <option v-for="c in companies" :key="c.id" :value="c.id">{{ c.nombre }}</option>
      </select>
      <small v-if="fieldErrors.company_id" class="err">{{ fieldErrors.company_id[0] }}</small>
    </label>

    <label class="field">
      <span>Nombre del cliente atendido <abbr title="obligatorio">*</abbr></span>
      <input
        :value="inner.client_name"
        :disabled="disabled"
        required
        list="client-suggestions"
        autocomplete="off"
        placeholder="Quién recibió el servicio"
        @input="patch({ client_name: $event.target.value })"
      />
      <small v-if="fieldErrors.client_name" class="err">{{ fieldErrors.client_name[0] }}</small>
      <datalist id="client-suggestions">
        <option v-for="s in clientSuggestions" :key="s" :value="s" />
      </datalist>
    </label>

    <label class="field">
      <span>Tipo de servicio <abbr title="obligatorio">*</abbr></span>
      <input
        :value="inner.service_type"
        :disabled="disabled"
        required
        placeholder="Ej. Mantenimiento, instalación…"
        @input="patch({ service_type: $event.target.value })"
      />
      <small v-if="fieldErrors.service_type" class="err">{{ fieldErrors.service_type[0] }}</small>
    </label>

    <label class="field wide">
      <span>Descripción <abbr title="obligatorio">*</abbr></span>
      <textarea
        :value="inner.description"
        :disabled="disabled"
        rows="4"
        required
        placeholder="Qué se realizó"
        @input="patch({ description: $event.target.value })"
      />
      <small v-if="fieldErrors.description" class="err">{{ fieldErrors.description[0] }}</small>
    </label>

    <label class="field">
      <span>Valor (COP) <abbr title="obligatorio">*</abbr></span>
      <input
        :value="inner.amount"
        :disabled="disabled"
        type="number"
        min="0.01"
        step="0.01"
        required
        @input="patch({ amount: $event.target.value })"
      />
      <small v-if="fieldErrors.amount" class="err">{{ fieldErrors.amount[0] }}</small>
    </label>

    <div v-if="serviceDateFromServer" class="field wide">
      <p class="server-date-notice">
        Al guardar, la fecha de servicio y el código los asigna el <strong>servidor</strong> (zona horaria de la aplicación).
        La hora exacta del registro queda grabada internamente en el servidor.
      </p>
    </div>
    <label v-else class="field">
      <span>Fecha del servicio <abbr title="obligatorio">*</abbr></span>
      <input
        :value="inner.service_date"
        :disabled="disabled"
        type="date"
        required
        @input="patch({ service_date: $event.target.value })"
      />
      <small v-if="fieldErrors.service_date" class="err">{{ fieldErrors.service_date[0] }}</small>
    </label>
  </div>
</template>

<style scoped>
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 1rem;
}

.field.wide {
  grid-column: 1 / -1;
}

.server-date-notice {
  margin: 0;
  padding: 0.75rem 0.9rem;
  border-radius: 10px;
  border: 1px solid rgba(56, 189, 248, 0.25);
  background: rgba(56, 189, 248, 0.08);
  font-size: 0.82rem;
  line-height: 1.45;
  color: #bae6fd;
}

.field span {
  display: block;
  font-size: 0.85rem;
  margin-bottom: 0.35rem;
  color: #cbd5e1;
}

input,
select,
textarea {
  width: 100%;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.35);
  color: #f8fafc;
  padding: 0.55rem 0.75rem;
  font: inherit;
}

input:focus,
select:focus,
textarea:focus {
  outline: none;
  border-color: #38bdf8;
  box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.35);
}

.err {
  color: #fecaca;
  display: block;
  margin-top: 0.25rem;
}

.hint {
  display: block;
  margin-top: 0.35rem;
  color: #94a3b8;
  font-size: 0.78rem;
  line-height: 1.35;
}
</style>
