<template>
  <div class="page fade-in">
    <PageHeader title="Dashboard Financeiro" :subtitle="subtitle" icon="fa-solid fa-chart-pie">
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
          Nenhuma transação {{ STATUS_LABEL[filters.status] }} neste período{{ hasExtraFilters ? ' com esses filtros' : '' }}.
        </p>
      </section>
    </div>
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

// Configurações › Financeiro "período inicial".
const period = ref(useAuthStore().settings.finance.default_period || 'month')
const range = ref(periodRange(period.value))
const filters = ref(DEFAULT_FILTERS())
const sourceOptions = ref([])
const summary = ref(null)
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
    { label: 'Receitas', icon: 'fa-solid fa-arrow-trend-up', value: totals.income, hint: s && plural(s.counts.income) },
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

const renderedCharts = computed(() =>
  charts.map((chart) => ({ ...chart, option: chart.option(summary.value), height: chart.height?.(summary.value) ?? '280px' })),
)

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

onMounted(async () => {
  const response = await apiFetch('/finance/transactions/options')
  if (response.ok) sourceOptions.value = (await response.json()).sources
  await fetchSummary()
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
  /* Wide time charts span both columns; the category pair splits evenly. */
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
