<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: Object,
    required: true,
  },
  catalogItems: { type: Array, default: () => [] },
  fieldErrors: { type: Object, default: () => ({}) },
  disabled: { type: Boolean, default: false },
  readonly: { type: Boolean, default: false },
  /** Oculta el bloque de descripción global (p. ej. empleado con líneas: el detalle va por concepto). */
  hideDescription: { type: Boolean, default: false },
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
  const d = (c.description || '').trim()
  if (d.length >= 8) return d
  return `Servicio estándar: ${c.name}. Detalle del trabajo realizado según visita en sitio.`
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
        :disabled="disabled || readonly"
        @change="onCatalogChange"
      >
        <option value="">— Personalizado —</option>
        <option v-for="c in catalogItems" :key="c.id" :value="String(c.id)">
          {{ c.name }} — {{ moneyShort(c.base_price) }}
        </option>
      </select>
    </label>

    <label class="field">
      <span>Nombre del cliente atendido <abbr title="obligatorio">*</abbr></span>
      <input
        :value="inner.client_name"
        :disabled="disabled || readonly"
        required
        autocomplete="off"
        placeholder="Quién recibió el servicio"
        @input="patch({ client_name: $event.target.value })"
      />
      <small v-if="fieldErrors.client_name" class="err">{{ fieldErrors.client_name[0] }}</small>
    </label>

    <label class="field">
      <span>Tipo de servicio <abbr title="obligatorio">*</abbr></span>
      <input
        :value="inner.service_type"
        :disabled="disabled || readonly"
        required
        placeholder="Ej. Mantenimiento, instalación…"
        @input="patch({ service_type: $event.target.value })"
      />
      <small v-if="fieldErrors.service_type" class="err">{{ fieldErrors.service_type[0] }}</small>
    </label>

    <label v-if="!hideDescription" class="field wide">
      <span>Descripción <abbr title="obligatorio">*</abbr></span>
      <textarea
        :value="inner.description"
        :disabled="disabled || readonly"
        rows="5"
        required
        placeholder="Descripción clara para facturación"
        @input="patch({ description: $event.target.value })"
      />
      <small v-if="fieldErrors.description" class="err">{{ fieldErrors.description[0] }}</small>
    </label>

    <label class="field">
      <span>Valor (COP) <abbr title="obligatorio">*</abbr></span>
      <input
        :value="inner.amount"
        :disabled="disabled || readonly"
        type="number"
        min="0.01"
        step="0.01"
        required
        @input="patch({ amount: $event.target.value })"
      />
      <small v-if="fieldErrors.amount" class="err">{{ fieldErrors.amount[0] }}</small>
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

.field span {
  display: block;
  font-size: 0.85rem;
  margin-bottom: 0.35rem;
  color: #cbd5e1;
}

input,
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
textarea:focus {
  outline: none;
  border-color: #38bdf8;
  box-shadow: 0 0 0 1px rgba(56, 189, 248, 0.35);
}

input:disabled,
textarea:disabled {
  opacity: 0.75;
  cursor: not-allowed;
}

.err {
  color: #fecaca;
  display: block;
  margin-top: 0.25rem;
}
</style>
