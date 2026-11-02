<template>
  <div class="page fade-in">
    <PageHeader title="Dashboard" subtitle="Situação de clientes e projetos." icon="fa-solid fa-gauge-high" />

    <p v-if="failed" class="dash-error" role="alert">
      Não foi possível carregar o dashboard.
      <button type="button" @click="load">Tentar novamente</button>
    </p>

    <div class="status-grid">
      <section v-for="card in statusCards" :key="card.title" class="status-card glass-panel">
        <header>
          <span class="status-card__icon" aria-hidden="true"><i :class="card.icon" /></span>
          <h4>{{ card.title }}</h4>
        </header>

        <div class="status-card__figures">
          <div>
            <span class="figure figure--ok">{{ card.data?.on_time ?? '—' }}</span>
            <span class="figure-label"><i class="dot dot--ok" />{{ card.ok }}</span>
          </div>
          <div>
            <span class="figure" :class="{ 'figure--late': card.data?.late }">{{ card.data?.late ?? '—' }}</span>
            <span class="figure-label"><i class="dot dot--late" />{{ card.late }}</span>
          </div>
        </div>

        <div class="split" role="img" :aria-label="`${card.data?.on_time ?? 0} ${card.ok}, ${card.data?.late ?? 0} ${card.late}`">
          <span class="split__ok" :style="{ flexGrow: card.data?.on_time ?? 0 }" />
          <span class="split__late" :style="{ flexGrow: card.data?.late ?? 0 }" />
        </div>
      </section>
    </div>

    <div class="attention-grid">
      <section v-for="list in attention" :key="list.title" class="attention-card glass-panel">
        <header>
          <div>
            <h4>{{ list.title }}</h4>
            <p>{{ list.hint }}</p>
          </div>
          <span class="attention-card__total" :class="{ 'has-items': list.data?.total }">{{ list.data?.total ?? '—' }}</span>
        </header>

        <ul v-if="list.data?.items.length">
          <li v-for="item in list.data.items" :key="item.id">
            <router-link :to="{ name: list.route, params: { id: item.id } }">
              <span class="attention-card__name">{{ item.name }}</span>
              <span class="attention-card__meta">{{ list.meta(item) }}</span>
            </router-link>
          </li>
        </ul>
        <p v-else class="attention-card__empty">{{ list.data ? list.empty : '' }}</p>

        <p v-if="list.data?.total > list.data?.items.length" class="attention-card__more">
          + {{ list.data.total - list.data.items.length }} {{ list.data.total - list.data.items.length === 1 ? 'outro' : 'outros' }}
        </p>
      </section>
    </div>

    <div class="chart-grid">
      <section v-for="chart in charts" :key="chart.title" class="chart-card glass-panel">
        <h4>{{ chart.title }}</h4>
        <p class="chart-card__hint">{{ chart.hint }}</p>
        <EChart v-if="chart.option" :option="chart.option" height="260px" />
        <div v-else class="chart-card__empty">{{ failed ? '' : 'Carregando…' }}</div>
      </section>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { apiFetch } from '@/services/api'
import EChart from '@/components/ui/EChart.vue'
import PageHeader from '@/components/ui/PageHeader.vue'

const summary = ref(null)
const failed = ref(false)

// Brand color is per company (CSS variable), so charts read tokens at render time.
const token = (name) => getComputedStyle(document.documentElement).getPropertyValue(`--color-${name}`).trim()

const monthLabel = (ym) => {
  const [y, m] = ym.split('-').map(Number)
  const name = new Date(y, m - 1, 1).toLocaleDateString('pt-BR', { month: 'short' }).replace('.', '')
  return `${name}/${String(y).slice(2)}`
}

const baseOption = () => ({
  grid: { left: 8, right: 16, top: 24, bottom: 8, containLabel: true },
  tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
})

const axisStyle = () => ({
  axisLine: { lineStyle: { color: token('border') } },
  axisTick: { show: false },
  axisLabel: { color: token('text-muted') },
})

