import type { Bot } from 'grammy'
import { detachCard, menuCard, notice, showCard } from '../card.js'
import { backend } from '../clients/backend.js'
import { type BotContext, resetSession } from '../session.js'

// Entry point of every conversation: any message (or /start) opens the menu card.

/** Checks activation with the backend (403 → replyError asks for the code), then greets below. */
async function greet(ctx: BotContext): Promise<void> {
  await backend.me(ctx.from!.id)
  await detachCard(ctx)
  resetSession(ctx)
  await showCard(ctx, menuCard(ctx.from?.first_name))
}

export function registerMenu(bot: Bot<BotContext>): void {
  bot.command('start', greet)

  const cancel = async (ctx: BotContext) => {
    const hadFlow = ctx.session.step !== 'idle'
    resetSession(ctx)
    await showCard(
      ctx,
      notice(hadFlow ? 'Tudo certo, lançamento descartado. Quando precisar, é só me chamar.' : 'Não há nada em andamento. Quando precisar, é só me chamar.'),
      { final: true },
    )
  }
  bot.command('cancelar', async (ctx) => {
    await ctx.deleteMessage().catch(() => {})
    await cancel(ctx)
  })
  bot.callbackQuery('cancel', async (ctx) => {
    await ctx.answerCallbackQuery()
    if (ctx.callbackQuery.message?.message_id !== ctx.session.cardId) {
      await ctx.editMessageReplyMarkup().catch(() => {})
      return
    }
    await cancel(ctx)
  })
}

/**
 * Must be registered after every flow: only messages no step handled get here. Idle (or not yet
 * activated) → menu; in the middle of a flow → the typed text is removed and the live card shows a
 * hint, instead of a new message or silently dropping the draft.
 */
export function registerMenuFallback(bot: Bot<BotContext>): void {
  bot.on('message', async (ctx) => {
    const { step, view } = ctx.session
    if (step === 'idle' || step === 'awaiting_code' || !view) {
      await greet(ctx)
      return
    }
    await ctx.deleteMessage().catch(() => {})
    await showCard(ctx, view, { note: 'Responda à pergunta acima ou use os botões para continuar.' })
  })
}
