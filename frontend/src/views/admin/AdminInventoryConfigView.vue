<script setup>
import AdminInventoryHolderSettingsView from '@/views/admin/AdminInventoryHolderSettingsView.vue'
import AdminInventoryLocationSettingsView from '@/views/admin/AdminInventoryLocationSettingsView.vue'
</script>

<template>
  <div class="inventory-config-page w-full max-w-[1320px] mx-auto">
    <header class="page-head mb-6 lg:mb-8">
      <h1 class="page-title">Inventario</h1>
      <p class="page-lede">
        Titulares del inventario interno; en el otro panel, ubicaciones, tipos de activo y estados físicos (un solo
        guardado con contraseña).
      </p>
    </header>

    <!-- Mobile: apilado. Desktop: dos columnas equilibradas con la misma altura de fila -->
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2 lg:gap-8 lg:items-stretch">
      <div class="inventory-config-panel flex h-full min-w-0">
        <AdminInventoryHolderSettingsView class="w-full" />
      </div>
      <div class="inventory-config-panel flex h-full min-w-0">
        <AdminInventoryLocationSettingsView class="w-full" />
      </div>
    </div>
  </div>
</template>

<style scoped>
.page-title {
  margin: 0 0 0.5rem;
  font-size: 1.5rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: #f8fafc;
}
.page-lede {
  margin: 0;
  font-size: 0.9rem;
  line-height: 1.55;
  color: #94a3b8;
  max-width: 40rem;
}
</style>
