import { test } from 'node:test'
import assert from 'node:assert/strict'
import { CODE_INPUT, codePrompt, disconnectCard, menuCard, receiptCard, transactionCard } from './card.js'
import type { Draft } from './session.js'

const buttons = (card: { keyboard?: { inline_keyboard: { text: string; callback_data?: string }[][] } }) =>
  card.keyboard?.inline_keyboard.map((row) => row.map((b) => b.callback_data)) ?? []

const categories = [
  { value: 1, label: 'Mercado', icon: '🛒', color: '#000' },
  { value: 2, label: 'Contas', icon: null, color: '#000' },
  { value: 3, label: 'Outros', icon: '📦', color: '#000' },
]

test('the card shows the step, what is filled and what is missing', () => {
  const card = transactionCard({ type: 'expense', amount: '150.50' }, 'description', '2026-10-07')
  assert.match(card.text, /Nova despesa<\/b> · Etapa 2 de 4/)
  assert.match(card.text.replace(/ /g, ' '), /<b>Valor:<\/b> R\$ 150,50/)
  assert.match(card.text, /<b>Descrição:<\/b> —/)
  assert.match(card.text, /<b>Data:<\/b> Hoje, 07\/10/)
  assert.match(card.text, /Como você quer descrever/)
  assert.deepEqual(buttons(card), [['back', 'cancel']])
})

test('user text is escaped', () => {
  const card = transactionCard({ type: 'income', amount: '1.00', description: '<b>x</b> & y' }, 'category', '2026-10-07', categories)
  assert.match(card.text, /&lt;b&gt;x&lt;\/b&gt; &amp; y/)
  assert.match(card.text, /Nova receita/)
})

test('keyboards: categories two per row, no empty rows, confirm on the last step', () => {
  assert.deepEqual(buttons(transactionCard({ type: 'expense' }, 'category', '2026-10-07', categories)), [
    ['cat:1', 'cat:2'],
    ['cat:3'],
    ['back', 'cancel'],
  ])
  assert.deepEqual(buttons(transactionCard({ type: 'expense' }, 'category', '2026-10-07', categories.slice(0, 2))), [
    ['cat:1', 'cat:2'],
    ['back', 'cancel'],
  ])
  assert.deepEqual(buttons(transactionCard({ type: 'expense' }, 'confirm', '2026-10-07')), [['confirm'], ['back', 'cancel']])
})

test('receipt and menu speak to the user by name', () => {
  const draft: Draft = { type: 'expense', amount: '10.00', description: 'Pão', category: categories[0] }
  const receipt = receiptCard(draft, 42, '2026-10-07', 'Igor')
  assert.match(receipt.text, /Pronto, Igor! Sua despesa foi salva\./)
  assert.match(receipt.text, /<b>Data:<\/b> 07\/10\/2026/)
  assert.match(receipt.text, /Nº 42/)
  assert.deepEqual(buttons(receipt), [['launch', 'consult']])
  assert.equal(menuCard('Igor').text, 'Olá, Igor! O que você gostaria de fazer hoje?')
  assert.equal(menuCard().text, 'Olá! O que você gostaria de fazer hoje?')
  // Desconectar lives only in Telegram's command menu (/desconectar), never on the cards.
  assert.deepEqual(buttons(menuCard()), [['launch', 'consult']])
})

test('disconnect asks first, names the company (escaped) and ends waiting for a code', () => {
  const ask = disconnectCard('A & B <Ltda>')
  assert.match(ask.text, /A &amp; B &lt;Ltda&gt;/)
  assert.deepEqual(buttons(ask), [['disconnect:yes'], ['menu']])
})

test('code prompt: welcome, after disconnecting or after a wrong code, always with the reply box', () => {
  assert.match(codePrompt({ firstName: 'Igor' }), /Olá, Igor! Boas-vindas/)
  assert.match(codePrompt({ disconnectedFrom: 'A & B' }), /Bot desconectado[\s\S]*A &amp; B/)
  assert.match(codePrompt({ error: 'Código de ativação inválido.' }), /⚠️ <b>Código de ativação inválido\.<\/b>/)
  for (const text of [codePrompt(), codePrompt({ error: 'x' })]) assert.match(text, /digite o código abaixo/)
  assert.equal(CODE_INPUT.force_reply, true)
  assert.ok(CODE_INPUT.input_field_placeholder.length <= 64)
})
