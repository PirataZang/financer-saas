<template>
  <div class="page fade-in">
    <PageHeader
      title="Transações"
      subtitle="Receitas e despesas lançadas pelo sistema, pelo Telegram ou por importações."
      icon="fa-solid fa-money-bill-transfer"
    />

    <div class="list-toolbar">
      <div class="period-chips" role="group" aria-label="Período">
        <button
          v-for="p in QUICK_PERIODS"
          :key="p.value"
          type="button"
          class="filter-chip"
          :class="{ 'is-active': period === p.value }"
          :aria-pressed="period === p.value"
          @click="pickPeriod(p.value)"
        >
          {{ p.label }}
        </button>
      </div>

      <div class="list-toolbar__filters">
        <Input class="search-input" type="search" v-model="filters.search" placeholder="Buscar descrição..." @input="debounceFetch">
          <template #prefix>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
          </template>
        </Input>
        <Input class="date-input" type="date" v-model="filters.from" aria-label="De" title="De" @change="onDatesChange" />
        <Input class="date-input" type="date" v-model="filters.to" aria-label="Até" title="Até" @change="onDatesChange" />
        <Select class="filter" v-model="filters.type" :options="options.types" placeholder="Todos os tipos" aria-label="Tipo" @change="applyFilters" />
        <CategorySelect class="filter filter--wide" v-model="filters.category_id" :type="filters.type" placeholder="Todas as categorias" clearable aria-label="Categoria" @change="applyFilters" />
        <Select class="filter" v-model="filters.status" :options="options.statuses" placeholder="Todos os status" aria-label="Status" @change="applyFilters" />
        <Select class="filter" v-model="filters.source" :options="options.sources" placeholder="Todas as origens" aria-label="Origem" @change="applyFilters" />
        <Input class="amount-input" type="number" min="0" step="0.01" v-model="filters.min_amount" placeholder="Valor mín." aria-label="Valor mínimo" @input="debounceFetch" />
        <Input class="amount-input" type="number" min="0" step="0.01" v-model="filters.max_amount" placeholder="Valor máx." aria-label="Valor máximo" @input="debounceFetch" />
        <Button v-if="hasExtraFilters" variant="ghost" size="sm" icon="fa-solid fa-filter-circle-xmark" label="Limpar filtros" @click="resetFilters" />
      </div>

      <div class="list-toolbar__actions">
        <Button v-can="'finance.transaction.create'" variant="create" icon="fa-solid fa-plus" label="Incluir" @click="$router.push('/finance/transactions/form')" />
        <Button
          :variant="canEdit ? 'edit' : 'secondary'"
          :icon="canEdit ? 'fa-solid fa-pen-to-square' : 'fa-solid fa-eye'"
          :label="canEdit ? 'Alterar' : 'Visualizar'"
          :disabled="selected.length !== 1"
          @click="open(selected[0])"
        />
        <Button
          v-can="'finance.transaction.create'"
          variant="secondary"
          icon="fa-solid fa-copy"
          label="Duplicar"
          title="Novo lançamento igual ao selecionado, com a data de hoje"
          :disabled="selected.length !== 1"
          @click="duplicate(selected[0])"
        />
        <Button
          v-can="'finance.transaction.update'"
          variant="success"
          icon="fa-solid fa-circle-check"
          :label="pendingSelected.length > 0 ? `Efetivar (${pendingSelected.length})` : 'Efetivar'"
          title="Marca as transações pendentes selecionadas como efetivadas"
          :disabled="pendingSelected.length === 0 || markingPaid"
          @click="markPaid"
        />
        <Button variant="secondary" icon="fa-solid fa-clock-rotate-left" label="Histórico" :disabled="selected.length !== 1" @click="showHistory = true" />
        <Button
          v-can="'finance.transaction.delete'"
          variant="danger"
          icon="fa-solid fa-trash-can"
          :label="selected.length > 0 ? `Excluir (${selected.length})` : 'Excluir'"
          :disabled="selected.length === 0"
          @click="deleteSelected"
        />
      </div>
    </div>

    <!-- Totals of every row matching the filters (all pages), paid and pending together. -->
    <section v-if="totals" class="totals" :class="{ 'is-loading': loading }" aria-label="Totais do filtro" aria-live="polite">
      <div class="total">
        <span class="total__label"><i class="fa-solid fa-arrow-trend-up" aria-hidden="true" /> Receitas</span>
        <strong class="total__value">{{ formatMoney(totals.income) }}</strong>
      </div>
      <div class="total">
        <span class="total__label"><i class="fa-solid fa-arrow-trend-down" aria-hidden="true" /> Despesas</span>
        <strong class="total__value">{{ formatMoney(totals.expense) }}</strong>
      </div>
      <div class="total">
        <span class="total__label"><i class="fa-solid fa-scale-balanced" aria-hidden="true" /> Saldo</span>
        <strong class="total__value" :class="{ 'is-negative': totals.balance < 0 }">{{ formatMoney(totals.balance) }}</strong>
      </div>
      <component
        :is="canFilterPending ? 'button' : 'div'"
        :type="canFilterPending ? 'button' : undefined"
        class="total"
        :class="{ 'total--action': canFilterPending }"
        :title="canFilterPending ? 'Mostrar só as pendentes' : undefined"
        @click="canFilterPending && showPending()"
      >
        <span class="total__label"><i class="fa-solid fa-list-ul" aria-hidden="true" /> Lançamentos</span>
        <strong class="total__value">{{ totalRows }}</strong>
        <span class="total__hint" :class="{ 'is-warning': totals.pending > 0 }">
          <i v-if="totals.pending > 0" class="fa-solid fa-circle-exclamation total__alert" aria-hidden="true" />
          {{ totals.pending === 0 ? 'nenhum pendente' : `${totals.pending} ${totals.pending === 1 ? 'pendente' : 'pendentes'}` }}
          <i v-if="canFilterPending" class="fa-solid fa-arrow-right" aria-hidden="true" />
        </span>
      </component>
    </section>

    <div class="grid-wrap">
      <AgGrid
        :rowData="rows"
        :columnDefs="columnDefs"
        :currentPage="currentPage"
        :pageSize="pageSize"
        :totalRows="totalRows"
        :selectable="true"
        @update:page="(page) => { currentPage = page; fetchTransactions() }"
        @update:pageSize="(size) => { pageSize = size; currentPage = 1; fetchTransactions() }"
        @update:selection="(selection) => (selected = selection)"
        @row-dblclick="open"
      />
    </div>

    <ActivityLogModal v-if="selected.length === 1" v-model="showHistory" subject-type="transaction" :subject-id="selected[0].id" />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AgGrid from '@/components/utils/AgGrid.vue'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import CategorySelect from '@/components/finance/CategorySelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ActivityLogModal from '@/components/ui/ActivityLogModal.vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'
