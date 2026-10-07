import { defineStore } from 'pinia'
import { API_URL } from '@/services/api'
import { can } from '@/permissions'

const STORAGE_KEYS = {
  token: 'financial_bottoken',
  user: 'financial_botuser',
  expireAt: 'financial_botexpire_at',
}

const POLL_INTERVAL_MS = 60_000

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: null,
    user: null,
    expireAt: null,
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

      localStorage.removeItem(STORAGE_KEYS.token)
      localStorage.removeItem(STORAGE_KEYS.user)
      localStorage.removeItem(STORAGE_KEYS.expireAt)
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
          await fetch(`${API_URL}/auth/logout`, {
            method: 'POST',
            headers: {
              Authorization: `Bearer ${this.token}`,
            },
          })
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
      const response = await fetch(`${API_URL}/auth/me`, {
        headers: {
          Authorization: `Bearer ${this.token}`,
          Accept: 'application/json',
        },
      })

      if (response.status === 401) {
        await this.logout()
        return false
      }

      if (!response.ok) return false

      this.user = await response.json()
      localStorage.setItem(STORAGE_KEYS.user, JSON.stringify(this.user))
      return true
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
