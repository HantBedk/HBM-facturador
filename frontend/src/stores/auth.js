import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { api, clearTokenStorage, getToken, setToken } from '@/services/api'

const USER_KEY = 'auth_user'

function loadStoredUser() {
  try {
    const s = sessionStorage.getItem(USER_KEY)
    if (s) return JSON.parse(s)
    const l = localStorage.getItem(USER_KEY)
    return l ? JSON.parse(l) : null
  } catch {
    return null
  }
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref(loadStoredUser())
  const bootstrapped = ref(false)

  const isAuthenticated = computed(() => !!user.value)

  function persistUser(u, persistent = true) {
    user.value = u
    sessionStorage.removeItem(USER_KEY)
    localStorage.removeItem(USER_KEY)
    if (!u) return
    const raw = JSON.stringify(u)
    if (persistent) localStorage.setItem(USER_KEY, raw)
    else sessionStorage.setItem(USER_KEY, raw)
  }

  async function bootstrap() {
    if (!getToken()) {
      bootstrapped.value = true
      return
    }
    try {
      const me = await api('/auth/me')
      const persistent = !!localStorage.getItem('auth_token')
      persistUser(me, persistent)
    } catch {
      clearTokenStorage()
      persistUser(null)
    } finally {
      bootstrapped.value = true
    }
  }

  /**
   * @param {object} payload correo, password, device_name
   * @param {{ remember?: boolean }} options remember=true guarda en localStorage
   */
  async function login(payload, options = {}) {
    const remember = options.remember !== false
    const data = await api('/auth/login', {
      method: 'POST',
      body: JSON.stringify(payload),
    })
    setToken(data.token, remember)
    persistUser(data.user, remember)
    return data.user
  }

  async function logout() {
    try {
      await api('/auth/logout', { method: 'POST' })
    } catch {
      /* ignorar red caída */
    }
    clearTokenStorage()
    persistUser(null)
  }

  return {
    user,
    bootstrapped,
    isAuthenticated,
    bootstrap,
    login,
    logout,
  }
})
