import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import LoginView from '@/views/LoginView.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'
import EmpleadoLayout from '@/layouts/EmpleadoLayout.vue'
import AdminDashboardView from '@/views/admin/AdminDashboardView.vue'
import CompaniesListView from '@/views/admin/CompaniesListView.vue'
import InvoicesListView from '@/views/admin/InvoicesListView.vue'
import InvoiceEditorView from '@/views/admin/InvoiceEditorView.vue'
import InvoiceDetailView from '@/views/admin/InvoiceDetailView.vue'
import EmpleadosListView from '@/views/admin/EmpleadosListView.vue'
import EmpleadoDashboardView from '@/views/empleado/EmpleadoDashboardView.vue'
import EmployeeHistorialView from '@/views/empleado/EmployeeHistorialView.vue'
import ServicesListView from '@/views/services/ServicesListView.vue'
import ServiceRegisterView from '@/views/services/ServiceRegisterView.vue'
import ServiceDetailView from '@/views/services/ServiceDetailView.vue'
import ServiceEditView from '@/views/services/ServiceEditView.vue'
import ServiceCatalogView from '@/views/admin/ServiceCatalogView.vue'
import PublicInvoiceConsultView from '@/views/public/PublicInvoiceConsultView.vue'
import { isAdminPanelRole } from '@/utils/roles.js'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: LoginView,
    meta: { guest: true },
  },
  {
    path: '/consulta-factura',
    name: 'consulta-factura-publica',
    component: PublicInvoiceConsultView,
    meta: { public: true },
  },
  {
    path: '/admin',
    component: AdminLayout,
    meta: { auth: true, roles: ['admin', 'super_admin'] },
    children: [
      { path: '', name: 'admin-dashboard', component: AdminDashboardView },
      { path: 'servicios', name: 'admin-servicios', component: ServicesListView },
      { path: 'catalogo-servicios', name: 'admin-catalogo-servicios', component: ServiceCatalogView },
      { path: 'servicios/nuevo', name: 'admin-servicios-nuevo', component: ServiceRegisterView },
      { path: 'servicios/:id', name: 'admin-servicio-detalle', component: ServiceDetailView, props: true },
      { path: 'servicios/:id/editar', name: 'admin-servicio-editar', component: ServiceEditView, props: true },
      { path: 'facturas/nueva', name: 'admin-facturas-nueva', component: InvoiceEditorView },
      {
        path: 'facturas/:id(\\d+)/editar',
        name: 'admin-facturas-editar',
        component: InvoiceEditorView,
        props: true,
      },
      {
        path: 'facturas/:id(\\d+)',
        name: 'admin-factura-detalle',
        component: InvoiceDetailView,
        props: true,
      },
      { path: 'facturas', name: 'admin-facturas', component: InvoicesListView },
      { path: 'empresas', name: 'admin-empresas', component: CompaniesListView },
      { path: 'empleados', name: 'admin-empleados-list', component: EmpleadosListView },
      {
        path: 'empleados/rendimiento/:userId(\\d+)',
        name: 'admin-emp-rendimiento-user',
        component: EmployeeHistorialView,
        props: true,
      },
      {
        path: 'empleados/rendimiento',
        name: 'admin-emp-rendimiento',
        component: EmployeeHistorialView,
      },
    ],
  },
  {
    path: '/empleado',
    component: EmpleadoLayout,
    meta: { auth: true, roles: ['empleado'] },
    children: [
      { path: '', name: 'empleado-dashboard', component: EmpleadoDashboardView },
      { path: 'historial', name: 'emp-historial', component: EmployeeHistorialView },
      { path: 'registro-servicio', name: 'emp-registro-servicio', component: ServiceRegisterView },
      { path: 'listado-servicios', name: 'emp-listado-servicios', component: ServicesListView },
      { path: 'servicio/:id(\\d+)', name: 'emp-servicio-detalle', component: ServiceDetailView, props: true },
      { path: 'servicios/nuevo', redirect: '/empleado/registro-servicio' },
      {
        path: 'servicios/:id(\\d+)',
        redirect: (to) => ({ path: `/empleado/servicio/${to.params.id}` }),
      },
      { path: 'servicios', redirect: '/empleado/listado-servicios' },
      { path: 'servicios/:pathMatch(.*)*', redirect: '/empleado/listado-servicios' },
    ],
  },
  { path: '/', redirect: '/login' },
  { path: '/:pathMatch(.*)*', redirect: '/login' },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.bootstrapped) await auth.bootstrap()

  if (to.matched.some((r) => r.meta.public)) {
    return true
  }

  const needsAuth = to.matched.some((r) => r.meta.auth)
  const guestOnly = to.matched.some((r) => r.meta.guest)
  const roles = to.matched.find((r) => r.meta.roles)?.meta.roles

  if (needsAuth && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (guestOnly && auth.isAuthenticated) {
    return isAdminPanelRole(auth.user?.rol)
      ? { name: 'admin-dashboard' }
      : { name: 'empleado-dashboard' }
  }

  if (roles && auth.user && !roles.includes(auth.user.rol)) {
    return isAdminPanelRole(auth.user.rol)
      ? { name: 'admin-dashboard' }
      : { name: 'empleado-dashboard' }
  }

  return true
})

export default router
