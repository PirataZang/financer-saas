import { formatMoney } from './money.js'

// Balancete for the "Consultar" option. Pure functions: dates in, text out (tested in balance.test.ts).

export type PeriodKey = 'month' | 'last_month' | 'year' | 'all'

export const PERIODS: { key: PeriodKey; label: string }[] = [
  { key: 'month', label: 'Este mês' },
  { key: 'last_month', label: 'Mês passado' },
  { key: 'year', label: 'Este ano' },
  { key: 'all', label: 'Todo o período' },
]

export interface CategoryTotal {
  name: string | null
  icon: string | null
  total: number
  count: number
}

export interface Summary {
  period: { from: string; to: string }
  totals: { income: number; expense: number; balance: number }
  counts: { income: number; expense: number }
  opening_balance: number
  closing_balance: number
  categories: { income: CategoryTotal[]; expense: CategoryTotal[] }
}

const pad = (n: number) => String(n).padStart(2, '0')

/** today = YYYY-MM-DD in the user's timezone. from null = everything up to `to`. */
export function periodRange(key: PeriodKey, today: string): { from: string | null; to: string } {
  const [y = 0, m = 0] = today.split('-').map(Number)
  switch (key) {
    case 'month':
      return { from: `${today.slice(0, 8)}01`, to: today }
    case 'last_month': {
      const [py, pm] = m === 1 ? [y - 1, 12] : [y, m - 1]
      const lastDay = new Date(Date.UTC(py, pm, 0)).getUTCDate()
      return { from: `${py}-${pad(pm)}-01`, to: `${py}-${pad(pm)}-${pad(lastDay)}` }
    }
    case 'year':
      return { from: `${y}-01-01`, to: today }
    case 'all':
      return { from: null, to: today }
  }
}

const brDate = (date: string) => date.split('-').reverse().join('/')
const escape = (text: string) => text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
const percent = (part: number, whole: number) =>
  `${((part / whole) * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`
const plural = (n: number) => `${n} ${n === 1 ? 'lançamento' : 'lançamentos'}`

function section(title: string, total: number, count: number, categories: CategoryTotal[]): string[] {
  const lines = [title, `Total: <b>${formatMoney(String(total))}</b> · ${plural(count)}`]
  const sorted = [...categories].sort((a, b) => b.total - a.total)
  sorted.forEach((category, i) => {
    const name = escape(`${category.icon ?? ''} ${category.name ?? 'Sem categoria'}`.trim())
    const branch = i === sorted.length - 1 ? '└' : '├'
    lines.push(`${branch} ${name}: ${formatMoney(String(category.total))} (${percent(category.total, total)})`)
  })
  return lines
}

export function formatBalance(summary: Summary, periodLabel: string): string {
  const { totals, counts, categories, period } = summary
  const header = [
    '📊 <b>Balancete financeiro</b>',
    `<i>${periodLabel} · ${brDate(period.from)} a ${brDate(period.to)}</i>`,
    '',
  ]

  if (!counts.income && !counts.expense) {
    return [...header, 'Nenhuma transação efetivada no período.'].join('\n')
  }

  const net = totals.balance
  return [
    ...header,
    '━━━━ 💰 <b>CRÉDITOS</b> ━━━━',
    ...section('Receitas por categoria', totals.income, counts.income, categories.income),
    '',
    '━━━━ 💸 <b>DÉBITOS</b> ━━━━',
    ...section('Despesas por categoria', totals.expense, counts.expense, categories.expense),
    '',
    '━━━━ 🧮 <b>RESUMO</b> ━━━━',
    `Total de créditos: <b>${formatMoney(String(totals.income))}</b>`,
    `Total de débitos: <b>${formatMoney(String(totals.expense))}</b>`,
    `Resultado líquido: <b>${formatMoney(String(net))}</b> ${net >= 0 ? '✅' : '🔻'}`,
    ...(summary.opening_balance ? [`Saldo anterior: ${formatMoney(String(summary.opening_balance))}`] : []),
    `Saldo acumulado: <b>${formatMoney(String(summary.closing_balance))}</b>`,
  ].join('\n')
}
