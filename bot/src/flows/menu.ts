import { Bot, InlineKeyboard } from 'grammy'
import { backend } from '../clients/backend.js'
import { type BotContext, escapeHtml, resetSession } from '../session.js'

// Entry point of every conversation: any message (or /start) greets with this menu.

export const mainMenu = new InlineKeyboard()
  .text('📝 Lançamento', 'launch')
  .text('📊 Consultar', 'consult')
  .row()
  .text('❌ Cancelar', 'cancel')

export async function showMenu(ctx: BotContext, text = 'O que deseja fazer?'): Promise<void> {
  await ctx.reply(text, { reply_markup: mainMenu, parse_mode: 'HTML' })
}

/** Checks activation with the backend (403 → replyError asks for the code), then greets. */
async function greet(ctx: BotContext): Promise<void> {
  await backend.me(ctx.from!.id)
  resetSession(ctx)
  const name = ctx.from?.first_name ? `, ${escapeHtml(ctx.from.first_name)}` : ''
  await showMenu(ctx, `👋 Olá${name}! O que deseja fazer?`)
}

export function registerMenu(bot: Bot<BotContext>): void {
  bot.command('start', greet)

  const cancel = async (ctx: BotContext) => {
    resetSession(ctx)
    await ctx.reply('Tudo bem, operação cancelada. Quando precisar, é só me mandar uma mensagem. 👋')
  }
  bot.command('cancelar', cancel)
  bot.callbackQuery('cancel', async (ctx) => {
    await ctx.answerCallbackQuery()
    await ctx.editMessageReplyMarkup().catch(() => {})
    await cancel(ctx)
  })
}

/**
 * Must be registered after every flow: only messages no step handled get here. Idle (or not yet
 * activated) → greeting/menu; in the middle of a flow → point back to the pending question
 * instead of silently dropping the draft.
 */
export function registerMenuFallback(bot: Bot<BotContext>): void {
  bot.on('message', async (ctx) => {
    if (ctx.session.step === 'idle' || ctx.session.step === 'awaiting_code') {
      await greet(ctx)
      return
    }
    await ctx.reply('Responda à pergunta acima (ou use os botões), ou toque em ❌ Cancelar.', {
      reply_markup: new InlineKeyboard().text('❌ Cancelar', 'cancel'),
    })
  })
}
