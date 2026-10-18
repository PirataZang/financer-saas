import type { Summary } from '../balance.js'
import { config } from '../config.js'

// The bot's only door to data: /api/bot/* on the backend. No database access, no finance rules
// here — the backend validates everything again.

export type TransactionType = 'income' | 'expense'

export interface TelegramUser {
  id: number
  username?: string
  first_name?: string
}

export interface CategoryOption {
  value: number
  label: string
  icon: string | null
  color: string
}

export interface NewTransaction {
  type: TransactionType
  amount: string
  description: string
  category_id: number
  date: string
  idempotency_key: string
}

export interface Transaction {
  id: number
  amount: string
}

export class BackendError extends Error {
  constructor(
    readonly status: number,
    readonly code: string | undefined,
    message: string,
  ) {
    super(message)
  }
}

async function request<T>(telegramUserId: number, method: 'GET' | 'POST', path: string, body?: unknown): Promise<T> {
  const response = await fetch(config.backendUrl + path, {
    method,
    headers: {
      Authorization: `Bearer ${config.backendToken}`,
      'X-Telegram-User-Id': String(telegramUserId),
      Accept: 'application/json',
      ...(body ? { 'Content-Type': 'application/json' } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
    signal: AbortSignal.timeout(10_000),
  })

  const data = (await response.json().catch(() => ({}))) as {
    code?: string
    message?: string
    errors?: Record<string, string[]>
  }

  if (!response.ok) {
    const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : undefined
    throw new BackendError(response.status, data.code, firstError ?? data.message ?? `HTTP ${response.status}`)
  }

  return data as T
}

export const backend = {
  /** The linked account; timezone = the company's "today" (Configurações › Financeiro). */
  me: (telegramUserId: number) => request<{ company: string; timezone?: string }>(telegramUserId, 'GET', '/bot/me'),

  /** Unlinks this Telegram user (they can send another code afterwards). */
  disconnect: (telegramUserId: number) => request<unknown>(telegramUserId, 'POST', '/bot/disconnect'),

  activate: (user: TelegramUser, code: string) =>
    request<unknown>(user.id, 'POST', '/bot/activate', { code, username: user.username, first_name: user.first_name }),

  categories: (telegramUserId: number, type: TransactionType) =>
    request<CategoryOption[]>(telegramUserId, 'GET', `/bot/finance/categories?type=${type}`),

  summary: (telegramUserId: number, from: string | null, to: string) =>
    request<Summary>(telegramUserId, 'GET', `/bot/finance/summary?${new URLSearchParams(from ? { from, to } : { to })}`),

  createTransaction: (telegramUserId: number, transaction: NewTransaction) =>
    request<Transaction>(telegramUserId, 'POST', '/bot/finance/transactions', transaction),
}
