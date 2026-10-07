import { InlineKeyboard } from 'grammy'
import type { CategoryOption, TransactionType } from './clients/backend.js'
import { formatMoney } from './money.js'
import { type BotContext, type Draft, escapeHtml } from './session.js'

// Every screen of the conversation, as text + buttons (pure: tested in card.test.ts), and showCard,
// which draws them on a single message edited in place instead of piling up new ones.

export type CardStep = 'amount' | 'description' | 'category' | 'confirm'

export interface Card {
  text: string
  keyboard?: InlineKeyboard
}

const STEPS: CardStep[] = ['amount', 'description', 'category', 'confirm']
const DIVIDER = '━━━━━━━━━━━━━━━━'
const TITLE: Record<TransactionType, string> = { income: '💰 Nova receita', expense: '🧾 Nova despesa' }
const NOUN: Record<TransactionType, string> = { income: 'receita', expense: 'despesa' }
const PROMPT: Record<CardStep, string> = {
  amount: 'Qual é o valor?\n<i>Ex.: 150,50 ou 1.234,56</i>',
  description: 'Como você quer descrever este lançamento?\n<i>Ex.: Compra do supermercado</i>',
  category: 'Em qual categoria ele se encaixa?',
  confirm: 'Está tudo certo? Toque em <b>Confirmar</b> para registrar.',
}

const button = InlineKeyboard.text
const navRow = [button('← Voltar', 'back'), button('Cancelar', 'cancel')]
const mainButtons = [button('📝 Novo lançamento', 'launch'), button('📊 Consultar', 'consult')]

const brDate = (date: string) => date.split('-').reverse().join('/')
const categoryLabel = (category: CategoryOption) => `${category.icon ?? ''} ${category.label}`.trim()
const hello = (firstName?: string) => (firstName ? `, ${escapeHtml(firstName)}` : '')

function fields(draft: Draft, dateLabel: string): string[] {
  return [
    `<b>Valor:</b> ${draft.amount ? formatMoney(draft.amount) : '—'}`,
    `<b>Descrição:</b> ${draft.description ? escapeHtml(draft.description) : '—'}`,
    `<b>Categoria:</b> ${draft.category ? escapeHtml(categoryLabel(draft.category)) : '—'}`,
    `<b>Data:</b> ${dateLabel}`,
  ]
}

function chunk<T>(items: T[], size: number): T[][] {
  const rows: T[][] = []
  for (let i = 0; i < items.length; i += size) rows.push(items.slice(i, i + size))
  return rows
}

export function menuCard(firstName?: string, heading = `Olá${hello(firstName)}! O que você gostaria de fazer hoje?`): Card {
  return { text: heading, keyboard: InlineKeyboard.from([mainButtons]) }
}

export function typeCard(): Card {
  return {
    text: '<b>Novo lançamento</b>\n\nÉ uma receita ou uma despesa?',
    keyboard: InlineKeyboard.from([[button('💰 Receita', 'new:income'), button('🧾 Despesa', 'new:expense')], [button('Cancelar', 'cancel')]]),
  }
}

/** today = YYYY-MM-DD in the user's timezone (entries are always dated today). */
export function transactionCard(draft: Draft, step: CardStep, today: string, categories: CategoryOption[] = []): Card {
  const text = [
    `<b>${TITLE[draft.type ?? 'expense']}</b> · Etapa ${STEPS.indexOf(step) + 1} de ${STEPS.length}`,
    DIVIDER,
    ...fields(draft, `Hoje, ${brDate(today).slice(0, 5)}`),
    DIVIDER,
    PROMPT[step],
  ].join('\n')

  const rows =
    step === 'category'
      ? [...chunk(categories.map((c) => button(categoryLabel(c), `cat:${c.value}`)), 2), navRow]
      : step === 'confirm'
        ? [[button('✅ Confirmar', 'confirm')], navRow]
        : [navRow]
  return { text, keyboard: InlineKeyboard.from(rows) }
}

export function receiptCard(draft: Draft, id: number, date: string, firstName?: string): Card {
  return {
    text: [
      '✅ <b>Lançamento registrado</b>',
      `Pronto${hello(firstName)}! Sua ${NOUN[draft.type ?? 'expense']} foi salva.`,
      DIVIDER,
      ...fields(draft, brDate(date)),
      DIVIDER,
      `<i>Nº ${id} · via Telegram</i>`,
    ].join('\n'),
    keyboard: InlineKeyboard.from([mainButtons]),
  }
}

export const notice = (text: string, withMenu = false): Card => ({
  text,
  keyboard: withMenu ? InlineKeyboard.from([mainButtons]) : undefined,
})

const withNote = (text: string, note?: string) => (note ? `${text}\n\n⚠️ ${escapeHtml(note)}` : text)

/**
 * Draws a screen on the live card (edits it), or sends a new message when there is none or it can't
 * be edited. `final` screens (receipt, balancete, cancelled) stay in the chat as history: they stop
 * being the live card, so the next flow opens below them instead of overwriting them.
 */
export async function showCard(ctx: BotContext, card: Card, { note, final = false }: { note?: string; final?: boolean } = {}): Promise<void> {
  const options = { parse_mode: 'HTML' as const, reply_markup: card.keyboard }
  const text = withNote(card.text, note)
  let id = ctx.session.cardId

  if (id) {
    await ctx.api.editMessageText(ctx.chat!.id, id, text, options).catch((error: Error) => {
      if (!error.message.includes('message is not modified')) id = undefined
    })
  }
  if (!id) id = (await ctx.reply(text, options)).message_id

  ctx.session.cardId = final ? undefined : id
  ctx.session.view = final ? undefined : card
}

/** Leaves the live card behind (buttons removed) so the next showCard opens a new message. */
export async function detachCard(ctx: BotContext): Promise<void> {
  if (ctx.session.cardId) await ctx.api.editMessageReplyMarkup(ctx.chat!.id, ctx.session.cardId).catch(() => {})
  ctx.session.cardId = undefined
  ctx.session.view = undefined
}

/** Is this button press on the live card? Buttons on old messages are expired. */
export const isLiveCard = (ctx: BotContext) => ctx.callbackQuery?.message?.message_id === ctx.session.cardId

export async function expired(ctx: BotContext): Promise<void> {
  await ctx.answerCallbackQuery({ text: 'Esta tela expirou. Mande qualquer mensagem para recomeçar.' })
  await ctx.editMessageReplyMarkup().catch(() => {})
}

/** "digitando…" while the backend answers. */
export const typing = (ctx: BotContext) => ctx.replyWithChatAction('typing').catch(() => {})
