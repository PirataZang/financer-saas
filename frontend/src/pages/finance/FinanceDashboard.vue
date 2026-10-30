<template>
  <div class="page fade-in">
    <PageHeader title="Dashboard Financeiro" :subtitle="subtitle" icon="fa-solid fa-chart-pie">
      <Button variant="secondary" icon="fa-solid fa-sliders" label="Personalizar" title="Escolher quais gráficos aparecem" @click="openCustomize" />
      <Button
        v-can="'finance.export'"
        variant="secondary"
        :icon="exporting ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-file-excel'"
        :label="exporting ? 'Gerando planilha...' : 'Exportar Excel'"
        :disabled="exporting || !validRange"
        title="Baixa uma planilha com o resumo (fórmulas), mês a mês, transações e categorias dos filtros atuais"
        @click="exportExcel"
      />
    </PageHeader>

    <section class="filters" aria-label="Filtros do dashboard">
      <Select class="filter filter--period" v-model="period" label="Período" :options="PERIODS" @change="onPeriodChange" />
      <template v-if="period === 'custom'">
        <Input class="filter filter--date" type="date" v-model="range.from" label="De" @change="fetchSummary" />
        <Input class="filter filter--date" type="date" v-model="range.to" label="Até" @change="fetchSummary" />
      </template>
      <CategorySelect
        class="filter filter--wide"
        v-model="filters.category_ids"
        label="Categorias"
        placeholder="Todas"
        multiple
        clearable
        @change="fetchSummary"
      />
      <Select class="filter" v-model="filters.source" label="Origem" :options="sourceOptions" placeholder="Todas" @change="fetchSummary" />
      <Select class="filter" v-model="filters.status" label="Status" :options="STATUS_OPTIONS" @change="fetchSummary" />
      <Button
        v-if="hasExtraFilters"
        class="filters__reset"
        variant="ghost"
        size="sm"
        icon="fa-solid fa-filter-circle-xmark"
        label="Limpar filtros"
        @click="resetFilters"
      />
    </section>

    <div v-if="error" class="form-error" role="alert">{{ error }}</div>

    <div class="kpi-grid" :class="{ 'is-loading': loading }" :aria-busy="loading">
      <div v-for="kpi in kpis" :key="kpi.label" class="kpi-card glass-panel">
        <span class="kpi-label"><i :class="kpi.icon" aria-hidden="true" /> {{ kpi.label }}</span>
        <span class="kpi-value" :class="kpi.tone">{{ summary ? formatMoney(kpi.value) : '—' }}</span>
        <span class="kpi-hint">{{ summary ? kpi.hint : ' ' }}</span>
      </div>
    </div>

    <div v-if="summary" class="chart-grid" :class="{ 'is-loading': loading }">
      <section v-for="chart in renderedCharts" :key="chart.key" class="chart-card glass-panel" :class="{ wide: chart.wide }">
        <h4>{{ chart.title }}</h4>
        <EChart v-if="chart.option" :option="chart.option" :height="chart.height" />
        <p v-else class="chart-empty" :style="{ height: chart.height }">
          <i class="fa-regular fa-folder-open" aria-hidden="true" />
          <template v-if="chart.empty">{{ chart.empty }}</template>
          <template v-else>Nenhuma transação {{ STATUS_LABEL[filters.status] }} neste período{{ hasExtraFilters ? ' com esses filtros' : '' }}.</template>
        </p>
      </section>
    </div>

    <!-- Mercado Pago (Configurações › Integrações): the account's own figures for the same period. -->
    <section v-if="mp" class="mp" :class="{ 'is-loading': loading }" aria-labelledby="mp-title">
      <header class="mp__head">
        <span class="mp__logo"><img :src="INTEGRATIONS.mercado_pago.logo" alt="" width="26" height="26" /></span>
        <div class="mp__title">
          <h3 id="mp-title">Mercado Pago</h3>
          <p>
            {{ mp.account.nickname ? `Conta ${mp.account.nickname} · ` : '' }}
            {{ mp.account.last_synced_at ? `sincronizado ${sinceTime(mp.account.last_synced_at)}` : 'ainda não sincronizado' }}
            · filtros de categoria, origem e status não se aplicam aqui
          </p>
        </div>
        <Button v-can="'settings.integrations'" type="route" :href="{ name: 'Settings', query: { tab: 'integrations' } }" variant="ghost" size="sm" icon="fa-solid fa-gear" label="Gerenciar" />
      </header>

      <p v-if="mp.account.last_error" class="mp__error" role="alert">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true" />
        A última sincronização falhou: {{ mp.account.last_error }}
      </p>

      <dl class="mp__kpis">
        <div v-for="kpi in mpKpis" :key="kpi.label">
          <dt><i :class="kpi.icon" aria-hidden="true" /> {{ kpi.label }}</dt>
          <dd :class="kpi.tone">{{ formatMoney(kpi.value) }}</dd>
          <small>{{ kpi.hint }}</small>
        </div>
      </dl>

      <div v-if="mpCharts.length" class="chart-grid">
        <section v-for="chart in mpCharts" :key="chart.key" class="chart-card glass-panel">
          <h4>{{ chart.title }}</h4>
          <EChart v-if="chart.option" :option="chart.option" :height="chart.height" />
          <p v-else class="chart-empty" :style="{ height: chart.height }">
            <i class="fa-regular fa-folder-open" aria-hidden="true" />
            {{ chart.empty }}
          </p>
        </section>
      </div>
    </section>

    <Modal v-model="customizing" title="Personalizar o dashboard" size="sm">
      <p class="customize__intro">Escolha os gráficos que aparecem para você. Vale em qualquer computador ou celular em que entrar.</p>
      <fieldset v-for="group in chartGroups" :key="group.key" class="customize__group">
        <legend>{{ group.label }}</legend>
        <Switch
          v-for="chart in group.charts"
          :key="chart.key"
          :model-value="!draftHidden.includes(chart.key)"
          :text-label="chart.title"
          :aria-label="`Mostrar ${chart.title}`"
          @change="toggleChart(chart.key, $event)"
        />
      </fieldset>
      <template #footer>
        <Button variant="ghost" label="Mostrar todos" :disabled="!draftHidden.length" @click="draftHidden = []" />
        <Button variant="secondary" label="Cancelar" @click="customizing = false" />
        <Button icon="fa-solid fa-check" label="Salvar" :disabled="savingLayout" @click="saveCustomize" />
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import EChart from '@/components/ui/EChart.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import CategorySelect from '@/components/finance/CategorySelect.vue'
import Modal from '@/components/utils/Modal.vue'
import Switch from '@/components/utils/Switch.vue'
import { INTEGRATIONS } from '@/integrations/catalog'
import { sinceTime } from '@/utils/date'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { PERIODS, periodRange } from '@/utils/period'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/utils/money'
import { charts } from './dashboardCharts'

