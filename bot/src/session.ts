import type { Context, SessionFlavor } from 'grammy'
import type { CategoryOption, TransactionType } from './clients/backend.js'

// Conversation state per chat. Activation itself lives in the backend (source of truth);
// this is only "where are we in the wizard". ponytail: in-memory, a restart drops half-filled
// drafts (never saved data) — swap for grammy's Redis storage if that ever matters.
export type Step = 'idle' | 'awaiting_code' | 'amount' | 'description' | 'category' | 'confirm'

export interface Draft {
  type?: TransactionType
  amount?: string
  description?: string
  category?: CategoryOption
}

export interface SessionData {
  step: Step
  draft: Draft
  categories: CategoryOption[]
}

export type BotContext = Context & SessionFlavor<SessionData>

export const initialSession = (): SessionData => ({ step: 'idle', draft: {}, categories: [] })

export function resetSession(ctx: BotContext): void {
  ctx.session = initialSession()
}

export function escapeHtml(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}
