import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import LoginView from '@/views/LoginView.vue'
/** Vistas y layouts en chunks aparte: menos JS en /login y carga bajo demanda al navegar. */
const AdminLayout = () => import('@/layouts/AdminLayout.vue')
const EmpleadoLayout = () => import('@/layouts/EmpleadoLayout.vue')
const AdminDashboardView = () => import('@/views/admin/AdminDashboardView.vue')
const CompaniesListView = () => import('@/views/admin/CompaniesListView.vue')
const InvoicesListView = () => import('@/views/admin/InvoicesListView.vue')
const InvoiceEditorView = () => import('@/views/admin/InvoiceEditorView.vue')
const InvoiceDetailView = () => import('@/views/admin/InvoiceDetailView.vue')
const AdminConfiguracionLayout = () => import('@/layouts/AdminConfiguracionLayout.vue')
const AdminEmpleadoNotificacionesView = () => import('@/views/admin/AdminEmpleadoNotificacionesView.vue')
const AdminHistorialMovimientosView = () => import('@/views/admin/AdminHistorialMovimientosView.vue')
const AdminBillingAutomationView = () => import('@/views/admin/AdminBillingAutomationView.vue')
const AdminEmpleadoPerfilView = () => import('@/views/admin/AdminEmpleadoPerfilView.vue')
const EmpleadosListView = () => import('@/views/admin/EmpleadosListView.vue')
const EmpleadoDashboardView = () => import('@/views/empleado/EmpleadoDashboardView.vue')
const EmpleadoConfiguracionView = () => import('@/views/empleado/EmpleadoConfiguracionView.vue')
const EmpleadoOnboardingView = () => import('@/views/empleado/EmpleadoOnboardingView.vue')
const EmployeeHistorialView = () => import('@/views/empleado/EmployeeHistorialView.vue')
const ServicesListView = () => import('@/views/services/ServicesListView.vue')
const ServiceRegisterView = () => import('@/views/services/ServiceRegisterView.vue')
const ServiceCompleteAssignmentView = () => import('@/views/services/ServiceCompleteAssignmentView.vue')
const ServiceDetailView = () => import('@/views/services/ServiceDetailView.vue')
const ServiceEditView = () => import('@/views/services/ServiceEditView.vue')
const ServiceCatalogView = () => import('@/views/admin/ServiceCatalogView.vue')
const PublicInvoiceConsultView = () => import('@/views/public/PublicInvoiceConsultView.vue')
import { isAdminPanelRole } from '@/utils/roles.js'
import { isEmpleadoPerfilIncomplete } from '@/utils/empleadoPerfil.js'

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
      {
        path: 'configuracion',
        component: AdminConfiguracionLayout,
        redirect: { name: 'admin-notificaciones-tecnicos' },
        children: [
          {
            path: 'cuentas',
            redirect: { name: 'admin-emp-rendimiento' },
          },
          {
            path: 'notificaciones-tecnicos',
            name: 'admin-notificaciones-tecnicos',
            component: AdminEmpleadoNotificacionesView,
          },
          {
            path: 'historial',
            name: 'admin-config-historial',
            component: AdminHistorialMovimientosView,
          },
          {
            path: 'facturacion-automatica',
            name: 'admin-config-billing-automation',
            component: AdminBillingAutomationView,
          },
        ],
      },
      {
        path: 'empleados/rendimiento/:userId(\\d+)',
        name: 'admin-emp-rendimiento-user',
        component: EmployeeHistorialView,
        props: true,
      },
      {
        path: 'empleados/rendimiento',
        name: 'admin-emp-rendimiento',
        component: EmpleadosListView,
      },
      {
        path: 'empleados/:userId(\\d+)/perfil',
        name: 'admin-empleado-perfil',
        component: AdminEmpleadoPerfilView,
        props: true,
      },
      { path: 'empleados', redirect: { name: 'admin-emp-rendimiento' } },
    ],
  },
  {
    path: '/empleado',
    component: EmpleadoLayout,
    meta: { auth: true, roles: ['empleado'] },
    children: [
      {
        path: 'completar-perfil',
        redirect: (to) => ({ name: 'empleado-perfil', query: to.query }),
      },
      {
        path: 'perfil',
        name: 'empleado-perfil',
        component: EmpleadoOnboardingView,
      },
      {
        path: 'configuracion',
        name: 'empleado-configuracion',
        component: EmpleadoConfiguracionView,
      },
      { path: '', name: 'empleado-dashboard', component: EmpleadoDashboardView },
      { path: 'historial', redirect: { name: 'empleado-dashboard' } },
      { path: 'registro-servicio', name: 'emp-registro-servicio', component: ServiceRegisterView },
      { path: 'listado-servicios', name: 'emp-listado-servicios', component: ServicesListView },
      { path: 'servicio/:id(\\d+)', name: 'emp-servicio-detalle', component: ServiceDetailView, props: true },
      {
        path: 'servicio/:id(\\d+)/completar-asignacion',
        name: 'emp-servicio-completar-asignacion',
        component: ServiceCompleteAssignmentView,
        props: true,
      },
      { path: 'servicio/:id(\\d+)/editar', name: 'emp-servicio-editar', component: ServiceEditView, props: true },
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

  /** Técnicos: si faltan datos obligatorios solo se permite /empleado/perfil y /empleado/configuracion (contraseña y avisos). */
  if (auth.isAuthenticated && auth.user?.rol === 'empleado') {
    const incomplete = isEmpleadoPerfilIncomplete(auth.user)
    if (
      to.path === '/empleado/perfil' ||
      to.path === '/empleado/completar-perfil' ||
      to.path === '/empleado/configuracion'
    ) {
      return true
    }
    if (incomplete) {
      return {
        path: '/empleado/perfil',
        query: { redirect: to.fullPath },
      }
    }
  }

  return true
})

export default router
