<template>
  <div class="page fade-in">
    <PageHeader
      title="Gerenciamento de Usuários"
      subtitle="Lista de administradores e usuários do sistema."
      icon="fa-solid fa-users"
    />

    <div class="list-toolbar">
      <Input
        class="search-input"
        type="search"
        v-model="searchQuery"
        @input="debounceSearch"
        placeholder="Buscar usuários por nome ou email..."
      >
        <template #prefix>
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
        </template>
      </Input>

      <div class="list-toolbar__actions">
        <Button
          v-can="'users.create'"
          variant="create"
          icon="fa-solid fa-plus"
          label="Incluir"
          @click="$router.push('/user/form')"
        />
        <Button
          v-can="'users.update'"
          variant="edit"
          icon="fa-solid fa-pen-to-square"
          label="Alterar"
          :disabled="selectedUsers.length !== 1"
          @click="editSelected"
        />
        <Button
          variant="secondary"
          icon="fa-solid fa-clock-rotate-left"
          label="Histórico"
          :disabled="selectedUsers.length !== 1"
          @click="showHistory = true"
        />
        <Button
          v-can="'users.delete'"
          variant="danger"
          icon="fa-solid fa-trash-can"
          :label="selectedUsers.length > 0 ? `Excluir (${selectedUsers.length})` : 'Excluir'"
          :disabled="selectedUsers.length === 0"
          @click="deleteSelectedUsers"
        />
      </div>
    </div>

    <div class="grid-wrap">
      <AgGrid
        :rowData="usersData"
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
      v-if="selectedUsers.length === 1"
      v-model="showHistory"
      subject-type="user"
      :subject-id="selectedUsers[0].id"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AgGrid from '@/components/utils/AgGrid.vue'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import ActivityLogModal from '@/components/ui/ActivityLogModal.vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const auth = useAuthStore()

const usersData = ref([])
const totalRows = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)
const searchQuery = ref('')
const selectedUsers = ref([])
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
  { field: 'name', headerName: 'Nome Completo', flex: 1, sortable: true, filter: true },
  { field: 'email', headerName: 'E-mail', flex: 1, sortable: true, filter: true },
  { field: 'active', headerName: 'Situação', type: 'boolean', width: 130, sortable: true, cellRendererParams: { trueLabel: 'Ativo', falseLabel: 'Inativo' } },
  {
    field: 'created_at',
    headerName: 'Data de Cadastro',
    type: 'datetime',
    width: 200,
    sortable: true,
  },
])

const fetchUsers = async () => {
  try {
    const url = `/users?page=${currentPage.value}&per_page=${pageSize.value}&search=${searchQuery.value}`
    const response = await apiFetch(url).then((res) => res.json())
    usersData.value = response.data
    totalRows.value = response.total
    selectedUsers.value = []
  } catch (err) {
    console.error('Erro ao buscar lista de usuários:', err)
  }
}

const debounceSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    currentPage.value = 1
    fetchUsers()
  }, 400)
}

const handlePageChange = (page) => {
  currentPage.value = page
  fetchUsers()
}

const handlePageSizeChange = (size) => {
  pageSize.value = size
  currentPage.value = 1
  fetchUsers()
}

const handleSelectionChange = (selection) => {
  selectedUsers.value = selection
}

const handleRowDoubleClick = (rowData) => {
  if (!auth.can('users.update')) return
  router.push(`/user/form/${rowData.id}`)
}

const editSelected = () => {
  if (selectedUsers.value.length !== 1) return
  router.push(`/user/form/${selectedUsers.value[0].id}`)
}

const deleteSelectedUsers = async () => {
  if (selectedUsers.value.length === 0) return

  const targets = [...selectedUsers.value]
  const ok = await swal.confirmDelete({
    count: targets.length,
    entity: 'usuário',
    entityPlural: 'usuários',
  })
  if (!ok) return

  try {
    for (const user of targets) {
      const res = await apiFetch(`/users/${user.id}`, { method: 'DELETE' })
      if (!res.ok) throw new Error((await res.json()).message)
    }
    await fetchUsers()
    swal.toastSuccess(
      targets.length > 1 ? 'Usuários excluídos com sucesso!' : 'Usuário excluído com sucesso!',
    )
  } catch (err) {
    console.error('Falha ao excluir usuários:', err)
    await fetchUsers()
    swal.toastError(err.message || 'Falha ao excluir usuário(s).')
  }
}

onMounted(fetchUsers)
</script>

