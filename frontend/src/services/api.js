import { useAuthStore } from '@/stores/auth'

// Base de todas as chamadas à API. Vem de VITE_API_URL no .env da raiz:
// local http://localhost:8000/api (backend direto), VPS /peppercore-admin/api (rota do Nginx).
// Vazia → docker-compose usa o backend em http://localhost:${BACKEND_PORT}/api.
export const API_URL = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/+$/, '')

export async function apiFetch(url, options = {}) {
  const auth = useAuthStore()
  const headers = {
    ...(options.headers || {}),
  }

  if (!headers['Content-Type'] && options.body) {
    headers['Content-Type'] = 'application/json'
  }

  if (auth.token) {
    headers.Authorization = `Bearer ${auth.token}`
  }

  const response = await fetch(API_URL + url, {
    ...options,
    headers,
  })

  if (response.status === 401) {
    await auth.logout()
    throw new Error('Sessão expirada. Faça login novamente.')
  }

  // Permission changed server-side: resync so menus/buttons stop offering the action.
  if (response.status === 403) {
    auth.refreshUser().catch(() => {})
  }

  return response
}
