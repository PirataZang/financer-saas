import { Bot, InlineKeyboard } from 'grammy'
import { PERIODS, type PeriodKey, formatBalance, periodRange } from '../balance.js'
import { backend } from '../clients/backend.js'
import { config } from '../config.js'
import { todayIn } from '../money.js'
import type { BotContext } from '../session.js'
import { showMenu } from './menu.js'

// "Consultar": pick a period → balancete built from the backend's aggregates (same as the web dashboard).

const periodKeyboard = new InlineKeyboard()
PERIODS.forEach((period, index) => {
  periodKeyboard.text(period.label, `sum:${period.key}`)
  if (index % 2 === 1) periodKeyboard.row()
})
periodKeyboard.text('❌ Cancelar', 'cancel')

export function registerConsult(bot: Bot<BotContext>): void {
  bot.callbackQuery('consult', async (ctx) => {
    await ctx.answerCallbackQuery()
    await ctx.reply('📊 Qual período deseja consultar?', { reply_markup: periodKeyboard })
  })

  bot.callbackQuery(/^sum:(month|last_month|year|all)$/, async (ctx) => {
    await ctx.answerCallbackQuery()
    const key = ctx.match[1] as PeriodKey
    const { from, to } = periodRange(key, todayIn(config.timezone))
    const summary = await backend.summary(ctx.from.id, from, to)
    const label = PERIODS.find((period) => period.key === key)!.label

    await ctx.editMessageText(formatBalance(summary, label), { parse_mode: 'HTML' }).catch(() =>
      ctx.reply(formatBalance(summary, label), { parse_mode: 'HTML' }),
    )
    await showMenu(ctx, 'Algo mais?')
  })
}
