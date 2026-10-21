import { defineStore } from 'pinia'
import { API_URL, apiFetch } from '@/services/api'
import { can } from '@/permissions'

const STORAGE_KEYS = {
  token: 'financial_bottoken',
  user: 'financial_botuser',
  expireAt: 'financial_botexpire_at',
  // Company a master is working in (sent as X-Company-Id; the API ignores it for everyone else).
  companyId: 'financial_botcompany_id',
}

const POLL_INTERVAL_MS = 60_000

// Per-browser UI preferences (e.g. menu open/closed): not company data, so a company switch keeps them.
export const PREFERENCE_PREFIX = 'financial_botpref_'

// 403 from ResolveCompany: the user's company license is off. Ends the session with a notice.
export async function isCompanyInactive(response) {
  if (response.status !== 403) return false
  const data = await response.clone().json().catch(() => ({}))
  if (data.code !== 'COMPANY_INACTIVE') return false
  const { swal } = await import('@/plugins/swal')
  swal.toastError(data.message)
  return true
}

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: null,
    user: null,
    expireAt: null,
    companyId: null,
    pollingTimer: null,
  }),

  getters: {
    isAuthenticated(state) {
      return !!state.token && !!state.user
    },

    // Reactive: re-evaluates in templates whenever /auth/me refreshes the permissions.
    can(state) {
      return (permission) => can(state.user, permission)
    },

    // Headers of every authenticated call (apiFetch, /auth/me, logout).
    headers(state) {
      return {
        Accept: 'application/json',
        ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
        ...(state.companyId ? { 'X-Company-Id': String(state.companyId) } : {}),
      }
    },

    // Current company's settings (Configurações) and the user's preferences, defaults filled by the API.
    settings(state) {
      return state.user?.company?.settings ?? { appearance: {}, finance: {} }
    },

    preferences(state) {
      return state.user?.preferences ?? {}
    },

    userInitials(state) {
      if (!state.user?.name) return '?'
      return state.user.name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0].toUpperCase())
        .join('')
    },
  },

  actions: {
    restoreSession() {
      this.token = localStorage.getItem(STORAGE_KEYS.token)
      this.expireAt = localStorage.getItem(STORAGE_KEYS.expireAt)
      this.companyId = localStorage.getItem(STORAGE_KEYS.companyId)

      const storedUser = localStorage.getItem(STORAGE_KEYS.user)
      this.user = storedUser ? JSON.parse(storedUser) : null

      if (this.isAuthenticated && !this.isTokenExpired()) {
        this.startPolling()
      } else if (this.token || this.user) {
        this.clearSession()
      }
    },

    isTokenExpired() {
      if (!this.expireAt) return true
      return new Date(this.expireAt).getTime() <= Date.now()
    },

    persistSession(token, user, expireAt) {
      this.token = token
      this.user = user
      this.expireAt = expireAt

      localStorage.setItem(STORAGE_KEYS.token, token)
      localStorage.setItem(STORAGE_KEYS.user, JSON.stringify(user))
      localStorage.setItem(STORAGE_KEYS.expireAt, expireAt)
    },

    clearSession() {
      this.stopPolling()
      this.token = null
      this.user = null
      this.expireAt = null
      this.companyId = null

      Object.values(STORAGE_KEYS).forEach((key) => localStorage.removeItem(key))
    },

    // Master only. Switching company drops every client-side cache (in-memory stores, page
    // state, sessionStorage, stored data other than the session itself) by reloading the app:
    // nothing from the previous company can leak into the next screen. The API scopes every
    // query to the company sent in X-Company-Id.
    switchCompany(companyId) {
      if (!this.user?.master || String(companyId) === String(this.user.company?.id)) return

      // The session survives (user is re-fetched from /auth/me right after the reload).
      const keep = new Set([STORAGE_KEYS.token, STORAGE_KEYS.expireAt, STORAGE_KEYS.user])
      Object.keys(localStorage)
        .filter((key) => !keep.has(key) && !key.startsWith(PREFERENCE_PREFIX))
        .forEach((key) => localStorage.removeItem(key))
      sessionStorage.clear()
      localStorage.setItem(STORAGE_KEYS.companyId, String(companyId))

      // BASE_URL keeps the reload inside the app's prefix (/financial-bot/ behind Nginx).
      window.location.assign(import.meta.env.BASE_URL)
    },

    async login(email, password) {
      const response = await fetch(`${API_URL}/auth/login`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email, password }),
      })

      const data = await response.json()

      if (!response.ok) {
        const message = data.errors?.email?.[0] || data.message || 'Falha ao realizar login.'
        throw new Error(message)
      }

      this.persistSession(data.token, data.user, data.expire_at)
      this.startPolling()
    },

    async logout({ redirect = true } = {}) {
      if (this.token) {
        try {
          await fetch(`${API_URL}/auth/logout`, { method: 'POST', headers: this.headers })
        } catch {
          // Ignora falha de rede no logout remoto.
        }
      }

      this.clearSession()

      if (redirect) {
        const { default: router } = await import('@/router')
        if (router.currentRoute.value.path !== '/login') {
          await router.push('/login')
        }
      }
    },

    // Pulls the current user + permissions; keeps the UI in step with grants/revokes.
    async refreshUser() {
      const response = await fetch(`${API_URL}/auth/me`, { headers: this.headers })

      if (response.status === 401 || (await isCompanyInactive(response))) {
        await this.logout()
        return false
      }

      if (!response.ok) return false

      this.user = await response.json()
      localStorage.setItem(STORAGE_KEYS.user, JSON.stringify(this.user))
      // The API answers with the company it actually used (an unknown id falls back).
      this.companyId = this.user.master ? String(this.user.company.id) : null
      if (this.companyId) localStorage.setItem(STORAGE_KEYS.companyId, this.companyId)
      else localStorage.removeItem(STORAGE_KEYS.companyId)
      return true
    },

    // Optimistic: the screen reacts right away; the API stores it for every device of this user.
    async savePreferences(patch) {
      this.user = { ...this.user, preferences: { ...this.preferences, ...patch } }
      localStorage.setItem(STORAGE_KEYS.user, JSON.stringify(this.user))
      const response = await apiFetch('/settings/preferences', { method: 'PUT', body: JSON.stringify(patch) })
      if (!response.ok) throw new Error('Não foi possível salvar a preferência.')
    },

    async validateSession() {
      if (!this.isAuthenticated) {
        await this.logout()
        return false
      }

      if (this.isTokenExpired()) {
        await this.logout()
        return false
      }

      try {
        return await this.refreshUser()
      } catch {
        return true
      }
    },

    startPolling() {
      this.stopPolling()

      this.pollingTimer = setInterval(() => {
        this.validateSession()
      }, POLL_INTERVAL_MS)
    },

    stopPolling() {
      if (this.pollingTimer) {
        clearInterval(this.pollingTimer)
        this.pollingTimer = null
      }
    },
  },
})
