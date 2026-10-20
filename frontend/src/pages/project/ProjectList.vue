<template>
  <div class="page fade-in">
    <PageHeader
      title="Projetos"
      subtitle="Projetos dos clientes, acompanhados por eles no portal do cliente."
      icon="fa-solid fa-diagram-project"
    />

    <div class="list-toolbar">
      <div class="list-toolbar__filters">
        <Input
          class="search-input"
          type="search"
          v-model="searchQuery"
          @input="debounceSearch"
          placeholder="Buscar por projeto ou cliente..."
        >
          <template #prefix>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
          </template>
        </Input>

        <Select
          class="status-filter"
          v-model="statusFilter"
          :options="statusOptions"
          placeholder="Todos os status"
          aria-label="Filtrar por status"
          @change="applyFilter"
        />
      </div>

      <div class="list-toolbar__actions">
        <Button v-can="'projects.create'" variant="create" icon="fa-solid fa-plus" label="Incluir" @click="$router.push('/project/form')" />
        <Button
          v-can="'projects.update'"
          variant="edit"
          icon="fa-solid fa-pen-to-square"
          label="Alterar"
          :disabled="selected.length !== 1"
          @click="editSelected"
        />
        <Button
          variant="secondary"
          icon="fa-solid fa-clock-rotate-left"
          label="Histórico"
          :disabled="selected.length !== 1"
          @click="showHistory = true"
        />
        <Button
          v-can="'projects.delete'"
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
        @update:page="handlePageChange"
        @update:pageSize="handlePageSizeChange"
        @update:selection="(selection) => (selected = selection)"
        @row-dblclick="handleRowDoubleClick"
      />
    </div>

    <ActivityLogModal v-if="selected.length === 1" v-model="showHistory" subject-type="project" :subject-id="selected[0].id" />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AgGrid from '@/components/utils/AgGrid.vue'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ActivityLogModal from '@/components/ui/ActivityLogModal.vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const rows = ref([])
const totalRows = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)
const searchQuery = ref('')
const statusFilter = ref('')
const statusOptions = ref([])
const selected = ref([])
const showHistory = ref(false)
let searchTimeout = null

const columnDefs = ref([
  { field: 'name', headerName: 'Projeto', flex: 1, sortable: true },
  { field: 'customer.name', headerName: 'Cliente', flex: 1, sortable: true },
  {
    field: 'status',
    headerName: 'Status',
    type: 'badge',
    width: 170,
    cellRendererParams: { options: () => statusOptions.value },
  },
  { field: 'progress', headerName: 'Progresso', width: 130, cellClass: 'cell-center', valueFormatter: (params) => `${params.value}%` },
  { field: 'due_date', headerName: 'Previsão', type: 'date', width: 140, sortable: true },
  { field: 'updated_at', headerName: 'Atualizado em', type: 'datetime', width: 190, sortable: true },
])

const fetchRows = async () => {
  try {
    const params = new URLSearchParams({ page: currentPage.value, per_page: pageSize.value, search: searchQuery.value, status: statusFilter.value })
    const response = await apiFetch(`/projects?${params}`).then((res) => res.json())
    rows.value = response.data
    totalRows.value = response.total
    selected.value = []
  } catch (err) {
    console.error('Erro ao buscar projetos:', err)
  }
}

const applyFilter = () => {
  currentPage.value = 1
  fetchRows()
}

const debounceSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(applyFilter, 400)
}

const handlePageChange = (page) => {
  currentPage.value = page
  fetchRows()
}

const handlePageSizeChange = (size) => {
  pageSize.value = size
  applyFilter()
}

const handleRowDoubleClick = (row) => {
  if (auth.can('projects.update')) router.push(`/project/form/${row.id}`)
}

const editSelected = () => router.push(`/project/form/${selected.value[0].id}`)

const deleteSelected = async () => {
  const targets = [...selected.value]
  const ok = await swal.confirmDelete({ count: targets.length, entity: 'projeto', entityPlural: 'projetos' })
  if (!ok) return

  try {
    for (const project of targets) {
      const res = await apiFetch(`/projects/${project.id}`, { method: 'DELETE' })
      if (!res.ok) throw new Error((await res.json()).message)
    }
    swal.toastSuccess(targets.length > 1 ? 'Projetos excluídos com sucesso!' : 'Projeto excluído com sucesso!')
  } catch (err) {
    swal.toastError(err.message || 'Falha ao excluir projeto(s).')
  } finally {
    await fetchRows()
  }
}

// Statuses first: the grid's badges need their colors/icons.
onMounted(async () => {
  const response = await apiFetch('/projects/statuses')
  if (response.ok) statusOptions.value = (await response.json()).statuses
  await fetchRows()
})
</script>