const valueAxis = () => ({
  type: 'value',
  minInterval: 1,
  splitLine: { lineStyle: { color: token('border') } },
  axisLabel: { color: token('text-muted') },
})

const barSeries = (data, color, extra = {}) => ({
  type: 'bar',
  data,
  barMaxWidth: 28,
  itemStyle: { color, borderRadius: [4, 4, 0, 0] },
  label: { show: true, position: 'top', color: token('text'), fontWeight: 600, formatter: (p) => (p.value ? p.value : '') },
  ...extra,
})

const monthlyOption = (series, color) => ({
  ...baseOption(),
  xAxis: { type: 'category', data: series.map((s) => monthLabel(s.month)), ...axisStyle() },
  yAxis: valueAxis(),
  series: [barSeries(series.map((s) => s.value), color)],
})

const joinedOption = computed(() => summary.value && monthlyOption(summary.value.customers_joined, token('primary')))
const doneOption = computed(() => summary.value && monthlyOption(summary.value.projects_done, token('success')))

const lateOption = computed(() => {
  const buckets = summary.value?.projects_late
  if (!buckets) return null

  return {
    ...baseOption(),
    xAxis: { type: 'category', data: buckets.map((b) => b.label), ...axisStyle() },
    yAxis: valueAxis(),
    series: [barSeries(buckets.map((b) => b.value), token('danger'))],
  }
})

// Same tones as CustomerStatus::color().
const STATUS_TONE = { lead: 'info', warming_up: 'warning', customer: 'success', cancelled: 'text-faint' }

const statusOption = computed(() => {
  const rows = summary.value?.customers_by_status
  if (!rows) return null

  return {
    tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
    legend: { bottom: 0, icon: 'circle', textStyle: { color: token('text-secondary') } },
    series: [{
      type: 'pie',
      radius: ['48%', '72%'],
      center: ['50%', '44%'],
      avoidLabelOverlap: true,
      itemStyle: { borderColor: token('bg-card'), borderWidth: 2 },
      label: { show: true, formatter: '{c}', color: token('text'), fontWeight: 600 },
      data: rows.filter((r) => r.value > 0).map((r) => ({
        name: r.label,
        value: r.value,
        itemStyle: { color: token(STATUS_TONE[r.key]) },
      })),
    }],
  }
})

const statusCards = computed(() => [
  { title: 'Clientes', icon: 'fa-solid fa-users', data: summary.value?.customers, ok: 'Em dia', late: 'Com pagamento atrasado' },
  { title: 'Projetos', icon: 'fa-solid fa-diagram-project', data: summary.value?.projects, ok: 'No prazo', late: 'Atrasados' },
])

const plural = (n, one, many) => `${n} ${n === 1 ? one : many}`

const attention = computed(() => {
  const a = summary.value?.attention
  return [
    {
      title: 'Vencem em 7 dias',
      hint: 'Projetos em aberto perto do prazo',
      data: a?.projects_due_soon,
      route: 'ProjectEdit',
      empty: 'Nenhum projeto vence nos próximos 7 dias.',
      meta: (p) => `${p.customer ?? 'Sem cliente'} · ${p.days === 0 ? 'hoje' : `em ${plural(p.days, 'dia', 'dias')}`}`,
    },
    {
      title: 'Leads parados',
      hint: 'Sem atualização há mais de 30 dias',
      data: a?.stale_leads,
      route: 'CustomerEdit',
      empty: 'Nenhum lead parado.',
      meta: (c) => `${c.status} · ${c.days} dias`,
    },
    {
      title: 'Clientes sem projeto',
      hint: 'Clientes ativos sem nenhum projeto',
      data: a?.customers_without_project,
      route: 'CustomerEdit',
      empty: 'Todos os clientes têm projeto.',
      meta: () => '',
    },
  ]
})