// Same vocabulary as the API (status=paid|pending|all); paid is what actually moved money.
const STATUS_OPTIONS = [
  { value: 'paid', label: 'Efetivadas', icon: 'fa-solid fa-circle-check' },
  { value: 'pending', label: 'Pendentes', icon: 'fa-solid fa-clock' },
  { value: 'all', label: 'Efetivadas e pendentes', icon: 'fa-solid fa-layer-group' },
]
const STATUS_LABEL = { paid: 'efetivada', pending: 'pendente', all: '' }
const DEFAULT_FILTERS = () => ({ category_ids: [], source: '', status: 'paid' })

const auth = useAuthStore()
// Configurações › Financeiro "período inicial".
const period = ref(auth.settings.finance.default_period || 'month')
const range = ref(periodRange(period.value))
const filters = ref(DEFAULT_FILTERS())
const sourceOptions = ref([])
const summary = ref(null)
const goals = ref([])
const loading = ref(false)
const exporting = ref(false)
const error = ref('')

const validRange = computed(() => !!range.value.from && !!range.value.to && range.value.from <= range.value.to)
const hasExtraFilters = computed(() => filters.value.category_ids.length > 0 || !!filters.value.source || filters.value.status !== 'paid')

const subtitle = computed(() => {
  const status = STATUS_OPTIONS.find((o) => o.value === filters.value.status)?.label.toLowerCase()
  return `Receitas, despesas e saldo das transações ${status} no período.`
})

