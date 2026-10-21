<template>
  <div class="page fade-in">
    <PageHeader
      title="Metas"
      subtitle="Cada meta tem a própria categoria: o que for lançado nela é o dinheiro separado da meta."
      icon="fa-solid fa-bullseye"
    />

    <div class="list-toolbar">
      <div class="list-toolbar__filters">
        <Select class="filter" v-model="typeFilter" :options="options.types" placeholder="Todos os tipos" aria-label="Filtrar por tipo" />
        <Select class="filter" v-model="statusFilter" :options="options.statuses" placeholder="Todas as situações" aria-label="Filtrar por situação" />
      </div>

      <div class="list-toolbar__actions">
        <Button v-can="'finance.goal.create'" variant="create" icon="fa-solid fa-plus" label="Incluir" @click="$router.push('/finance/goals/form')" />
      </div>
    </div>

    <div v-if="loading" class="empty-state">
      <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
      <span>Carregando metas...</span>
    </div>

    <div v-else-if="!visible.length" class="empty-state">
      <i class="fa-solid fa-bullseye" aria-hidden="true" />
      <span>{{ goals.length ? 'Nenhuma meta encontrada para o filtro.' : 'Nenhuma meta cadastrada.' }}</span>
    </div>

    <ul v-else class="goal-grid">
      <li v-for="goal in visible" :key="goal.id" class="goal-card" :style="{ '--goal-color': goal.category.color }">
        <div class="goal-card__head">
          <span class="goal-card__icon" aria-hidden="true">{{ goal.category.icon || '🎯' }}</span>
          <div class="goal-card__title">
            <strong :title="goal.category.name">{{ goal.category.name }}</strong>
            <div class="goal-card__badges">
              <StatusBadge v-bind="badge(options.types, goal.type)" small />
              <StatusBadge v-bind="badge(options.statuses, goal.progress.status)" small />
            </div>
          </div>
        </div>

        <div class="goal-card__amounts">
          <span class="goal-card__current">{{ formatMoney(goal.progress.current) }}</span>
          <span class="goal-card__target">de {{ formatMoney(goal.target_amount) }}{{ goal.type === 'spending' ? ' (teto)' : '' }}</span>
        </div>

        <div
          class="goal-progress"
          role="progressbar"
          :aria-valuenow="goal.progress.percent"
          aria-valuemin="0"
          aria-valuemax="100"
          :aria-label="`${goal.category.name}: ${percentLabel(goal)}`"
        >
          <span class="goal-progress__bar" :class="barTone(goal)" :style="{ width: `${Math.min(Math.max(goal.progress.percent, 0), 100)}%` }" />
        </div>

        <ul class="goal-card__facts">
          <li><strong>{{ percentLabel(goal) }}</strong> · {{ remainingLabel(goal) }}</li>
          <li v-if="goal.progress.pending">{{ formatMoney(goal.progress.pending) }} em lançamentos pendentes</li>
          <li>{{ deadlineLabel(goal) }}</li>
          <li v-if="paceLabel(goal)">{{ paceLabel(goal) }}</li>
        </ul>

        <div class="goal-card__actions">
          <template v-if="auth.can('finance.transaction.create')">
            <Button
              variant="secondary"
              size="sm"
              :icon="goal.type === 'saving' ? 'fa-solid fa-piggy-bank' : 'fa-solid fa-receipt'"
              :label="goal.type === 'saving' ? 'Aportar' : 'Lançar gasto'"
              @click="launch(goal, 'expense')"
            />
            <Button
              v-if="goal.type === 'saving'"
              variant="ghost"
              size="sm"
              icon="fa-solid fa-hand-holding-dollar"
              label="Resgatar"
              @click="launch(goal, 'income')"
            />
          </template>
          <span class="goal-card__spacer" />
          <Button
            v-can="'finance.transaction.view'"
            variant="ghost"
            size="sm"
            icon="fa-solid fa-list"
            icon-only
            :aria-label="`Lançamentos de ${goal.category.name}`"
            title="Lançamentos"
            @click="$router.push({ path: '/finance/transactions', query: { period: 'all', category_id: goal.category_id } })"
          />
          <Button
            v-can="'finance.goal.update'"
            variant="ghost"
            size="sm"
            icon="fa-solid fa-pen-to-square"
            icon-only
            :aria-label="`Alterar ${goal.category.name}`"
            title="Alterar"
            @click="$router.push(`/finance/goals/form/${goal.id}`)"
          />
          <Button
            variant="ghost"
            size="sm"
            icon="fa-solid fa-clock-rotate-left"
            icon-only
            :aria-label="`Histórico de ${goal.category.name}`"
            title="Histórico"
            @click="historyId = goal.id"
          />
          <Button
            v-can="'finance.goal.delete'"
            variant="ghost"
            size="sm"
            icon="fa-solid fa-trash-can"
            icon-only
            class="is-danger"
            :aria-label="`Excluir ${goal.category.name}`"
            title="Excluir"
            @click="remove(goal)"
          />
        </div>
      </li>
    </ul>

    <ActivityLogModal
      v-if="historyId"
      :model-value="!!historyId"
      subject-type="goal"
      :subject-id="historyId"
      @update:model-value="historyId = null"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from '@/components/utils/Button.vue'
