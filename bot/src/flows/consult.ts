import { type Bot, InlineKeyboard } from 'grammy'
import { PERIODS, type PeriodKey, formatBalance, periodRange } from '../balance.js'
import { detachCard, expired, isLiveCard, showCard, typing } from '../card.js'
import { backend } from '../clients/backend.js'
import { todayIn } from '../money.js'
import { timezoneOf } from '../config.js'
import { type BotContext, resetSession } from '../session.js'

// "Consultar" on the live card: pick a period → balancete (same aggregates as the web dashboard).

const periodCard = {
  text: '<b>Consulta</b>\n\nQual período você quer consultar?',
  keyboard: InlineKeyboard.from([
    ...[0, 2].map((i) => PERIODS.slice(i, i + 2).map((p) => InlineKeyboard.text(p.label, `sum:${p.key}`))),
    [InlineKeyboard.text('Cancelar', 'cancel')],
  ]),
}

const afterBalance = InlineKeyboard.from([[
  InlineKeyboard.text('📅 Outro período', 'consult'),
  InlineKeyboard.text('📝 Novo lançamento', 'launch'),
]])

export function registerConsult(bot: Bot<BotContext>): void {
  bot.callbackQuery('consult', async (ctx) => {
    await ctx.answerCallbackQuery()
    if (!isLiveCard(ctx)) await detachCard(ctx)
    resetSession(ctx)
    ctx.session.step = 'period'
    await showCard(ctx, periodCard)
  })

  bot.callbackQuery(/^sum:(month|last_month|year|all)$/, async (ctx) => {
    if (!isLiveCard(ctx) || ctx.session.step !== 'period') return expired(ctx)
    await ctx.answerCallbackQuery()
    await typing(ctx)

    const key = ctx.match[1] as PeriodKey
    const { from, to } = periodRange(key, todayIn(timezoneOf(ctx)))
    const summary = await backend.summary(ctx.from.id, from, to)
    const label = PERIODS.find((period) => period.key === key)!.label

    resetSession(ctx)
    await showCard(ctx, { text: formatBalance(summary, label), keyboard: afterBalance }, { final: true })
  })
}
