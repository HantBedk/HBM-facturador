<script setup>
defineProps({
  filters: { type: Object, required: true },
  companies: { type: Array, default: () => [] },
  empleados: { type: Array, default: () => [] },
  isAdmin: Boolean,
  isMaintenanceListing: Boolean,
})
</script>

<template>
  <div class="filters card">
    <label>
      <span>Empresa</span>
      <select v-model="filters.company_id">
        <option value="">Todas</option>
        <option v-for="c in companies" :key="c.id" :value="String(c.id)">{{ c.nombre }}</option>
      </select>
    </label>
    <label v-if="isAdmin">
      <span>Empleado / técnico</span>
      <select v-model="filters.user_id">
        <option value="">Todos</option>
        <option v-for="u in empleados" :key="u.id" :value="String(u.id)">{{ u.nombre }}</option>
      </select>
    </label>
    <label>
      <span>Tipo SAV</span>
      <select v-model="filters.sav_type" :disabled="isMaintenanceListing">
        <option value="">Todos</option>
        <option value="servicio">Servicio</option>
        <option value="mantenimiento">Mantenimiento</option>
        <option value="venta">Venta</option>
        <option value="alquiler">Alquiler</option>
      </select>
    </label>
    <template v-if="isAdmin">
      <label>
        <span>Fecha desde</span>
        <input v-model="filters.service_date_from" type="date" />
      </label>
      <label>
        <span>Fecha hasta</span>
        <input v-model="filters.service_date_to" type="date" />
      </label>
    </template>
    <label class="grow">
      <span>Búsqueda (código, cliente, descripción, tipo)</span>
      <input v-model="filters.q" type="search" placeholder="Ej. 20260329, SYF, instalación…" />
    </label>
    <label v-if="!isAdmin" class="filter-check">
      <input v-model="filters.assignment_pending" type="checkbox" />
      <span>Solo asignaciones pendientes</span>
    </label>
  </div>
</template>

<style scoped>
.card {
  padding: 1rem;
  border-radius: 14px;
  border: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.55);
  margin-bottom: 1rem;
}

.filters {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 180px), 1fr));
  gap: 0.75rem;
  align-items: end;
}

.filters label span {
  display: block;
  font-size: 0.8rem;
  color: #94a3b8;
  margin-bottom: 0.25rem;
}

.filters input,
.filters select {
  width: 100%;
  border-radius: 10px;
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: rgba(2, 6, 23, 0.35);
  color: #f8fafc;
  padding: 0.45rem 0.6rem;
}

.grow {
  grid-column: span 2;
}

.filter-check {
  display: flex;
  flex-direction: row;
  align-items: center;
  gap: 0.5rem;
  grid-column: span 2;
}

.filter-check span {
  display: inline;
  margin: 0;
  font-size: 0.85rem;
  color: #94a3b8;
}

.filter-check input {
  width: auto;
}

@media (max-width: 720px) {
  .grow {
    grid-column: span 1;
  }

  .filter-check {
    grid-column: span 1;
  }
}
</style>
