<template>
  <div class="page fade-in">
    <PageHeader
      title="Dashboard"
      subtitle="Resumo com dados de exemplo — troque por consultas reais assim que tiver models."
      icon="fa-solid fa-gauge-high"
    />

    <div class="kpi-grid">
      <div class="kpi-card glass-panel">
        <span class="kpi-label">Total de Registros</span>
        <span class="kpi-value">{{ summary?.total ?? '—' }}</span>
      </div>
      <div class="kpi-card glass-panel">
        <span class="kpi-label">Receita do Mês</span>
        <span class="kpi-value">{{ formatMoney(summary?.monthly_revenue) }}</span>
      </div>
      <div class="kpi-card glass-panel">
        <span class="kpi-label">Pendências</span>
        <span class="kpi-value" :class="{ 'is-danger': summary?.overdue_count }">{{ summary?.overdue_count ?? '—' }}</span>
      </div>
      <div class="kpi-card glass-panel">
        <span class="kpi-label">Próximo Vencimento</span>
        <span class="kpi-value kpi-value--sm">{{ nextDueLabel }}</span>
      </div>
    </div>

    <div class="chart-grid">
      <div class="chart-card glass-panel">
        <h4>Registros por Categoria</h4>
        <EChart v-if="typeChartOption" :option="typeChartOption" height="260px" />
      </div>
      <div class="chart-card glass-panel">
        <h4>Receita Mensal</h4>
        <EChart v-if="revenueChartOption" :option="revenueChartOption" height="260px" />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { apiFetch } from '@/services/api'
import EChart from '@/components/ui/EChart.vue'
import PageHeader from '@/components/ui/PageHeader.vue'

const CHART_AXIS_COLOR = '#64748b'
const CHART_GRID_COLOR = '#e2e8f0'
const CHART_LABEL_COLOR = '#0f172a'

const summary = ref(null)

const formatMoney = (value) => {
  if (!value) return 'R$ 0,00'
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value)
}

const nextDueLabel = computed(() => {
  const next = summary.value?.upcoming_due?.[0]
  if (!next) return '—'
  const when = next.days_until_due === 0 ? 'hoje' : `em ${next.days_until_due}d`
  return `${next.name} · ${when}`
})

const typeChartOption = computed(() => {
  const byCategory = summary.value?.by_category
  if (!byCategory) return null

  return {
    grid: { left: 8, right: 16, top: 24, bottom: 28, containLabel: true },
    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
    xAxis: {
      type: 'category',
      data: Object.keys(byCategory),
      axisLine: { lineStyle: { color: CHART_GRID_COLOR } },
      axisTick: { show: false },
      axisLabel: { color: CHART_AXIS_COLOR },
    },
    yAxis: {
      type: 'value',
      minInterval: 1,
      splitLine: { lineStyle: { color: CHART_GRID_COLOR } },
      axisLabel: { color: CHART_AXIS_COLOR },
    },
    series: [{
      type: 'bar',
      data: Object.values(byCategory),
      barMaxWidth: 24,
      itemStyle: { color: '#4f46e5', borderRadius: [4, 4, 0, 0] },
      label: { show: true, position: 'top', color: CHART_LABEL_COLOR, fontWeight: 600 },
    }],
  }
})

const revenueChartOption = computed(() => {
  const series = summary.value?.monthly_series
  if (!series) return null

  return {
    grid: { left: 8, right: 16, top: 24, bottom: 28, containLabel: true },
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow' },
      valueFormatter: (v) => formatMoney(v),
    },
    xAxis: {
      type: 'category',
      data: series.map((v) => v.month),
      axisLine: { lineStyle: { color: CHART_GRID_COLOR } },
      axisTick: { show: false },
      axisLabel: { color: CHART_AXIS_COLOR },
    },
    yAxis: {
      type: 'value',
      splitLine: { lineStyle: { color: CHART_GRID_COLOR } },
      axisLabel: { color: CHART_AXIS_COLOR, formatter: (v) => `R$ ${v}` },
    },
    series: [{
      type: 'bar',
      data: series.map((v) => v.value),
      barMaxWidth: 24,
      itemStyle: { color: '#2563eb', borderRadius: [4, 4, 0, 0] },
      label: {
        show: true,
        position: 'top',
        color: CHART_LABEL_COLOR,
        fontWeight: 600,
        formatter: (p) => formatMoney(p.value),
      },
    }],
  }
})

const fetchSummary = async () => {
  try {
    summary.value = await apiFetch('/dashboard/summary').then((res) => res.json())
  } catch (err) {
    console.error('Não foi possível carregar o resumo do dashboard.', err)
  }
}

onMounted(fetchSummary)
</script>

<style scoped>
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.kpi-value {
  font-size: 1.6rem;
  font-weight: 700;
  color: var(--color-text);
}

.kpi-value--sm {
  font-size: 1rem;
  font-weight: 600;
}

.kpi-value.is-danger {
  color: var(--color-danger);
}

.chart-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 20px;
}

.chart-card {
  padding: 20px;
  border-radius: 16px;
}

.chart-card h4 {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--color-text);
  margin-bottom: 12px;
}
</style>
