import { formatMoney } from '@/utils/money'

// Finance dashboard charts. Each entry turns the /finance/dashboard payload into an ECharts
// option (or null = "sem dados"). New chart = new entry here; FinanceDashboard.vue renders the list.
//   { key, title, group, wide?, permission?, empty?, height?(summary), option(summary) }
// group: 'general' (main grid) or 'mercado_pago' (integration panel, only when connected).
// Every key can be hidden per user (Personalizar → preferences.dashboard_hidden_charts).
// One question per chart: flow over time, balance over time, where money comes from, where it goes,
// how the goals are doing. `summary.goals` is GET /finance/goals (own periods, ignores the filters).

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
  warning: cssVar('--color-warning'),
  danger: cssVar('--color-danger'),
  neutral: cssVar('--color-text-faint'),
  chart: (n) => cssVar(`--color-chart-${n}`),
})

// Color follows the entity, never its rank (a filter must not repaint the survivors).
const METHOD_SLOT = { credit_card: 1, account_money: 2, pix: 3, ticket: 4, prepaid_card: 5, atm: 6, debit_card: 7 }
const SOURCE_SLOT = { manual: 1, mercado_pago: 3, telegram: 7, import: 5, open_finance: 6 }
const methodColor = (c, key) => (METHOD_SLOT[key] ? c.chart(METHOD_SLOT[key]) : c.neutral)
const toneColor = (c, tone) => ({ success: c.income, warning: c.warning, danger: c.danger }[tone] ?? c.neutral)

// Donut: share of a whole. Slices keep a 2px surface gap; the legend carries name, value and share
// (identity is never color alone — chart tokens 3–5 are < 3:1 on white), the hole carries the total.
//   rows: [{ name, value, color, count? }]  ·  money: values are R$ (else counts)
const donut = (rows, { money = true, totalLabel = 'Total' } = {}) => {
  const data = rows.filter((r) => r.value > 0)
  if (!data.length) return null
  const c = colors()
  const total = data.reduce((sum, r) => sum + r.value, 0)
  const fmt = (v) => (money ? formatMoney(v) : v.toLocaleString('pt-BR'))
  const share = (v) => percent((v / total) * 100)
  const byName = Object.fromEntries(data.map((r) => [r.name, r]))
  // Wide card: donut left, legend right. Narrow (phone): donut on top, legend below.
  return {
    baseOption: {
      tooltip: {
        trigger: 'item',
        formatter: (p) => `${p.marker}${p.name}<br/><b>${fmt(p.value)}</b> · ${share(p.value)}${p.data.count != null && money ? ` · ${p.data.count} pagamento(s)` : ''}`,
      },
      legend: {
        orient: 'vertical',
        right: 0,
        top: 'middle',
        icon: 'circle',
        itemWidth: 10,
        itemHeight: 10,
        itemGap: 12,
        textStyle: { color: c.text, rich: { v: { color: c.axis, padding: [0, 0, 0, 4] } } },
        formatter: (name) => `${name}  {v|${share(byName[name].value)}}`,
      },
      title: {
        text: fmt(total),
        subtext: totalLabel,
        left: '28%',
        top: '50%',
        textAlign: 'center',
        textVerticalAlign: 'middle',
        itemGap: 2,
        textStyle: { color: c.text, fontSize: 14, fontWeight: 700 },
        subtextStyle: { color: c.axis, fontSize: 12 },
      },
      series: [{
        type: 'pie',
        radius: ['56%', '80%'],
        center: ['28%', '50%'],
        label: { show: false },
        labelLine: { show: false },
        itemStyle: { borderColor: c.surface, borderWidth: 2, borderRadius: 4 },
        emphasis: { scaleSize: 4 },
        data: data.map((r) => ({ name: r.name, value: r.value, count: r.count, itemStyle: { color: r.color } })),
      }],
    },
    media: [{
      // Full-row card: keep donut and legend together instead of at opposite edges.
      query: { minWidth: 700 },
      option: {
        legend: { left: '52%', right: 'auto' },
        title: { left: '34%' },
        series: [{ center: ['34%', '50%'] }],
      },
    }, {
      query: { maxWidth: 440 },
      option: {
        legend: { orient: 'horizontal', left: 'center', right: 'auto', top: 'auto', bottom: 0 },
        title: { left: '50%', top: '38%' },
        series: [{ center: ['50%', '38%'], radius: ['44%', '66%'] }],
      },
    }],
  }
}

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

const percent = (v) => `${v.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`

