import { type Bot, GrammyError } from 'grammy'
import { CODE_INPUT, codePrompt, detachCard, menuCard, showCard } from '../card.js'
import { BackendError, backend } from '../clients/backend.js'
import { log } from '../log.js'
import { type BotContext, escapeHtml, resetSession } from '../session.js'

/**
 * Not linked: greets and waits for the code. A new message (not the edited card): only a new
 * message can carry the reply box (CODE_INPUT), so the user just types the code.
 */
export async function askForCode(ctx: BotContext, options: Parameters<typeof codePrompt>[0] = {}): Promise<void> {
  await detachCard(ctx)
  resetSession(ctx)
  ctx.session.timezone = undefined
  ctx.session.step = 'awaiting_code'
  await ctx.reply(codePrompt({ firstName: ctx.from?.first_name, ...options }), { parse_mode: 'HTML', reply_markup: CODE_INPUT })
}

/**
 * Turns any error from a handler into something the user can act on. Not-activated (or revoked)
 * users go to the activation step from anywhere; otherwise the wizard state is kept and the error
 * appears on the live card, so the user can simply try again.
 */
export async function replyError(ctx: BotContext, error: unknown): Promise<void> {
  if (error instanceof BackendError && error.code === 'BOT_NOT_ACTIVATED') return askForCode(ctx)

  let message = 'Não consegui concluir agora. Tente novamente em alguns instantes.'
  if (error instanceof BackendError && error.status === 429) message = 'Muitas tentativas seguidas. Aguarde alguns minutos e tente de novo.'
  else if (error instanceof BackendError && (error.status === 422 || error.code === 'COMPANY_INACTIVE')) message = error.message
  else log('error', { message: error instanceof Error ? error.message : String(error), status: error instanceof BackendError ? error.status : undefined })

  if (error instanceof GrammyError) return // Telegram itself failed: nothing useful to show.
  // Wrong code (or too many tries): ask again, with the reply box open.
  if (ctx.session.step === 'awaiting_code') return askForCode(ctx, { error: message })
  const { view } = ctx.session
  await (view ? showCard(ctx, view, { note: message }) : ctx.reply(`⚠️ ${message}`)).catch(() => {})
}

/** Typed code or the t.me link's start payload. Errors (invalid, revoked, other company) go to replyError. */
export async function activateWith(ctx: BotContext, code: string): Promise<void> {
  const from = ctx.from!
  await backend.activate(from, code)
  log('activation.succeeded', { telegramUserId: from.id })
  resetSession(ctx)
  ctx.session.timezone = (await backend.me(from.id)).timezone
  const name = from.first_name ? `, ${escapeHtml(from.first_name)}` : ''
  await showCard(ctx, menuCard(undefined, `✅ <b>Acesso liberado${name}. Seja bem-vindo!</b>\n\nO que você gostaria de fazer?`))
}

export function registerActivation(bot: Bot<BotContext>): void {
  bot.filter((ctx) => ctx.session.step === 'awaiting_code').on('message:text', async (ctx) => {
    // The code shouldn't linger in the chat history.
    await ctx.deleteMessage().catch(() => {})
    await activateWith(ctx, ctx.message.text)
  })
}
