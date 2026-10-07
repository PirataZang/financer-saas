import { Bot, GrammyError } from 'grammy'
import { BackendError, backend } from '../clients/backend.js'
import { log } from '../log.js'
import { type BotContext, resetSession } from '../session.js'
import { showMenu } from './menu.js'

const ASK_CODE = '🔒 Para usar o bot, envie o <b>código de ativação</b>.'

/**
 * Turns any error from a handler into a reply. Not-activated (or revoked) users are sent to the
 * activation step from anywhere in the flow; the wizard state is kept otherwise so the user can retry.
 */
export async function replyError(ctx: BotContext, error: unknown): Promise<void> {
  if (error instanceof BackendError) {
    if (error.code === 'BOT_NOT_ACTIVATED') {
      resetSession(ctx)
      ctx.session.step = 'awaiting_code'
      await ctx.reply(ASK_CODE, { parse_mode: 'HTML' })
      return
    }
    if (error.status === 429) {
      await ctx.reply('⏳ Muitas tentativas. Aguarde alguns minutos e tente de novo.')
      return
    }
    if (error.status === 422) {
      await ctx.reply(`⚠️ ${error.message}`)
      return
    }
  }

  log('error', { message: error instanceof Error ? error.message : String(error), status: error instanceof BackendError ? error.status : undefined })
  if (!(error instanceof GrammyError)) await ctx.reply('⚠️ Não foi possível concluir agora. Tente novamente em instantes.').catch(() => {})
}

export function registerActivation(bot: Bot<BotContext>): void {
  bot.filter((ctx) => ctx.session.step === 'awaiting_code').on('message:text', async (ctx) => {
    // The code shouldn't linger in the chat history.
    await ctx.deleteMessage().catch(() => {})
    await backend.activate(ctx.from, ctx.message.text)
    log('activation.succeeded', { telegramUserId: ctx.from.id })
    resetSession(ctx)
    await showMenu(ctx, '✅ Bot ativado!\n\nO que deseja fazer?')
  })
}