import { PERIODS, periodRange } from '@/utils/period'
import { formatMoney } from '@/utils/money'

// Presets as chips; "Tudo" drops the dates. Typing a date by hand = custom (no chip lit).
const QUICK_PERIODS = [...PERIODS.filter((p) => p.value !== 'custom'), { value: 'all', label: 'Tudo' }]
const DEFAULT_PERIOD = 'month'
const DEFAULT_FILTERS = () => ({ search: '', type: '', category_id: '', status: '', source: '', min_amount: '', max_amount: '' })

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const canEdit = computed(() => auth.can('finance.transaction.update'))

const rows = ref([])
const totals = ref(null)
const totalRows = ref(0)
const currentPage = ref(1)
const pageSize = ref(25)
const selected = ref([])
const showHistory = ref(false)
const loading = ref(false)
const markingPaid = ref(false)
const options = ref({ types: [], statuses: [], sources: [] })
const period = ref(DEFAULT_PERIOD)
const filters = ref({ ...DEFAULT_FILTERS(), ...periodRange(DEFAULT_PERIOD) })
let debounceTimer = null

const hasExtraFilters = computed(() => period.value !== DEFAULT_PERIOD || Object.entries(DEFAULT_FILTERS()).some(([key, value]) => filters.value[key] !== value))
const pendingSelected = computed(() => selected.value.filter((row) => row.status === 'pending'))
const canFilterPending = computed(() => totals.value?.pending > 0 && filters.value.status !== 'pending')

