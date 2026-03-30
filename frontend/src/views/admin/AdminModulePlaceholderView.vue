<script setup>
import { computed } from 'vue'
import { RouterLink, useRoute } from 'vue-router'

const route = useRoute()
const title = computed(() => route.meta.placeholderTitle || 'Módulo')
const hint = computed(() => route.meta.placeholderHint || 'Esta sección se integrará en una siguiente etapa.')
</script>

<template>
  <section class="panel">
    <h2>{{ title }}</h2>
    <p class="lead">{{ hint }}</p>
    <RouterLink to="/admin" class="back">← Volver al dashboard</RouterLink>
  </section>
</template>

<style scoped>
.panel h2 {
  margin: 0 0 0.5rem;
  font-size: 1.25rem;
}

.lead {
  color: #94a3b8;
  margin: 0 0 1.25rem;
  line-height: 1.5;
  max-width: 42rem;
}

.back {
  color: #7dd3fc;
  font-weight: 600;
  text-decoration: none;
}

.back:hover {
  text-decoration: underline;
}
</style>
