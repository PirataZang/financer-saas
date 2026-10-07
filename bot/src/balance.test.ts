import { test } from 'node:test'
import assert from 'node:assert/strict'
import { formatBalance, periodRange, type Summary } from './balance.js'

test('period presets in the user calendar', () => {
  assert.deepEqual(periodRange('month', '2026-10-07'), { from: '2026-10-01', to: '2026-10-07' })
  assert.deepEqual(periodRange('last_month', '2026-10-07'), { from: '2026-09-01', to: '2026-09-30' })
  assert.deepEqual(periodRange('last_month', '2026-01-15'), { from: '2025-12-01', to: '2025-12-31' })
  assert.deepEqual(periodRange('last_month', '2024-03-31'), { from: '2024-02-01', to: '2024-02-29' })
  assert.deepEqual(periodRange('year', '2026-10-07'), { from: '2026-01-01', to: '2026-10-07' })
  assert.deepEqual(periodRange('all', '2026-10-07'), { from: null, to: '2026-10-07' })
})

const summary: Summary = {
  period: { from: '2026-10-01', to: '2026-10-07' },
  totals: { income: 5950, expense: 2716.77, balance: 3233.23 },
  counts: { income: 2, expense: 1 },
  opening_balance: 100,
  closing_balance: 3333.23,
  categories: {
    income: [
      { name: 'Salário', icon: '💼', total: 5200, count: 1 },
      { name: 'Outros <x>', icon: null, total: 750, count: 1 },
    ],
    expense: [{ name: 'Moradia', icon: '🏠', total: 2716.77, count: 1 }],
  },
}

test('balancete lists receitas, despesas and the totals', () => {
  const text = formatBalance(summary, 'Este mês').replace(/ /g, ' ')
  assert.match(text, /Este mês · 01\/10\/2026 a 07\/10\/2026/)
  assert.match(text, /Receitas<\/b> — <b>R\$ 5\.950,00<\/b> \(2 lançamentos\)/)
  assert.match(text, /💼 Salário: R\$ 5\.200,00 · 87,4%/)
  assert.match(text, /Outros &lt;x&gt;: R\$ 750,00 · 12,6%/)
  assert.match(text, /Despesas<\/b> — <b>R\$ 2\.716,77<\/b> \(1 lançamento\)/)
  assert.match(text, /Total arrecadado: <b>R\$ 5\.950,00<\/b>/)
  assert.match(text, /Total gasto: <b>R\$ 2\.716,77<\/b>/)
  assert.match(text, /Resultado líquido: <b>R\$ 3\.233,23<\/b> ✅/)
  assert.match(text, /Saldo anterior: R\$ 100,00/)
  assert.match(text, /Saldo acumulado: <b>R\$ 3\.333,23<\/b>/)
})

test('negative result and empty period', () => {
  const negative = formatBalance({ ...summary, totals: { income: 0, expense: 10, balance: -10 }, counts: { income: 0, expense: 1 }, opening_balance: 0 }, 'X')
  assert.match(negative, /🔻/)
  assert.doesNotMatch(negative, /Saldo anterior/)
  assert.match(formatBalance({ ...summary, counts: { income: 0, expense: 0 } }, 'X'), /Nenhuma transação efetivada/)
})