// Category badges come from the rows themselves (also covers deleted/inactive categories).
const rowCategories = computed(() =>
  rows.value.map((row) => ({ value: row.category_id, label: row.category?.name ?? '—', color: row.category?.color, icon: row.category?.icon })),
)

const columnDefs = [
  { field: 'date', headerName: 'Data', type: 'date', width: 120 },
  { field: 'type', headerName: 'Tipo', type: 'badge', width: 130, cellRendererParams: { options: () => options.value.types } },
  { field: 'description', headerName: 'Descrição', flex: 1, minWidth: 200 },
  { field: 'category_id', headerName: 'Categoria', type: 'badge', width: 190, cellRendererParams: { options: () => rowCategories.value } },
  {
    field: 'amount',
    headerName: 'Valor',
    width: 150,
    type: 'rightAligned',
    filter: 'agNumberColumnFilter',
    comparator: (a, b) => Number(a) - Number(b),
    valueFormatter: (p) => (p.data.type === 'expense' ? '− ' : '+ ') + formatMoney(p.value),
  },
  { field: 'source', headerName: 'Origem', type: 'badge', width: 150, cellRendererParams: { options: () => options.value.sources } },
  { field: 'status', headerName: 'Status', type: 'badge', width: 140, cellRendererParams: { options: () => options.value.statuses } },
]

// Period, filters and page live in the URL: coming back from the form (or reloading, or sharing the
// link) shows the same view. Preset dates are recomputed from `period`, so "Este mês" stays current.
const syncUrl = () => {
  const query = { period: period.value }
  if (currentPage.value > 1) query.page = currentPage.value
  Object.entries(filters.value).forEach(([key, value]) => {
    if (value !== '' && value != null) query[key] = value
  })
  if (period.value !== 'custom') {
    delete query.from
    delete query.to
  }
  router.replace({ query })
}

const restoreFromUrl = () => {
  const q = route.query
  period.value = q.period === 'custom' || QUICK_PERIODS.some((p) => p.value === q.period) ? q.period : DEFAULT_PERIOD
  const dates = period.value === 'custom' ? { from: q.from ?? '', to: q.to ?? '' } : (periodRange(period.value) ?? { from: '', to: '' })
  filters.value = { ...DEFAULT_FILTERS(), ...dates }
  Object.keys(DEFAULT_FILTERS()).forEach((key) => {
    if (q[key] != null) filters.value[key] = q[key]
  })
  // Option values are numbers; the URL gives strings.
  if (filters.value.category_id) filters.value.category_id = Number(filters.value.category_id)
  currentPage.value = Number(q.page) || 1
}

const fetchTransactions = async () => {
  syncUrl()
  const params = new URLSearchParams({ page: currentPage.value, per_page: pageSize.value })
  Object.entries(filters.value).forEach(([key, value]) => {
    if (value !== '' && value != null) params.set(key, value)
  })
  loading.value = true
  try {
    const response = await apiFetch(`/finance/transactions?${params}`)
    const data = await response.json()
    if (!response.ok) throw new Error(data.message)
    rows.value = data.data
    totalRows.value = data.total
    totals.value = data.totals
    selected.value = []
  } catch (err) {
    swal.toastError(err.message || 'Erro ao buscar transações.')
  } finally {
    loading.value = false
  }
}

const applyFilters = () => {
  currentPage.value = 1
  fetchTransactions()
}