import Select from '@/components/utils/Select.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ActivityLogModal from '@/components/ui/ActivityLogModal.vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'
import { formatMoney } from '@/utils/money'
import { formatDate } from '@/utils/date'
import { toDateString } from '@/utils/period'

const DAY = 86_400_000

const auth = useAuthStore()
const router = useRouter()
const goals = ref([])
const options = ref({ types: [], statuses: [] })
const loading = ref(true)
const typeFilter = ref('')
const statusFilter = ref('')
const historyId = ref(null)

const visible = computed(() =>
  goals.value.filter(
    (goal) => (!typeFilter.value || goal.type === typeFilter.value) && (!statusFilter.value || goal.progress.status === statusFilter.value),
  ),
)

const badge = (list, value) => {
  const option = list.find((o) => o.value === value)
  return { label: option?.label ?? value, color: option?.color, icon: option?.icon }
}

const percentLabel = (goal) => `${goal.progress.percent.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`

// Both "YYYY-MM-DD": parsed as UTC midnight, so the difference is a whole number of days.
const daysLeft = (goal) => (goal.end_date ? Math.round((Date.parse(goal.end_date) - Date.parse(toDateString(new Date()))) / DAY) : null)

const remainingLabel = (goal) => {
  const diff = Number(goal.target_amount) - goal.progress.current
  if (goal.type === 'saving') return diff > 0 ? `faltam ${formatMoney(diff)}` : 'objetivo alcançado'
  return diff >= 0 ? `${formatMoney(diff)} disponíveis no teto` : `passou ${formatMoney(-diff)} do teto`
}

const deadlineLabel = (goal) => {
  const days = daysLeft(goal)
  if (days === null) return `Desde ${formatDate(goal.start_date)}, sem prazo`
  if (days < 0) return `Prazo encerrado em ${formatDate(goal.end_date)}`
  return `Prazo ${formatDate(goal.end_date)} · ${days === 0 ? 'termina hoje' : `${days} ${days === 1 ? 'dia' : 'dias'}`}`
}

// How much per month still gets a saving goal there in time.
const paceLabel = (goal) => {
  const days = daysLeft(goal)
  const diff = Number(goal.target_amount) - goal.progress.current
  if (goal.type !== 'saving' || goal.progress.status !== 'in_progress' || !days || diff <= 0) return ''
  const months = Math.max(days / 30.44, 1)
  return `≈ ${formatMoney(diff / months)} por mês para chegar no prazo`
}

// A ceiling warms up as it fills; a saving goal fills in its own color.
const barTone = (goal) => {
  if (goal.type !== 'spending') return ''
  if (goal.progress.percent > 100) return 'is-over'
  return goal.progress.percent >= 80 ? 'is-near' : 'is-ok'
}

