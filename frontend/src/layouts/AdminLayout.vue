<script setup>
import { computed } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const displayName = computed(() => auth.user?.nombre || 'Administrador')

async function salir() {
  await auth.logout()
  await router.replace('/login')
}
</script>

<template>
  <div class="shell admin">
    <aside class="sidebar">
      <div class="logo">HBM</div>
      <nav class="nav">
        <RouterLink to="/admin" class="nav-item" :class="{ active: route.path === '/admin' }">Dashboard</RouterLink>
        <RouterLink to="/admin/servicios" class="nav-item" :class="{ active: route.path.startsWith('/admin/servicios') }">
          Listado de servicios
        </RouterLink>
        <RouterLink to="/admin/facturas" class="nav-item" :class="{ active: route.path.startsWith('/admin/facturas') }">
          Facturas
        </RouterLink>
        <RouterLink to="/admin/empresas" class="nav-item" :class="{ active: route.path.startsWith('/admin/empresas') }">
          Empresas
        </RouterLink>
        <RouterLink
          to="/admin/empleados"
          class="nav-item"
          :class="{ active: route.path === '/admin/empleados' }"
        >
          Empleados
        </RouterLink>
        <RouterLink
          to="/admin/empleados/rendimiento"
          class="nav-item"
          :class="{ active: route.path.startsWith('/admin/empleados/rendimiento') }"
        >
          Rendimiento equipo
        </RouterLink>
      </nav>
    </aside>
    <div class="main">
      <header class="topbar">
        <div class="topbar-title">Panel administrador</div>
        <div class="topbar-actions">
          <span class="user-pill">{{ displayName }}</span>
          <button type="button" class="btn-ghost" @click="salir">Salir</button>
        </div>
      </header>
      <main class="content">
        <RouterView />
      </main>
    </div>
  </div>
</template>

<style scoped>
.shell {
  min-height: 100vh;
  display: grid;
  grid-template-columns: 240px 1fr;
  background: #0f172a;
  color: #e2e8f0;
}

.sidebar {
  border-right: 1px solid rgba(148, 163, 184, 0.2);
  padding: 1.25rem 1rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  background: rgba(15, 23, 42, 0.95);
}

.logo {
  font-weight: 800;
  letter-spacing: 0.08em;
  padding: 0.5rem 0.75rem;
  border-radius: 10px;
  background: linear-gradient(135deg, #38bdf8, #6366f1);
  color: #0f172a;
  text-align: center;
}

.nav {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.nav-item {
  padding: 0.65rem 0.75rem;
  border-radius: 10px;
  color: #cbd5e1;
  text-decoration: none;
  font-size: 0.95rem;
}

.nav-item.active {
  background: rgba(56, 189, 248, 0.15);
  color: #f8fafc;
  border: 1px solid rgba(56, 189, 248, 0.35);
}

.nav-item.disabled {
  opacity: 0.45;
  cursor: not-allowed;
}

.main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.5rem;
  border-bottom: 1px solid rgba(148, 163, 184, 0.2);
  background: rgba(15, 23, 42, 0.85);
  backdrop-filter: blur(10px);
}

.topbar-title {
  font-weight: 600;
}

.topbar-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.user-pill {
  padding: 0.35rem 0.75rem;
  border-radius: 999px;
  background: rgba(148, 163, 184, 0.15);
  font-size: 0.85rem;
}

.btn-ghost {
  border: 1px solid rgba(148, 163, 184, 0.35);
  background: transparent;
  color: #e2e8f0;
  border-radius: 10px;
  padding: 0.45rem 0.85rem;
  cursor: pointer;
}

.btn-ghost:hover {
  border-color: #38bdf8;
  color: #fff;
}

.content {
  padding: 1.5rem;
  flex: 1;
}

@media (max-width: 900px) {
  .shell {
    grid-template-columns: 1fr;
  }
  .sidebar {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }
  .nav {
    flex-direction: row;
    flex-wrap: wrap;
  }
}
</style>