const debounceFetch = () => {
  clearTimeout(debounceTimer)
  debounceTimer = setTimeout(applyFilters, 400)
}

const pickPeriod = (value) => {
  period.value = value
  Object.assign(filters.value, periodRange(value) ?? { from: '', to: '' })
  applyFilters()
}

const onDatesChange = () => {
  period.value = 'custom'
  applyFilters()
}

const resetFilters = () => {
  period.value = DEFAULT_PERIOD
  filters.value = { ...DEFAULT_FILTERS(), ...periodRange(DEFAULT_PERIOD) }
  applyFilters()
}

const showPending = () => {
  filters.value.status = 'pending'
  applyFilters()
}

const open = (row) => {
  if (row) router.push(`/finance/transactions/form/${row.id}`)
}

const duplicate = (row) => {
  if (row) router.push({ path: '/finance/transactions/form', query: { duplicate: row.id } })
}

// One request for the whole selection (a per-row loop would hit the API rate limit on a full page).
const markPaid = async () => {
  markingPaid.value = true
  try {
    const response = await apiFetch('/finance/transactions/status', {
      method: 'PATCH',
      body: JSON.stringify({ ids: pendingSelected.value.map((row) => row.id), status: 'paid' }),
    })
    const data = await response.json()
    if (!response.ok) throw new Error(data.message)
    swal.toastSuccess(data.updated === 1 ? 'Transação efetivada!' : `${data.updated} transações efetivadas!`)
  } catch (err) {
    swal.toastError(err.message || 'Falha ao efetivar transação(ões).')
  } finally {
    markingPaid.value = false
  }
  await fetchTransactions()
}

const deleteSelected = async () => {
  const targets = [...selected.value]
  const ok = await swal.confirmDelete({ count: targets.length, entity: 'transação', entityPlural: 'transações', feminine: true })
  if (!ok) return

  try {
    for (const transaction of targets) {
      const response = await apiFetch(`/finance/transactions/${transaction.id}`, { method: 'DELETE' })
      if (!response.ok) throw new Error((await response.json()).message)
    }
    swal.toastSuccess(targets.length > 1 ? 'Transações excluídas com sucesso!' : 'Transação excluída com sucesso!')
  } catch (err) {
    swal.toastError(err.message || 'Falha ao excluir transação(ões).')
  }
  await fetchTransactions()
}

// Enum options first: the grid's badges need their labels/colors.
onMounted(async () => {
  restoreFromUrl()
  const response = await apiFetch('/finance/transactions/options')
  if (response.ok) options.value = await response.json()
  await fetchTransactions()
})
</script>

<style scoped>
.list-toolbar {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.period-chips,
.list-toolbar__filters,
.list-toolbar__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.list-toolbar .search-input {
  width: 260px;
}

.list-toolbar .date-input {
  width: 160px;
}

.list-toolbar .filter {
  width: 180px;
}

.list-toolbar .filter--wide {
  width: 220px;
}

.list-toolbar .amount-input {
  width: 130px;
}

.totals {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 12px;
  transition: opacity 0.15s ease;
}

/* Refetching keeps the last numbers visible, just quieter — no layout jump. */
.totals.is-loading {
  opacity: 0.55;
}

.total {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 12px 16px;
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  text-align: left;
  font: inherit;
}

.total--action {
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}

.total--action:hover {
  background: var(--color-bg-hover);
  border-color: var(--color-border-strong);
}

.total--action:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}

.total__label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.total__value {
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--color-text);
  font-variant-numeric: tabular-nums;
}

.total__value.is-negative {
  color: var(--color-danger);
}

.total__hint {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

/* Amber only on the icon: as small text it wouldn't reach AA contrast on white. */
.total__hint.is-warning {
  font-weight: 600;
  color: var(--color-text-secondary);
}

.total__alert {
  color: var(--color-warning);
}

.grid-wrap {
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}
</style>