const plural = (n) => `${n} ${n === 1 ? 'lançamento' : 'lançamentos'}`

const kpis = computed(() => {
  const s = summary.value
  const totals = s?.totals ?? {}
  const kept = totals.income > 0 ? (totals.balance / totals.income) * 100 : null
  return [
    {
      label: 'Receitas',
      icon: 'fa-solid fa-arrow-trend-up',
      value: totals.income,
      hint: s && (mpIncome.value > 0 ? `${plural(s.counts.income)} · ${formatMoney(mpIncome.value)} do Mercado Pago` : plural(s.counts.income)),
    },
    { label: 'Despesas', icon: 'fa-solid fa-arrow-trend-down', value: totals.expense, hint: s && plural(s.counts.expense) },
    {
      label: 'Resultado do período',
      icon: 'fa-solid fa-scale-balanced',
      value: totals.balance,
      tone: totals.balance < 0 ? 'is-negative' : '',
      hint: kept === null ? 'Sem receitas no período' : `${kept.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}% da receita ${kept >= 0 ? 'sobrou' : 'faltou'}`,
    },
    {
      label: 'Saldo acumulado',
      icon: 'fa-solid fa-wallet',
      value: s?.closing_balance,
      tone: s?.closing_balance < 0 ? 'is-negative' : '',
      hint: s && `Saldo anterior: ${formatMoney(s.opening_balance)}`,
    },
  ]
})

// Charts this user may see and didn't hide (Personalizar → preferences.dashboard_hidden_charts).
const hidden = computed(() => auth.preferences.dashboard_hidden_charts ?? [])
const allowedCharts = computed(() => charts.filter((chart) => !chart.permission || auth.can(chart.permission)))
const build = (group) => {
  const data = { ...summary.value, goals: goals.value }
  const list = allowedCharts.value
    .filter((chart) => chart.group === group && !hidden.value.includes(chart.key))
    .map((chart) => ({ ...chart, option: chart.option(data), height: chart.height?.(data) ?? '280px' }))
  // Charts pair up two per row: an odd one out (before a wide chart or at the end) takes the whole
  // row instead of leaving a hole — hiding charts in Personalizar can create any count.
  let run = 0
  list.forEach((chart, i) => {
    run = chart.wide ? 0 : run + 1
    const endsRun = i === list.length - 1 || list[i + 1].wide
    if (!chart.wide && endsRun && run % 2 === 1) chart.wide = true
  })
  return list
}
const renderedCharts = computed(() => build('general'))

// Mercado Pago panel: present when the integration is connected and on.
const mp = computed(() => summary.value?.mercado_pago ?? null)
const mpCharts = computed(() => (mp.value ? build('mercado_pago') : []))
const mpIncome = computed(() => summary.value?.sources?.find((r) => r.source === 'mercado_pago')?.income ?? 0)
const mpKpis = computed(() => {
  const t = mp.value.totals
  const share = (v) => (t.gross > 0 ? `${((v / t.gross) * 100).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}% do bruto` : '')
  return [
    { label: 'Recebido (bruto)', icon: 'fa-solid fa-hand-holding-dollar', value: t.gross, hint: `${t.count} pagamento(s) aprovado(s)` },
    { label: 'Tarifas', icon: 'fa-solid fa-receipt', value: t.fees, hint: share(t.fees) },
    { label: 'Estornos e contestações', icon: 'fa-solid fa-rotate-left', value: t.refunded, hint: share(t.refunded) },
    { label: 'Líquido', icon: 'fa-solid fa-wallet', value: t.net, tone: t.net < 0 ? 'is-negative' : '', hint: 'Bruto − tarifas − estornos' },
    { label: 'Aguardando', icon: 'fa-regular fa-clock', value: t.pending, hint: 'Boletos e Pix ainda não pagos' },
  ]
})

