import type { Context, InlineKeyboard, SessionFlavor } from 'grammy'
import type { CategoryOption, TransactionType } from './clients/backend.js'

// Conversation state per chat. Activation itself lives in the backend (source of truth);
// this is only "where are we in the wizard". ponytail: in-memory, a restart drops half-filled
// drafts (never saved data) — swap for grammy's Redis storage if that ever matters.
export type Step = 'idle' | 'awaiting_code' | 'type' | 'amount' | 'description' | 'category' | 'confirm' | 'period'

export interface Draft {
  /** One per lançamento; sent as the idempotency key, so a retried confirm never saves twice. */
  id?: string
  type?: TransactionType
  amount?: string
  description?: string
  category?: CategoryOption
}

export interface SessionData {
  step: Step
  draft: Draft
  categories: CategoryOption[]
  /** The "live" message the bot edits in place (see showCard). Final messages are not tracked. */
  cardId?: number
  /** What the live card shows, so a hint or error can be added without losing it. */
  view?: { text: string; keyboard?: InlineKeyboard }
  /** The company's timezone, from /bot/me on every greeting (fallback BOT_TIMEZONE after a restart). */
  timezone?: string
}

export type BotContext = Context & SessionFlavor<SessionData>

export const initialSession = (): SessionData => ({ step: 'idle', draft: {}, categories: [] })

/** Clears the flow but keeps the live card, so the next screen replaces it in place. */
export function resetSession(ctx: BotContext): void {
  ctx.session = { ...initialSession(), cardId: ctx.session.cardId, view: ctx.session.view, timezone: ctx.session.timezone }
}

export function escapeHtml(text: string): string {
  return text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
}
