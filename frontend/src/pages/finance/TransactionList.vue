<template>
  <div class="page fade-in">
    <PageHeader
      title="Transações"
      subtitle="Receitas e despesas lançadas pelo sistema, pelo Telegram ou por importações."
      icon="fa-solid fa-money-bill-transfer"
    />

    <div class="list-toolbar">
      <div class="list-toolbar__filters">
        <Input class="search-input" type="search" v-model="filters.search" placeholder="Buscar descrição..." @input="debounceFetch">
          <template #prefix>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
          </template>
        </Input>
        <Input class="date-input" type="date" v-model="filters.from" aria-label="De" title="De" @change="applyFilters" />
        <Input class="date-input" type="date" v-model="filters.to" aria-label="Até" title="Até" @change="applyFilters" />
        <Select class="filter" v-model="filters.type" :options="options.types" placeholder="Todos os tipos" aria-label="Tipo" @change="applyFilters" />
        <CategorySelect class="filter filter--wide" v-model="filters.category_id" :type="filters.type" placeholder="Todas as categorias" clearable aria-label="Categoria" @change="applyFilters" />
        <Select class="filter" v-model="filters.source" :options="options.sources" placeholder="Todas as origens" aria-label="Origem" @change="applyFilters" />
        <Input class="amount-input" type="number" min="0" step="0.01" v-model="filters.min_amount" placeholder="Valor mín." aria-label="Valor mínimo" @input="debounceFetch" />
        <Input class="amount-input" type="number" min="0" step="0.01" v-model="filters.max_amount" placeholder="Valor máx." aria-label="Valor máximo" @input="debounceFetch" />
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
import { useRouter } from 'vue-router'
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
import { periodRange } from '@/utils/period'
import { formatMoney } from '@/utils/money'

const router = useRouter()
const auth = useAuthStore()
const canEdit = computed(() => auth.can('finance.transaction.update'))

const rows = ref([])
const totalRows = ref(0)
const currentPage = ref(1)
const pageSize = ref(25)
const selected = ref([])
const showHistory = ref(false)
const options = ref({ types: [], statuses: [], sources: [] })
const filters = ref({
  search: '',
  ...periodRange('month'),
  type: '',
  category_id: '',
  source: '',
  min_amount: '',
  max_amount: '',
})
let debounceTimer = null

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

const fetchTransactions = async () => {
  const params = new URLSearchParams({ page: currentPage.value, per_page: pageSize.value })
  Object.entries(filters.value).forEach(([key, value]) => {
    if (value !== '' && value != null) params.set(key, value)
  })
  try {
    const response = await apiFetch(`/finance/transactions?${params}`)
    const data = await response.json()
    if (!response.ok) throw new Error(data.message)
    rows.value = data.data
    totalRows.value = data.total
    selected.value = []
  } catch (err) {
    swal.toastError(err.message || 'Erro ao buscar transações.')
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

const open = (row) => {
  if (row) router.push(`/finance/transactions/form/${row.id}`)
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

.grid-wrap {
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}
</style>
