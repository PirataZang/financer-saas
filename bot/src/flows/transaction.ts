import { Bot, InlineKeyboard } from 'grammy'
import { backend, type CategoryOption, type TransactionType } from '../clients/backend.js'
import { config } from '../config.js'
import { log } from '../log.js'
import { formatMoney, parseMoney, todayIn } from '../money.js'
import { type BotContext, type Draft, escapeHtml, resetSession } from '../session.js'

// Wizard: type → amount → description → category → confirm. Only collects input; amounts and
// categories are validated again by the backend, which owns every finance rule.

const TYPE_LABEL: Record<TransactionType, string> = { income: '💰 Receita', expense: '💸 Despesa' }

export const mainMenu = new InlineKeyboard().text(TYPE_LABEL.income, 'new:income').text(TYPE_LABEL.expense, 'new:expense')

const cancelButton = new InlineKeyboard().text('❌ Cancelar', 'cancel')

function categoryKeyboard(categories: CategoryOption[]): InlineKeyboard {
  const keyboard = new InlineKeyboard()
  categories.forEach((category, index) => {
    keyboard.text(`${category.icon ?? ''} ${category.label}`.trim(), `cat:${category.value}`)
    if (index % 2 === 1) keyboard.row()
  })
  return keyboard.row().text('❌ Cancelar', 'cancel')
}

function summary(draft: Draft): string {
  return [
    '<b>Confira os dados:</b>',
    '',
    `Tipo: ${draft.type ? TYPE_LABEL[draft.type] : ''}`,
    `Valor: ${formatMoney(draft.amount ?? '0')}`,
    `Categoria: ${escapeHtml(`${draft.category?.icon ?? ''} ${draft.category?.label ?? ''}`.trim())}`,
    `Descrição: ${escapeHtml(draft.description ?? '')}`,
  ].join('\n')
}

export function registerTransaction(bot: Bot<BotContext>): void {
  const cancel = async (ctx: BotContext) => {
    resetSession(ctx)
    await ctx.reply('Lançamento cancelado.\n\nO que deseja lançar?', { reply_markup: mainMenu })
  }
  bot.command('cancelar', cancel)
  bot.callbackQuery('cancel', async (ctx) => {
    await ctx.answerCallbackQuery()
    await ctx.editMessageReplyMarkup().catch(() => {})
    await cancel(ctx)
  })

  bot.callbackQuery(/^new:(income|expense)$/, async (ctx) => {
    await ctx.answerCallbackQuery()
    resetSession(ctx)
    ctx.session.draft.type = ctx.match[1] as TransactionType
    ctx.session.step = 'amount'
    await ctx.reply(`${TYPE_LABEL[ctx.session.draft.type]}\n\nQual o valor? Ex.: <code>150,50</code>`, {
      parse_mode: 'HTML',
      reply_markup: cancelButton,
    })
  })

  bot.filter((ctx) => ctx.session.step === 'amount').on('message:text', async (ctx) => {
    const amount = parseMoney(ctx.message.text)
    if (!amount) {
      await ctx.reply('Valor inválido. Envie algo como <code>150,50</code> ou <code>1.234,56</code>.', { parse_mode: 'HTML' })
      return
    }
    ctx.session.draft.amount = amount
    ctx.session.step = 'description'
    await ctx.reply(`Valor: ${formatMoney(amount)}\n\nDescrição da transação:`, { reply_markup: cancelButton })
  })

  bot.filter((ctx) => ctx.session.step === 'description').on('message:text', async (ctx) => {
    const description = ctx.message.text.trim()
    if (!description || description.length > 255) {
      await ctx.reply('Envie uma descrição de até 255 caracteres.')
      return
    }

    const type = ctx.session.draft.type!
    const categories = await backend.categories(ctx.from.id, type)
    if (!categories.length) {
      resetSession(ctx)
      await ctx.reply('Nenhuma categoria ativa para esse tipo. Cadastre em Financeiro > Categorias.', { reply_markup: mainMenu })
      return
    }

    ctx.session.draft.description = description
    ctx.session.categories = categories
    ctx.session.step = 'category'
    await ctx.reply('Escolha uma categoria:', { reply_markup: categoryKeyboard(categories) })
  })

  bot.callbackQuery(/^cat:(\d+)$/, async (ctx) => {
    const category = ctx.session.step === 'category' ? ctx.session.categories.find((c) => c.value === Number(ctx.match[1])) : undefined
    if (!category) {
      await ctx.answerCallbackQuery({ text: 'Esse menu expirou. Use /start.' })
      return
    }
    await ctx.answerCallbackQuery()
    ctx.session.draft.category = category
    ctx.session.step = 'confirm'
    await ctx.editMessageText(summary(ctx.session.draft), {
      parse_mode: 'HTML',
      reply_markup: new InlineKeyboard().text('✅ Confirmar', 'confirm').text('❌ Cancelar', 'cancel'),
    })
  })

  bot.callbackQuery('confirm', async (ctx) => {
    const { draft } = ctx.session
    if (ctx.session.step !== 'confirm' || !draft.type || !draft.amount || !draft.description || !draft.category) {
      await ctx.answerCallbackQuery({ text: 'Nada para confirmar. Use /start.' })
      return
    }
    await ctx.answerCallbackQuery()

    // Same confirm message → same key: a retried/duplicated callback never creates a second entry.
    const transaction = await backend.createTransaction(ctx.from.id, {
      type: draft.type,
      amount: draft.amount,
      description: draft.description,
      category_id: draft.category.value,
      date: todayIn(config.timezone),
      idempotency_key: `${ctx.chat!.id}:${ctx.callbackQuery.message!.message_id}`,
    })
    log('transaction.created', { telegramUserId: ctx.from.id, transactionId: transaction.id })

    const saved = summary(draft).replace('<b>Confira os dados:</b>', '<b>✅ Lançamento salvo!</b>')
    resetSession(ctx)
    await ctx.editMessageText(saved, { parse_mode: 'HTML' }).catch(() => {})
    await ctx.reply('Deseja lançar outro?', { reply_markup: mainMenu })
  })
}
