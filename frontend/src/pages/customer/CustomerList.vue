<template>
  <div class="page fade-in">
    <PageHeader
      title="Clientes"
      subtitle="Leads, clientes em aquecimento, ativos e cancelados."
      icon="fa-solid fa-address-book"
    />

    <div class="list-toolbar">
      <div class="list-toolbar__filters">
        <Input
          class="search-input"
          type="search"
          v-model="searchQuery"
          @input="debounceSearch"
          placeholder="Buscar por nome, e-mail ou telefone..."
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
        <Button
          v-can="'customers.create'"
          variant="create"
          icon="fa-solid fa-plus"
          label="Incluir"
          @click="$router.push('/customer/form')"
        />
        <Button
          v-can="'customers.update'"
          variant="edit"
          icon="fa-solid fa-pen-to-square"
          label="Alterar"
          :disabled="selectedCustomers.length !== 1"
          @click="editSelected"
        />
        <Button
          variant="secondary"
          icon="fa-solid fa-clock-rotate-left"
          label="Histórico"
          :disabled="selectedCustomers.length !== 1"
          @click="showHistory = true"
        />
        <Button
          v-can="'customers.delete'"
          variant="danger"
          icon="fa-solid fa-trash-can"
          :label="selectedCustomers.length > 0 ? `Excluir (${selectedCustomers.length})` : 'Excluir'"
          :disabled="selectedCustomers.length === 0"
          @click="deleteSelectedCustomers"
        />
      </div>
    </div>

    <div class="grid-wrap">
      <AgGrid
        :rowData="customersData"
        :columnDefs="columnDefs"
        :currentPage="currentPage"
        :pageSize="pageSize"
        :totalRows="totalRows"
        :selectable="true"
        @update:page="handlePageChange"
        @update:pageSize="handlePageSizeChange"
        @update:selection="handleSelectionChange"
        @row-dblclick="handleRowDoubleClick"
      />
    </div>

    <ActivityLogModal
      v-if="selectedCustomers.length === 1"
      v-model="showHistory"
      subject-type="customer"
      :subject-id="selectedCustomers[0].id"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AgGrid from '@/components/utils/AgGrid.vue'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import { MASKS } from '@/utils/masks'
import PageHeader from '@/components/ui/PageHeader.vue'
import ActivityLogModal from '@/components/ui/ActivityLogModal.vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const customersData = ref([])
const totalRows = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)
const searchQuery = ref('')
const statusFilter = ref('')
const statusOptions = ref([])
const selectedCustomers = ref([])
const showHistory = ref(false)
let searchTimeout = null

const columnDefs = ref([
  {
    field: 'id',
    headerName: 'ID',
    width: 80,
    sortable: true,
    filter: 'agNumberColumnFilter',
    cellClass: 'cell-center',
  },
  { field: 'name', headerName: 'Nome', flex: 1, sortable: true, filter: true },
  { field: 'email', headerName: 'E-mail', flex: 1, sortable: true, filter: true },
  {
    field: 'phone',
    headerName: 'Telefone',
    width: 160,
    valueFormatter: (params) => MASKS.phone.format(params.value),
  },
  {
    field: 'status',
    headerName: 'Status',
    type: 'badge',
    width: 170,
    sortable: true,
    cellRendererParams: { options: () => statusOptions.value },
  },
  { field: 'city', headerName: 'Cidade', width: 160, sortable: true },
  { field: 'active', headerName: 'Ativo', type: 'boolean', width: 110, sortable: true, cellRendererParams: { trueLabel: 'Ativo', falseLabel: 'Inativo' } },
  {
    field: 'created_at',
    headerName: 'Data de Cadastro',
    type: 'datetime',
    width: 200,
    sortable: true,
  },
])

const fetchCustomers = async () => {
  try {
    const params = new URLSearchParams({
      page: currentPage.value,
      per_page: pageSize.value,
      search: searchQuery.value,
      status: statusFilter.value,
    })
    const url = `/customers?${params}`
    const response = await apiFetch(url).then((res) => res.json())
    customersData.value = response.data
    totalRows.value = response.total
    selectedCustomers.value = []
  } catch (err) {
    console.error('Erro ao buscar lista de clientes:', err)
  }
}

const fetchStatuses = async () => {
  const response = await apiFetch('/customers/statuses')
  if (response.ok) statusOptions.value = await response.json()
}

const applyFilter = () => {
  currentPage.value = 1
  fetchCustomers()
}

const debounceSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    currentPage.value = 1
    fetchCustomers()
  }, 400)
}

const handlePageChange = (page) => {
  currentPage.value = page
  fetchCustomers()
}

const handlePageSizeChange = (size) => {
  pageSize.value = size
  currentPage.value = 1
  fetchCustomers()
}

const handleSelectionChange = (selection) => {
  selectedCustomers.value = selection
}

const handleRowDoubleClick = (rowData) => {
  if (!auth.can('customers.update')) return
  router.push(`/customer/form/${rowData.id}`)
}

const editSelected = () => {
  if (selectedCustomers.value.length !== 1) return
  router.push(`/customer/form/${selectedCustomers.value[0].id}`)
}

const deleteSelectedCustomers = async () => {
  if (selectedCustomers.value.length === 0) return

  const targets = [...selectedCustomers.value]
  const ok = await swal.confirmDelete({
    count: targets.length,
    entity: 'cliente',
    entityPlural: 'clientes',
  })
  if (!ok) return

  try {
    for (const customer of targets) {
      const res = await apiFetch(`/customers/${customer.id}`, { method: 'DELETE' })
      if (!res.ok) throw new Error((await res.json()).message)
    }
    await fetchCustomers()
    swal.toastSuccess(
      targets.length > 1 ? 'Clientes excluídos com sucesso!' : 'Cliente excluído com sucesso!',
    )
  } catch (err) {
    console.error('Falha ao excluir clientes:', err)
    await fetchCustomers()
    swal.toastError(err.message || 'Falha ao excluir cliente(s).')
  }
}

// Statuses first: the grid's status badges need their colors/icons.
onMounted(async () => {
  await fetchStatuses()
  await fetchCustomers()
})
</script>