const launch = (goal, type) => router.push({ path: '/finance/transactions/form', query: { type, category_id: goal.category_id } })

const remove = async (goal) => {
  const ok = await swal.confirmDelete({
    entity: 'meta',
    feminine: true,
    text: `A categoria "${goal.category.name}" também é excluída. Os lançamentos continuam no histórico com ela, mas ela deixa de aparecer para novos lançamentos. Excluir?`,
  })
  if (!ok) return

  const response = await apiFetch(`/finance/goals/${goal.id}`, { method: 'DELETE' })
  if (!response.ok) {
    swal.toastError((await response.json()).message || 'Falha ao excluir a meta.')
    return
  }
  goals.value = goals.value.filter((g) => g.id !== goal.id)
  swal.toastSuccess('Meta excluída com sucesso!')
}

onMounted(async () => {
  try {
    const [optionsResponse, goalsResponse] = await Promise.all([apiFetch('/finance/goals/options'), apiFetch('/finance/goals')])
    if (optionsResponse.ok) options.value = await optionsResponse.json()
    if (goalsResponse.ok) goals.value = await goalsResponse.json()
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.list-toolbar {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 12px;
}

.list-toolbar__filters,
.list-toolbar__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.list-toolbar .filter {
  width: 220px;
}

.goal-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.goal-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px 16px 10px;
  background: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-left: 4px solid var(--goal-color);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-sm);
  transition: box-shadow 0.15s ease;
}

.goal-card:hover {
  box-shadow: var(--shadow-md);
}

.goal-card__head {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.goal-card__icon {
  flex: 0 0 48px;
  height: 48px;
  display: grid;
  place-items: center;
  border-radius: var(--radius-lg);
  background: color-mix(in srgb, var(--goal-color) 14%, white);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--goal-color) 25%, transparent);
  font-size: 1.5rem;
}

.goal-card__title {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.goal-card__title strong {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: var(--color-text);
  font-size: 0.95rem;
}

.goal-card__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.goal-card__amounts {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 6px;
}

.goal-card__current {
  font-size: 1.4rem;
  font-weight: 700;
  color: var(--color-text);
  font-variant-numeric: tabular-nums;
}

.goal-card__target {
  font-size: 0.85rem;
  color: var(--color-text-muted);
}

.goal-progress {
  height: 10px;
  padding: 2px;
  border-radius: var(--radius-full);
  background: color-mix(in srgb, var(--goal-color) 12%, var(--color-bg-muted));
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--goal-color) 18%, transparent);
  overflow: hidden;
}

/* min-width keeps 1–5% a visible round pill instead of a sliver; 0% stays empty. */
.goal-progress__bar {
  display: block;
  height: 100%;
  min-width: 6px;
  border-radius: inherit;
  background: var(--goal-color);
  transition: width 0.4s ease;
}

.goal-progress__bar[style*='width: 0%'] {
  min-width: 0;
}

.goal-progress__bar.is-ok {
  background: var(--color-success);
}

.goal-progress__bar.is-near {
  background: var(--color-warning);
}

.goal-progress__bar.is-over {
  background: var(--color-danger);
}

.goal-card__facts {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0;
  padding: 0;
  list-style: none;
  font-size: 0.8rem;
  color: var(--color-text-muted);
}

.goal-card__facts strong {
  color: var(--color-text);
}

.goal-card__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
  padding-top: 8px;
  border-top: 1px solid var(--color-border);
}

.goal-card__spacer {
  flex: 1;
}

.goal-card__actions .is-danger:hover {
  color: var(--color-danger);
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 48px 24px;
  background: var(--color-bg-card);
  border: 1px dashed var(--color-border-strong);
  border-radius: var(--radius-xl);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.empty-state i {
  font-size: 1.4rem;
}

@media (max-width: 640px) {
  .list-toolbar .filter {
    width: 100%;
  }

  .goal-grid {
    grid-template-columns: 1fr;
  }
}
</style>