// % of each goal's target, dashed line at 100%. A ceiling uses the same tones as the Metas screen.
const goalBars = (goals) => {
  if (!goals?.length) return null
  const c = colors()
  const tone = (g) => {
    if (g.type !== 'spending') return g.category.color
    return g.progress.percent > 100 ? c.danger : g.progress.percent >= 80 ? c.warning : c.income
  }
  const list = goals.slice(0, 12).reverse()
  const label = (d) => `${percent(d.value)} · ${formatMoney(d.current)} de ${formatMoney(d.target)}${d.ceiling ? ' (teto)' : ''}`
  return {
    grid: { left: 8, right: 210, top: 8, bottom: 8, containLabel: true },
    tooltip: { trigger: 'item', formatter: (p) => `${p.name}<br/><b>${label(p.data)}</b>` },
    xAxis: { type: 'value', show: false, max: (v) => Math.max(100, v.max) },
    yAxis: {
      type: 'category',
      data: list.map((g) => `${g.category.icon ?? ''} ${g.category.name}`.trim()),
      axisLine: { show: false },
      axisTick: { show: false },
      axisLabel: { color: c.text },
    },
    series: [{
      type: 'bar',
      data: list.map((g) => ({
        value: g.progress.percent,
        current: g.progress.current,
        target: Number(g.target_amount),
        ceiling: g.type === 'spending',
        itemStyle: { color: tone(g) },
      })),
      barMaxWidth: 18,
      showBackground: true,
      backgroundStyle: { color: c.grid, borderRadius: [0, 4, 4, 0] },
      itemStyle: { borderRadius: [0, 4, 4, 0] },
      label: { show: true, position: 'right', color: c.text, formatter: (p) => label(p.data) },
      markLine: { silent: true, symbol: 'none', label: { show: false }, lineStyle: { color: c.axis, type: 'dashed' }, data: [{ xAxis: 100 }] },
    }],
  }
}

export const charts = [
  {
    key: 'flow',
    group: 'general',
    title: 'Receitas x despesas',
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
    group: 'general',
    title: 'Evolução do saldo',
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
  {
    key: 'sources',
    height: () => '300px',
    group: 'general',
    title: 'Receitas por origem',
    empty: 'Só há receitas de uma origem neste período.',
    option: (s) => {
      const c = colors()
      const rows = (s.sources ?? []).map((r) => ({ name: r.label, value: r.income, color: SOURCE_SLOT[r.source] ? c.chart(SOURCE_SLOT[r.source]) : c.neutral }))
      // One slice says nothing a KPI doesn't: only when there is a real split.
      return rows.filter((r) => r.value > 0).length > 1 ? donut(rows) : null
    },
  },
  { key: 'expense-categories', group: 'general', title: 'Despesas por categoria', height: (s) => categoryHeight(s.categories.expense), option: (s) => categoryBars(s.categories.expense) },
  { key: 'income-categories', group: 'general', title: 'Receitas por categoria', height: (s) => categoryHeight(s.categories.income), option: (s) => categoryBars(s.categories.income) },
  {
    key: 'goals',
    group: 'general',
    title: 'Metas',
    wide: true,
    permission: 'finance.goal.view',
    empty: 'Nenhuma meta cadastrada. Crie uma em Financeiro › Metas.',
    height: (s) => categoryHeight(s.goals ?? []),
    option: (s) => goalBars(s.goals),
  },
  // Mercado Pago panel (summary.mercado_pago, GET /finance/dashboard): the account's own figures.
  {
    key: 'mp-methods',
    height: () => '300px',
    group: 'mercado_pago',
    title: 'Recebido por meio de pagamento',
    empty: 'Nenhum pagamento recebido neste período.',
    option: (s) => {
      const c = colors()
      return donut(s.mercado_pago.methods.map((m) => ({ name: m.label, value: m.total, count: m.count, color: methodColor(c, m.key) })), { totalLabel: 'Recebido' })
    },
  },
  {
    key: 'mp-composition',
    height: () => '300px',
    group: 'mercado_pago',
    title: 'Para onde foi o valor recebido',
    empty: 'Nenhum pagamento recebido neste período.',
    option: (s) => {
      const c = colors()
      const tone = { net: c.income, fees: c.neutral, refunded: c.danger }
      return donut(s.mercado_pago.composition.map((r) => ({ name: r.label, value: r.total, color: tone[r.key] })), { totalLabel: 'Bruto' })
    },
  },
  {
    key: 'mp-status',
    height: () => '300px',
    group: 'mercado_pago',
    title: 'Status dos pagamentos',
    empty: 'Nenhum pagamento neste período.',
    option: (s) => {
      const c = colors()
      return donut(s.mercado_pago.statuses.map((r) => ({ name: r.label, value: r.count, color: toneColor(c, r.tone) })), { money: false, totalLabel: 'Pagamentos' })
    },
  },
  {
    key: 'mp-purchases',
    height: () => '300px',
    group: 'mercado_pago',
    title: 'Pagamentos feitos pela conta',
    empty: 'A conta não pagou nada pelo Mercado Pago neste período.',
    option: (s) => {
      const c = colors()
      return donut(s.mercado_pago.purchases.map((m) => ({ name: m.label, value: m.total, count: m.count, color: methodColor(c, m.key) })), { totalLabel: 'Pago' })
    },
  },
]
