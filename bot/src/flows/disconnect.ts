import type { Bot } from 'grammy'
import { detachCard, disconnectCard, expired, isLiveCard, menuCard, showCard, typing } from '../card.js'
import { backend } from '../clients/backend.js'
import { log } from '../log.js'
import { type BotContext, resetSession } from '../session.js'
import { askForCode } from './activation.js'

// "Desconectar" (/desconectar, in Telegram's command menu): confirm → unlink → waiting for a code again, so the user
// can link to another company (or back to this one) without an admin. Revoked users never get here:
// the backend answers BOT_NOT_ACTIVATED and replyError asks for the code, which won't work for them.

async function confirm(ctx: BotContext): Promise<void> {
  const { company } = await backend.me(ctx.from!.id)
  resetSession(ctx)
  await showCard(ctx, disconnectCard(company))
}

export function registerDisconnect(bot: Bot<BotContext>): void {
  bot.command('desconectar', async (ctx) => {
    await ctx.deleteMessage().catch(() => {})
    await detachCard(ctx)
    await confirm(ctx)
  })

  bot.callbackQuery('menu', async (ctx) => {
    if (!isLiveCard(ctx)) return expired(ctx)
    await ctx.answerCallbackQuery()
    resetSession(ctx)
    await showCard(ctx, menuCard(ctx.from.first_name))
  })

  bot.callbackQuery('disconnect:yes', async (ctx) => {
    if (!isLiveCard(ctx)) return expired(ctx)
    await ctx.answerCallbackQuery()
    await typing(ctx)
    const { company } = await backend.me(ctx.from.id)
    await backend.disconnect(ctx.from.id)
    log('disconnected', { telegramUserId: ctx.from.id })
    await askForCode(ctx, { disconnectedFrom: company })
  })
}