// Personalizar: switches per chart, saved to the user's preferences on Salvar.
const customizing = ref(false)
const savingLayout = ref(false)
const draftHidden = ref([])
const chartGroups = computed(() => [
  { key: 'general', label: 'Geral', charts: allowedCharts.value.filter((c) => c.group === 'general') },
  ...(mp.value ? [{ key: 'mercado_pago', label: 'Mercado Pago', charts: allowedCharts.value.filter((c) => c.group === 'mercado_pago') }] : []),
])
const openCustomize = () => {
  draftHidden.value = [...hidden.value]
  customizing.value = true
}
const toggleChart = (key, visible) => {
  draftHidden.value = visible ? draftHidden.value.filter((k) => k !== key) : [...draftHidden.value, key]
}
const saveCustomize = async () => {
  savingLayout.value = true
  try {
    await auth.savePreferences({ dashboard_hidden_charts: draftHidden.value })
    customizing.value = false
    swal.toastSuccess('Dashboard personalizado.')
  } catch (err) {
    swal.toastError(err.message || 'Não foi possível salvar.')
  } finally {
    savingLayout.value = false
  }
}

// One query string for the dashboard and the export, so the spreadsheet is exactly what's on screen.
const query = () => {
  const params = new URLSearchParams({ ...range.value, status: filters.value.status })
  if (filters.value.source) params.set('source', filters.value.source)
  filters.value.category_ids.forEach((id) => params.append('category_ids[]', id))
  return params
}

const onPeriodChange = () => {
  if (period.value === 'custom') return // keeps the last range until the user edits the dates
  range.value = periodRange(period.value)
  fetchSummary()
}

const resetFilters = () => {
  filters.value = DEFAULT_FILTERS()
  fetchSummary()
}

const fetchSummary = async () => {
  if (!validRange.value) {
    error.value = range.value.from && range.value.to ? 'A data inicial deve ser anterior à final.' : ''
    return
  }
  error.value = ''
  loading.value = true
  try {
    const response = await apiFetch(`/finance/dashboard?${query()}`)
    const data = await response.json()
    if (!response.ok) throw new Error(data.message)
    summary.value = data
  } catch (err) {
    error.value = err.message || 'Não foi possível carregar o dashboard.'
  } finally {
    loading.value = false
  }
}

const exportExcel = async () => {
  exporting.value = true
  try {
    const response = await apiFetch(`/finance/dashboard/export?${query()}`)
    if (!response.ok) throw new Error((await response.json().catch(() => ({}))).message)
    const url = URL.createObjectURL(await response.blob())
    const link = Object.assign(document.createElement('a'), {
      href: url,
      download: `financeiro_${range.value.from}_a_${range.value.to}.xlsx`,
    })
    link.click()
    URL.revokeObjectURL(url)
    swal.toastSuccess('Planilha gerada.')
  } catch (err) {
    swal.toastError(err.message || 'Não foi possível gerar a planilha.')
  } finally {
    exporting.value = false
  }
}

// Goals have their own periods: loaded once, the dashboard filters don't apply to them.
const fetchGoals = async () => {
  if (!auth.can('finance.goal.view')) return
  const response = await apiFetch('/finance/goals')
  if (response.ok) goals.value = await response.json()
}

