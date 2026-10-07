import { test } from 'node:test'
import assert from 'node:assert/strict'
import { parseMoney, todayIn } from './money.js'

test('parses the ways people type money', () => {
  const cases: Record<string, string> = {
    '150,50': '150.50',
    'R$ 150,50': '150.50',
    'r$150': '150.00',
    '1.234,56': '1234.56',
    '1,234.56': '1234.56',
    '1234.5': '1234.50',
    '1.234': '1234.00',
    '0,99': '0.99',
    '007': '7.00',
    ' 10 ': '10.00',
  }
  for (const [input, expected] of Object.entries(cases)) assert.equal(parseMoney(input), expected, input)
})

test('rejects anything ambiguous, empty, zero or negative', () => {
  for (const input of ['', 'abc', '-10', '0', '0,00', '10,999', '1,234', '1,234,56', '1.2.3', '12.34.567', '1e3', '10 reais', '99999999999999'])
    assert.equal(parseMoney(input), null, input)
})

test('today follows the configured timezone, not UTC', () => {
  const lateNightInBrazil = new Date('2026-10-08T01:30:00Z') // 22:30 on the 7th in São Paulo
  assert.equal(todayIn('America/Sao_Paulo', lateNightInBrazil), '2026-10-07')
  assert.equal(todayIn('UTC', lateNightInBrazil), '2026-10-08')
})