const charts = computed(() => [
  { title: 'Clientes que entraram', hint: 'Cadastros por mês, últimos 12 meses', option: joinedOption.value },
  { title: 'Projetos realizados', hint: 'Projetos concluídos por mês, últimos 12 meses', option: doneOption.value },
  { title: 'Projetos atrasados', hint: 'Projetos em aberto após o prazo, por tempo de atraso', option: lateOption.value },
  { title: 'Clientes por tipo', hint: 'Distribuição por status do cliente', option: statusOption.value },
])

const load = async () => {
  failed.value = false
  try {
    const res = await apiFetch('/dashboard/summary')
    summary.value = await res.json()
  } catch (err) {
    failed.value = true
    console.error('Não foi possível carregar o dashboard.', err)
  }
}

onMounted(load)
</script>

<style scoped>
.dash-error {
  padding: 12px 16px;
  border-radius: 12px;
  background: var(--color-danger-soft);
  color: var(--color-danger);
  font-size: 0.875rem;
}

.dash-error button {
  margin-left: 8px;
  font-weight: 600;
  text-decoration: underline;
  color: inherit;
  cursor: pointer;
}

.status-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
  gap: 20px;
}

.status-card {
  display: flex;
  flex-direction: column;
  gap: 16px;
  padding: 20px;
}

.status-card header {
  display: flex;
  align-items: center;
  gap: 10px;
}

.status-card h4,
.chart-card h4 {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--color-text);
}

.status-card__icon {
  display: grid;
  place-items: center;
  width: 32px;
  height: 32px;
  border-radius: 10px;
  background: var(--color-primary-soft);
  color: var(--color-primary);
  font-size: 0.85rem;
}

.status-card__figures {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.figure {
  display: block;
  font-size: 2rem;
  font-weight: 700;
  line-height: 1.1;
  color: var(--color-text);
  font-variant-numeric: tabular-nums;
}

.figure--ok {
  color: var(--color-success);
}

.figure--late {
  color: var(--color-danger);
}

.figure-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-top: 4px;
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.dot--ok {
  background: var(--color-success);
}

.dot--late {
  background: var(--color-danger);
}

.split {
  display: flex;
  gap: 3px;
  height: 8px;
  border-radius: 4px;
  overflow: hidden;
  background: var(--color-bg-muted);
}

.split span {
  flex-basis: 0;
  min-width: 0;
}

.split__ok {
  background: var(--color-success);
}

.split__late {
  background: var(--color-danger);
}

.attention-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
  gap: 20px;
}

.attention-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 20px;
}

.attention-card header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.attention-card h4 {
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--color-text);
}

.attention-card header p {
  margin-top: 2px;
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.attention-card__total {
  min-width: 32px;
  padding: 2px 10px;
  border-radius: 999px;
  background: var(--color-bg-muted);
  color: var(--color-text-secondary);
  font-size: 0.85rem;
  font-weight: 700;
  text-align: center;
}

.attention-card__total.has-items {
  background: var(--color-warning-soft);
  color: var(--color-warning-text);
}

.attention-card ul {
  display: flex;
  flex-direction: column;
  list-style: none;
  margin: 0;
  padding: 0;
}

.attention-card li + li {
  border-top: 1px solid var(--color-border);
}

.attention-card li a {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  padding: 9px 6px;
  border-radius: 8px;
  color: inherit;
  text-decoration: none;
}

.attention-card li a:hover {
  background: var(--color-bg-hover);
}

.attention-card__name {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text);
}

.attention-card__meta {
  flex-shrink: 0;
  font-size: 0.78rem;
  color: var(--color-text-muted);
}

.attention-card__empty,
.attention-card__more {
  margin: 0;
  font-size: 0.82rem;
  color: var(--color-text-muted);
}

.chart-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 420px), 1fr));
  gap: 20px;
}

.chart-card {
  padding: 20px;
}

.chart-card__hint {
  margin: 2px 0 12px;
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.chart-card__empty {
  display: grid;
  place-items: center;
  height: 260px;
  color: var(--color-text-faint);
  font-size: 0.85rem;
}
</style>
