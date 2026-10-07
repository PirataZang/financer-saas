<template>
  <div class="page fade-in">
    <PageHeader title="Dashboard Financeiro" subtitle="Receitas, despesas e saldo das transações efetivadas no período." icon="fa-solid fa-chart-pie" />

    <div class="filters">
      <Select class="period-select" v-model="period" :options="PERIODS" aria-label="Período" @change="onPeriodChange" />
      <template v-if="period === 'custom'">
        <Input class="date-input" type="date" v-model="range.from" aria-label="De" title="De" @change="fetchSummary" />
        <Input class="date-input" type="date" v-model="range.to" aria-label="Até" title="Até" @change="fetchSummary" />
      </template>
      <i v-if="loading" class="fa-solid fa-spinner fa-spin loading" aria-label="Carregando" />
    </div>

    <div class="kpi-grid">
      <div v-for="kpi in kpis" :key="kpi.label" class="kpi-card glass-panel">
        <span class="kpi-label"><i :class="kpi.icon" aria-hidden="true" /> {{ kpi.label }}</span>
        <span class="kpi-value" :class="kpi.tone">{{ summary ? formatMoney(kpi.value) : '—' }}</span>
        <span v-if="kpi.hint" class="kpi-hint">{{ kpi.hint }}</span>
      </div>
    </div>

    <div v-if="error" class="form-error">{{ error }}</div>

    <div v-if="summary" class="chart-grid">
      <section v-for="chart in renderedCharts" :key="chart.key" class="chart-card glass-panel" :class="{ wide: chart.wide }">
        <h4>{{ chart.title }}</h4>
        <EChart v-if="chart.option" :option="chart.option" height="280px" />
        <p v-else class="chart-empty">Sem dados no período.</p>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import EChart from '@/components/ui/EChart.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import { apiFetch } from '@/services/api'
import { PERIODS, periodRange } from '@/utils/period'
import { formatMoney } from '@/utils/money'
import { charts } from './dashboardCharts'

const period = ref('month')
const range = ref(periodRange('month'))
const summary = ref(null)
const loading = ref(false)
const error = ref('')

const kpis = computed(() => {
  const totals = summary.value?.totals ?? {}
  return [
    { label: 'Receitas', icon: 'fa-solid fa-arrow-trend-up', value: totals.income },
    { label: 'Despesas', icon: 'fa-solid fa-arrow-trend-down', value: totals.expense },
    { label: 'Resultado do período', icon: 'fa-solid fa-scale-balanced', value: totals.balance, tone: totals.balance < 0 ? 'is-negative' : '' },
    {
      label: 'Saldo acumulado',
      icon: 'fa-solid fa-wallet',
      value: summary.value?.closing_balance,
      tone: summary.value?.closing_balance < 0 ? 'is-negative' : '',
      hint: summary.value ? `Saldo anterior: ${formatMoney(summary.value.opening_balance)}` : '',
    },
  ]
})

const renderedCharts = computed(() => charts.map((chart) => ({ ...chart, option: chart.option(summary.value) })))

const onPeriodChange = () => {
  if (period.value === 'custom') return // keeps the last range until the user edits the dates
  range.value = periodRange(period.value)
  fetchSummary()
}

const fetchSummary = async () => {
  if (!range.value.from || !range.value.to) return
  if (range.value.from > range.value.to) {
    error.value = 'A data inicial deve ser anterior à final.'
    return
  }
  error.value = ''
  loading.value = true
  try {
    const response = await apiFetch(`/finance/dashboard?${new URLSearchParams(range.value)}`)
    const data = await response.json()
    if (!response.ok) throw new Error(data.message)
    summary.value = data
  } catch (err) {
    error.value = err.message || 'Não foi possível carregar o dashboard.'
  } finally {
    loading.value = false
  }
}

onMounted(fetchSummary)
</script>

<style scoped>
.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.period-select {
  width: 220px;
}

.date-input {
  width: 170px;
}

.loading {
  color: var(--color-text-muted);
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
  font-size: 0.75rem;
  color: var(--color-text-faint);
}

.chart-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
  gap: 20px;
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
  display: grid;
  place-items: center;
  height: 280px;
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.875rem;
}
</style>
