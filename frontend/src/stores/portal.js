import { defineStore } from 'pinia'
import { API_URL, portalFetch } from '@/services/api'

// Portal do cliente session. Kept apart from the CRM session (stores/auth.js): a customer's token only
// opens /api/portal/*. `slug` = the company portal it belongs to; another portal's address asks to log in.
const STORAGE_KEYS = {
  token: 'financial_botportal_token',
  expireAt: 'financial_botportal_expire_at',
  slug: 'financial_botportal_slug',
}

export const usePortalStore = defineStore('portal', {
  state: () => ({
    token: null,
    expireAt: null,
    slug: null,
    // /portal/me: { customer: { name, email }, company: { name, slug, primary_color, payment } }
    me: null,
    // Announcements newer than the last visit to Avisos (per customer, on this device) → red badge on the tab.
    unseenAnnouncements: 0,
  }),

  getters: {
    sessionFor: (state) => (slug) =>
      !!state.token && state.slug === slug && !!state.expireAt && new Date(state.expireAt).getTime() > Date.now(),
  },

  actions: {
    restore() {
      this.token = localStorage.getItem(STORAGE_KEYS.token)
      this.expireAt = localStorage.getItem(STORAGE_KEYS.expireAt)
      this.slug = localStorage.getItem(STORAGE_KEYS.slug)
    },

    // Step 1: the e-mail must be a customer of this company. Returns where the code went (masked).
    async requestCode(slug, email) {
      const data = await post(`/portal/${slug}/code`, { email })
      return data.sent_to
    },

    // Step 2: e-mail + code → session.
    async login(slug, email, code) {
      const data = await post(`/portal/${slug}/login`, { email, code })
      this.token = data.token
      this.expireAt = data.expire_at
      this.slug = slug
      this.me = null
      localStorage.setItem(STORAGE_KEYS.token, data.token)
      localStorage.setItem(STORAGE_KEYS.expireAt, data.expire_at)
      localStorage.setItem(STORAGE_KEYS.slug, slug)
    },

    async loadMe() {
      const response = await portalFetch('/portal/me')
      if (response.ok) this.me = await response.json()
      return this.me
    },

    async checkAnnouncements() {
      const response = await portalFetch('/portal/announcements')
      if (!response.ok) return
      const seenAt = Date.parse(localStorage.getItem(seenKey(this.me)) || 0)
      this.unseenAnnouncements = (await response.json()).filter((a) => Date.parse(a.created_at) > seenAt).length
    },

    // ponytail: "seen" lives in this device's localStorage; move to the server if customers switch devices a lot.
    markAnnouncementsSeen(announcements) {
      if (announcements.length) localStorage.setItem(seenKey(this.me), announcements[0].created_at)
      this.unseenAnnouncements = 0
    },

    async logout({ remote = true } = {}) {
      const slug = this.slug
      if (remote && this.token) {
        await fetch(`${API_URL}/portal/logout`, { method: 'POST', headers: { Accept: 'application/json', Authorization: `Bearer ${this.token}` } }).catch(() => {})
      }
      this.token = this.expireAt = this.slug = this.me = null
      this.unseenAnnouncements = 0
      Object.values(STORAGE_KEYS).forEach((key) => localStorage.removeItem(key))

      const { default: router } = await import('@/router')
      if (slug && router.currentRoute.value.name !== 'PortalLogin') await router.push({ name: 'PortalLogin', params: { slug } })
    },
  },
})

const seenKey = (me) => `financial_botportal_seen_announcements_${me?.customer?.id}`

// Public portal calls (before a session exists): first validation message, else the API message.
async function post(url, body) {
  const response = await fetch(API_URL + url, {
    method: 'POST',
    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  })
  const data = await response.json().catch(() => ({}))
  if (!response.ok) {
    throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || 'Não foi possível continuar. Tente novamente.')
  }
  return data
}
