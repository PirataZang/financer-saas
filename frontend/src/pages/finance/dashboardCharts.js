import { formatMoney } from '@/utils/money'

// Finance dashboard charts. Each entry turns the /finance/dashboard payload into an ECharts
// option (or null = "sem dados"). New chart = new entry here; FinanceDashboard.vue renders the list.
//   { key, title, wide?, height?(summary), option(summary) }
// One question per chart: flow over time, balance over time, where money comes from, where it goes.

const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim()

// Income/expense reuse the status tokens. Their CVD separation is borderline (ΔE ≈ 8), so
// expenses also carry a 45° texture and every chart has a legend/labels — never color alone.
const colors = () => ({
  income: cssVar('--color-success'),
  expense: cssVar('--color-warning'),
  balance: cssVar('--color-primary'),
  axis: cssVar('--color-text-muted'),
  grid: cssVar('--color-border'),
  text: cssVar('--color-text'),
  surface: cssVar('--color-bg-card'),
})

const expenseTexture = { symbol: 'rect', symbolSize: 1, dashArrayX: [1, 0], dashArrayY: [2, 4], rotation: -Math.PI / 4, color: 'rgba(255,255,255,0.45)' }

const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez']
const periodLabel = (period) => {
  const [y, m, d] = period.split('-')
  return d ? `${d}/${m}` : `${MONTHS[Number(m) - 1]}/${y.slice(2)}`
}

const compactMoney = (v) =>
  Math.abs(v) >= 1000 ? `R$ ${(v / 1000).toLocaleString('pt-BR', { maximumFractionDigits: 1 })} mil` : `R$ ${v}`

const axes = (c, categories) => ({
  grid: { left: 8, right: 16, top: 36, bottom: 8, containLabel: true },
  xAxis: {
    type: 'category',
    data: categories,
    axisLine: { lineStyle: { color: c.grid } },
    axisTick: { show: false },
    axisLabel: { color: c.axis },
  },
  yAxis: {
    type: 'value',
    splitLine: { lineStyle: { color: c.grid } },
    axisLabel: { color: c.axis, formatter: compactMoney },
  },
})

const tooltip = (trigger = 'axis') => ({ trigger, valueFormatter: (v) => formatMoney(v), axisPointer: { type: trigger === 'axis' ? 'shadow' : undefined } })

const legend = (c) => ({ top: 0, left: 0, icon: 'roundRect', itemWidth: 12, itemHeight: 12, textStyle: { color: c.text } })

// One series (a category ranking): bars in each category's own color, name on the axis,
// value + share of the type's total on the label — the ranking and the distribution in one view.
const categoryBars = (list) => {
  if (!list?.length) return null
  const c = colors()
  const total = list.reduce((sum, item) => sum + item.total, 0)
  const share = (value) => `${((value / total) * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`
  const top = list.slice(0, 12).reverse()
  return {
    grid: { left: 8, right: 120, top: 8, bottom: 8, containLabel: true },
    tooltip: {
      trigger: 'item',
      formatter: (p) => `${p.name}<br/><b>${formatMoney(p.value)}</b> · ${share(p.value)} · ${p.data.count} lançamento(s)`,
    },
    xAxis: { type: 'value', show: false },
    yAxis: {
      type: 'category',
      data: top.map((item) => `${item.icon ?? ''} ${item.name}`.trim()),
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: { color: c.text },
    },
    series: [{
      type: 'bar',
      data: top.map((item) => ({ value: item.total, count: item.count, itemStyle: { color: item.color } })),
      barMaxWidth: 18,
      itemStyle: { borderRadius: [0, 4, 4, 0] },
      label: { show: true, position: 'right', color: c.text, formatter: (p) => `${formatMoney(p.value)} · ${share(p.value)}` },
    }],
  }
}

// Ranked bars need room per category; the time charts keep the default height.
const categoryHeight = (list) => `${Math.max(140, Math.min(list.length, 12) * 40 + 32)}px`

export const charts = [
  {
    key: 'flow',
    title: 'Receitas x despesas',
    wide: true,
    option: (s) => {
      if (!s.timeline.some((p) => p.income || p.expense)) return null
      const c = colors()
      return {
        ...axes(c, s.timeline.map((p) => periodLabel(p.period))),
        tooltip: tooltip(),
        legend: legend(c),
        series: [
          { name: 'Receitas', type: 'bar', data: s.timeline.map((p) => p.income), barMaxWidth: 16, barGap: '15%', itemStyle: { color: c.income, borderRadius: [4, 4, 0, 0] } },
          { name: 'Despesas', type: 'bar', data: s.timeline.map((p) => p.expense), barMaxWidth: 16, itemStyle: { color: c.expense, borderRadius: [4, 4, 0, 0], decal: expenseTexture } },
        ],
      }
    },
  },
  {
    key: 'balance',
    title: 'Evolução do saldo',
    wide: true,
    option: (s) => {
      const c = colors()
      return {
        ...axes(c, s.timeline.map((p) => periodLabel(p.period))),
        tooltip: { trigger: 'axis', valueFormatter: (v) => formatMoney(v) },
        series: [{
          name: 'Saldo',
          type: 'line',
          data: s.timeline.map((p) => p.balance),
          symbolSize: 8,
          showSymbol: s.timeline.length <= 31,
          lineStyle: { width: 2, color: c.balance },
          itemStyle: { color: c.balance, borderColor: c.surface, borderWidth: 2 },
          areaStyle: { color: c.balance, opacity: 0.08 },
          markLine: { silent: true, symbol: 'none', label: { show: false }, lineStyle: { color: c.axis, type: 'dashed' }, data: [{ yAxis: 0 }] },
        }],
      }
    },
  },
  { key: 'expense-categories', title: 'Despesas por categoria', height: (s) => categoryHeight(s.categories.expense), option: (s) => categoryBars(s.categories.expense) },
  { key: 'income-categories', title: 'Receitas por categoria', height: (s) => categoryHeight(s.categories.income), option: (s) => categoryBars(s.categories.income) },
]