onMounted(async () => {
  const response = await apiFetch('/finance/transactions/options')
  if (response.ok) sourceOptions.value = (await response.json()).sources
  await Promise.all([fetchSummary(), fetchGoals()])
})
</script>

<style scoped>
.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  gap: 12px;
  padding: 16px 20px;
  background: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-sm);
}

.filter {
  width: 190px;
}

.filter--period {
  width: 200px;
}

.filter--date {
  width: 170px;
}

.filter--wide {
  width: 280px;
}

.filters__reset {
  margin-bottom: 4px;
}

.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap: 20px;
}

.kpi-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 18px 20px;
  border-radius: 16px;
}

.kpi-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.kpi-value {
  font-size: 1.6rem;
  font-weight: 700;
  color: var(--color-text);
  font-variant-numeric: tabular-nums;
}

.kpi-value.is-negative {
  color: var(--color-danger);
}

.kpi-hint {
  min-height: 1.1em;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

/* Refetching keeps the last numbers visible, just quieter — no layout jump. */
.is-loading {
  opacity: 0.55;
  transition: opacity 0.18s ease;
}

.chart-grid {
  display: grid;
  /* Charts pair up side by side; a wide one (Metas) spans both columns. */
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 20px;
  transition: opacity 0.18s ease;
}

.chart-card {
  padding: 20px;
  border-radius: 16px;
  min-width: 0;
}

.chart-card.wide {
  grid-column: 1 / -1;
}

.chart-card h4 {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--color-text);
  margin-bottom: 12px;
}

.mp {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding-top: 8px;
  transition: opacity 0.18s ease;
}

.mp__head {
  display: flex;
  align-items: center;
  gap: 12px;
}

.mp__logo {
  display: grid;
  flex: 0 0 40px;
  place-items: center;
  width: 40px;
  height: 40px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg-card);
}

.mp__title {
  flex: 1;
  min-width: 0;
}

.mp__title h3 {
  margin: 0;
  color: var(--color-text);
  font-size: 1.1rem;
  font-weight: 700;
}

.mp__title p {
  margin: 2px 0 0;
  color: var(--color-text-muted);
  font-size: 0.8rem;
}

.mp__error {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin: 0;
  padding: 10px 14px;
  border-radius: var(--radius-md);
  background: var(--color-warning-soft);
  color: var(--color-text-secondary);
  font-size: 0.85rem;
}

.mp__error i {
  color: var(--color-warning-text);
}

/* One strip, not five cards: the panel's figures read as a single statement of the account. */
.mp__kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  margin: 0;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  background: var(--color-bg-card);
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

.mp__kpis > div {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 16px 18px;
  box-shadow: -1px 0 0 var(--color-border), 0 -1px 0 var(--color-border);
}

.mp__kpis dt {
  display: flex;
  align-items: center;
  gap: 6px;
  color: var(--color-text-muted);
  font-size: 0.78rem;
}

.mp__kpis dd {
  margin: 0;
  color: var(--color-text);
  font-size: 1.3rem;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.mp__kpis dd.is-negative {
  color: var(--color-danger);
}

.mp__kpis small {
  min-height: 1.1em;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

.customize__intro {
  margin: 0 0 16px;
  color: var(--color-text-secondary);
  font-size: 0.875rem;
  line-height: 1.5;
}

.customize__group {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin: 0 0 18px;
  padding: 0;
  border: 0;
}

.customize__group legend {
  margin-bottom: 10px;
  padding: 0;
  color: var(--color-text);
  font-size: 0.8rem;
  font-weight: 700;
}

.chart-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 160px;
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.875rem;
  text-align: center;
}

.chart-empty i {
  font-size: 1.25rem;
  color: var(--color-text-faint);
}

@media (max-width: 640px) {
  .filter,
  .filter--period,
  .filter--date,
  .filter--wide {
    width: 100%;
  }

  .chart-grid {
    grid-template-columns: 1fr;
  }
}
</style>
