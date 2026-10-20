<template>
  <div class="page fade-in">
    <PageHeader
      title="Avisos"
      subtitle="Comunicados publicados no portal do cliente: gerais ou de um projeto."
      icon="fa-solid fa-bullhorn"
    />

    <div class="list-toolbar">
      <div class="list-toolbar__filters">
        <Input class="search-input" type="search" v-model="searchQuery" @input="debounceSearch" placeholder="Buscar por título ou texto...">
          <template #prefix>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
          </template>
        </Input>
      </div>

      <div class="list-toolbar__actions">
        <Button v-can="'announcements.create'" variant="create" icon="fa-solid fa-plus" label="Incluir" @click="$router.push('/announcement/form')" />
        <Button
          v-can="'announcements.update'"
          variant="edit"
          icon="fa-solid fa-pen-to-square"
          label="Alterar"
          :disabled="selected.length !== 1"
          @click="editSelected"
        />
        <Button
          v-can="'announcements.delete'"
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
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AgGrid from '@/components/utils/AgGrid.vue'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
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
const selected = ref([])
let searchTimeout = null

const columnDefs = ref([
  { field: 'title', headerName: 'Título', flex: 1, sortable: true },
  { field: 'project.name', headerName: 'Para', flex: 1, valueFormatter: (params) => params.value || 'Todos os clientes' },
  { field: 'created_at', headerName: 'Publicado em', type: 'datetime', width: 200, sortable: true },
])

const fetchRows = async () => {
  try {
    const params = new URLSearchParams({ page: currentPage.value, per_page: pageSize.value, search: searchQuery.value })
    const response = await apiFetch(`/announcements?${params}`).then((res) => res.json())
    rows.value = response.data
    totalRows.value = response.total
    selected.value = []
  } catch (err) {
    console.error('Erro ao buscar avisos:', err)
  }
}

const debounceSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    currentPage.value = 1
    fetchRows()
  }, 400)
}

const handlePageChange = (page) => {
  currentPage.value = page
  fetchRows()
}

const handlePageSizeChange = (size) => {
  pageSize.value = size
  currentPage.value = 1
  fetchRows()
}

const handleRowDoubleClick = (row) => {
  if (auth.can('announcements.update')) router.push(`/announcement/form/${row.id}`)
}

const editSelected = () => router.push(`/announcement/form/${selected.value[0].id}`)

const deleteSelected = async () => {
  const targets = [...selected.value]
  const ok = await swal.confirmDelete({ count: targets.length, entity: 'aviso', entityPlural: 'avisos' })
  if (!ok) return

  try {
    for (const announcement of targets) {
      const res = await apiFetch(`/announcements/${announcement.id}`, { method: 'DELETE' })
      if (!res.ok) throw new Error((await res.json()).message)
    }
    swal.toastSuccess(targets.length > 1 ? 'Avisos excluídos com sucesso!' : 'Aviso excluído com sucesso!')
  } catch (err) {
    swal.toastError(err.message || 'Falha ao excluir aviso(s).')
  } finally {
    await fetchRows()
  }
}

onMounted(fetchRows)
</script>
