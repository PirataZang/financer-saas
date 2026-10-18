import { randomUUID } from 'node:crypto'
import type { Bot } from 'grammy'
import { type CardStep, detachCard, expired, isLiveCard, notice, receiptCard, showCard, transactionCard, typeCard, typing } from '../card.js'
import { backend, type TransactionType } from '../clients/backend.js'
import { log } from '../log.js'
import { parseMoney, todayIn } from '../money.js'
import { timezoneOf } from '../config.js'
import { type BotContext, resetSession } from '../session.js'

// "Lançamento" wizard on the live card: type → 1 valor → 2 descrição → 3 categoria → 4 confirmar.
// Only collects input; amounts and categories are validated again by the backend, which owns every
// finance rule. Typed answers are deleted so the chat ends with just the receipt.

const render = (ctx: BotContext, note?: string) =>
  showCard(ctx, transactionCard(ctx.session.draft, ctx.session.step as CardStep, todayIn(timezoneOf(ctx)), ctx.session.categories), { note })

export function registerTransaction(bot: Bot<BotContext>): void {
  bot.callbackQuery('launch', async (ctx) => {
    await ctx.answerCallbackQuery()
    // From the menu card: replace it. From a receipt/balancete (history): open a new card below.
    if (!isLiveCard(ctx)) await detachCard(ctx)
    resetSession(ctx)
    ctx.session.step = 'type'
    await showCard(ctx, typeCard())
  })

  bot.callbackQuery(/^new:(income|expense)$/, async (ctx) => {
    if (!isLiveCard(ctx) || ctx.session.step !== 'type') return expired(ctx)
    await ctx.answerCallbackQuery()
    ctx.session.draft = { id: randomUUID(), type: ctx.match[1] as TransactionType }
    ctx.session.step = 'amount'
    await render(ctx)
  })

  bot.filter((ctx) => ctx.session.step === 'amount').on('message:text', async (ctx) => {
    await ctx.deleteMessage().catch(() => {})
    const amount = parseMoney(ctx.message.text)
    if (!amount) return render(ctx, 'Não entendi esse valor. Envie no formato 150,50 ou 1.234,56.')
    ctx.session.draft.amount = amount
    ctx.session.step = 'description'
    await render(ctx)
  })

  bot.filter((ctx) => ctx.session.step === 'description').on('message:text', async (ctx) => {
    await ctx.deleteMessage().catch(() => {})
    const description = ctx.message.text.trim()
    if (!description || description.length > 255) return render(ctx, 'A descrição deve ter até 255 caracteres.')

    await typing(ctx)
    const categories = await backend.categories(ctx.from.id, ctx.session.draft.type!)
    if (!categories.length) {
      resetSession(ctx)
      return showCard(ctx, notice('Não há categorias ativas para esse tipo de lançamento. Cadastre uma em <b>Financeiro › Categorias</b> e tente novamente.', true), { final: true })
    }
    ctx.session.draft.description = description
    ctx.session.categories = categories
    ctx.session.step = 'category'
    await render(ctx)
  })

  bot.callbackQuery(/^cat:(\d+)$/, async (ctx) => {
    const category = ctx.session.categories.find((c) => c.value === Number(ctx.match[1]))
    if (!isLiveCard(ctx) || ctx.session.step !== 'category' || !category) return expired(ctx)
    await ctx.answerCallbackQuery()
    ctx.session.draft.category = category
    ctx.session.step = 'confirm'
    await render(ctx)
  })

  // One step back, clearing what that step had filled.
  bot.callbackQuery('back', async (ctx) => {
    if (!isLiveCard(ctx)) return expired(ctx)
    await ctx.answerCallbackQuery()
    const { draft } = ctx.session
    switch (ctx.session.step) {
      case 'amount':
        ctx.session.step = 'type'
        return showCard(ctx, typeCard())
      case 'description':
        draft.amount = undefined
        ctx.session.step = 'amount'
        break
      case 'category':
        draft.description = undefined
        ctx.session.step = 'description'
        break
      case 'confirm':
        draft.category = undefined
        ctx.session.step = 'category'
        break
      default:
        return
    }
    await render(ctx)
  })

  bot.callbackQuery('confirm', async (ctx) => {
    const { draft } = ctx.session
    if (!isLiveCard(ctx) || ctx.session.step !== 'confirm' || !draft.id || !draft.type || !draft.amount || !draft.description || !draft.category) {
      return expired(ctx)
    }
    await ctx.answerCallbackQuery()
    await typing(ctx)

    const date = todayIn(timezoneOf(ctx))
    // draft.id is the idempotency key: a retried/duplicated confirm returns the same transaction.
    const transaction = await backend.createTransaction(ctx.from.id, {
      type: draft.type,
      amount: draft.amount,
      description: draft.description,
      category_id: draft.category.value,
      date,
      idempotency_key: draft.id,
    })
    log('transaction.created', { telegramUserId: ctx.from.id, transactionId: transaction.id })

    const receipt = receiptCard(draft, transaction.id, date, ctx.from.first_name)
    resetSession(ctx)
    await showCard(ctx, receipt, { final: true })
  })
}
