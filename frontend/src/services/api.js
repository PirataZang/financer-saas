import { isCompanyInactive, useAuthStore } from '@/stores/auth'
import { usePortalStore } from '@/stores/portal'
import { download } from '@/utils/export'

// Base de todas as chamadas à API. Vem de VITE_API_URL no .env da raiz:
// local http://localhost:8000/api (backend direto), VPS /peppercore-admin/api (rota do Nginx).
// Vazia → docker-compose usa o backend em http://localhost:${BACKEND_PORT}/api.
export const API_URL = (import.meta.env.VITE_API_URL || 'http://localhost:8000/api').replace(/\/+$/, '')

export async function apiFetch(url, options = {}) {
  const auth = useAuthStore()
  const headers = {
    ...auth.headers,
    ...(options.headers || {}),
  }

  // FormData: o navegador define o Content-Type com o boundary.
  if (!headers['Content-Type'] && options.body && !(options.body instanceof FormData)) {
    headers['Content-Type'] = 'application/json'
  }

  const response = await fetch(API_URL + url, {
    ...options,
    headers,
  })

  if (response.status === 401) {
    await auth.logout()
    throw new Error('Sessão expirada. Faça login novamente.')
  }

  if (await isCompanyInactive(response)) {
    await auth.logout()
    throw new Error('Acesso da empresa suspenso.')
  }

  // Permission changed server-side: resync so menus/buttons stop offering the action.
  if (response.status === 403) {
    auth.refreshUser().catch(() => {})
  }

  return response
}

// Baixa um arquivo gerado pela API (precisa do token, então não dá para ser um <a href>).
export async function downloadFile(url, filename) {
  const response = await apiFetch(url)
  if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message || 'Não foi possível baixar o arquivo.')
  download(await response.blob(), filename)
}

// Portal do cliente: its own session (a customer's, never a user's), so both can be open in one browser.
// 401 or an inactive company ends the portal session and goes back to the portal's login.
export async function portalFetch(url, options = {}) {
  const portal = usePortalStore()
  const headers = {
    Accept: 'application/json',
    ...(portal.token ? { Authorization: `Bearer ${portal.token}` } : {}),
    ...(options.body ? { 'Content-Type': 'application/json' } : {}),
    ...(options.headers || {}),
  }

  const response = await fetch(API_URL + url, { ...options, headers })

  if (response.status === 401 || (await isCompanyInactive(response))) {
    await portal.logout({ remote: false })
    throw new Error('Sessão expirada. Entre novamente.')
  }

  return response
}
