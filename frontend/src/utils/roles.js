/** Roles que usan el panel /admin (no empleado). */
export function isAdminPanelRole(rol) {
  return rol === 'admin' || rol === 'super_admin'
}
