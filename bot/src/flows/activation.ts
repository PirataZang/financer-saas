import { type Bot, GrammyError } from 'grammy'
import { detachCard, menuCard, notice, showCard } from '../card.js'
import { BackendError, backend } from '../clients/backend.js'
import { log } from '../log.js'
import { type BotContext, escapeHtml, resetSession } from '../session.js'

const ASK_CODE = '🔒 <b>Acesso restrito</b>\n\nOlá! Para começar, envie o código de ativação que você recebeu.'

/**
 * Turns any error from a handler into something the user can act on. Not-activated (or revoked)
 * users go to the activation step from anywhere; otherwise the wizard state is kept and the error
 * appears on the live card, so the user can simply try again.
 */
export async function replyError(ctx: BotContext, error: unknown): Promise<void> {
  if (error instanceof BackendError && error.code === 'BOT_NOT_ACTIVATED') {
    await detachCard(ctx)
    resetSession(ctx)
    ctx.session.step = 'awaiting_code'
    await showCard(ctx, notice(ASK_CODE), { final: true })
    return
  }

  let message = 'Não consegui concluir agora. Tente novamente em alguns instantes.'
  if (error instanceof BackendError && error.status === 429) message = 'Muitas tentativas seguidas. Aguarde alguns minutos e tente de novo.'
  else if (error instanceof BackendError && error.status === 422) message = error.message
  else log('error', { message: error instanceof Error ? error.message : String(error), status: error instanceof BackendError ? error.status : undefined })

  if (error instanceof GrammyError) return // Telegram itself failed: nothing useful to show.
  const { view } = ctx.session
  await (view ? showCard(ctx, view, { note: message }) : ctx.reply(`⚠️ ${message}`)).catch(() => {})
}

export function registerActivation(bot: Bot<BotContext>): void {
  bot.filter((ctx) => ctx.session.step === 'awaiting_code').on('message:text', async (ctx) => {
    // The code shouldn't linger in the chat history.
    await ctx.deleteMessage().catch(() => {})
    await backend.activate(ctx.from, ctx.message.text)
    log('activation.succeeded', { telegramUserId: ctx.from.id })
    resetSession(ctx)
    const name = ctx.from.first_name ? `, ${escapeHtml(ctx.from.first_name)}` : ''
    await showCard(ctx, menuCard(undefined, `✅ <b>Acesso liberado${name}. Seja bem-vindo!</b>\n\nO que você gostaria de fazer?`))
  })
}
