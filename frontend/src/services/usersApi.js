import { api } from './api'

export function fetchAdminUsers(params = {}) {
  const qs = new URLSearchParams()
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) qs.set(k, String(v))
  })
  const s = qs.toString()
  return api(`/admin/users${s ? `?${s}` : ''}`)
}

export function createUser(payload) {
  return api('/admin/users', {
    method: 'POST',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

export function updateUser(id, payload) {
  return api(`/admin/users/${id}`, {
    method: 'PUT',
    body: JSON.stringify(payload),
  }).then((r) => r.data)
}

export function patchUserEstado(id, estado) {
  return api(`/admin/users/${id}/estado`, {
    method: 'PATCH',
    body: JSON.stringify({ estado }),
  }).then((r) => r.data)
}
