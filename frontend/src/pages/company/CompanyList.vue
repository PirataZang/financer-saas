<template>
  <div class="page fade-in">
    <PageHeader
      title="Empresas"
      subtitle="Área restrita: clientes da plataforma, dados cadastrais e licença de acesso."
      icon="fa-solid fa-building"
    />

    <div class="list-toolbar">
      <div class="list-toolbar__filters">
        <Input
          class="search-input"
          type="search"
          v-model="searchQuery"
          @input="debounceSearch"
          placeholder="Buscar por nome, nome fantasia ou CPF/CNPJ..."
        >
          <template #prefix>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
          </template>
        </Input>
      </div>

      <div class="list-toolbar__actions">
        <Button variant="create" icon="fa-solid fa-plus" label="Incluir" @click="$router.push('/restricted/company/form')" />
        <Button
          variant="edit"
          icon="fa-solid fa-pen-to-square"
          label="Alterar"
          :disabled="selected.length !== 1"
          @click="edit(selected[0])"
        />
        <Button
          variant="secondary"
          icon="fa-solid fa-clock-rotate-left"
          label="Histórico"
          :disabled="selected.length !== 1"
          @click="showHistory = true"
        />
        <Button
          variant="danger"
          icon="fa-solid fa-trash-can"
          :label="selected.length > 0 ? `Excluir (${selected.length})` : 'Excluir'"
          :disabled="selected.length === 0 || selected.some((c) => c.id === DEFAULT_COMPANY_ID)"
          :title="selected.some((c) => c.id === DEFAULT_COMPANY_ID) ? 'A empresa padrão não pode ser excluída.' : undefined"
          @click="deleteSelected"
        />
      </div>
    </div>

    <div class="grid-wrap">
      <AgGrid
        :rowData="companies"
        :columnDefs="columnDefs"
        :currentPage="currentPage"
        :pageSize="pageSize"
        :totalRows="totalRows"
        :selectable="true"
        @update:page="handlePageChange"
        @update:pageSize="handlePageSizeChange"
        @update:selection="(rows) => (selected = rows)"
        @row-dblclick="edit"
      />
    </div>

    <ActivityLogModal
      v-if="selected.length === 1"
      v-model="showHistory"
      subject-type="company"
      :subject-id="selected[0].id"
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
import { MASKS } from '@/utils/masks'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { LICENSE_OPTIONS, DEFAULT_COMPANY_ID } from './license'

const router = useRouter()

const companies = ref([])
const totalRows = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)
const searchQuery = ref('')
const selected = ref([])
const showHistory = ref(false)
let searchTimeout = null

const columnDefs = [
  { field: 'id', headerName: 'ID', width: 80, sortable: true, cellClass: 'cell-center' },
  { field: 'name', headerName: 'Razão social / Nome', flex: 1, sortable: true },
  { field: 'trade_name', headerName: 'Nome fantasia', flex: 1, sortable: true },
  {
    field: 'document',
    headerName: 'CPF/CNPJ',
    width: 190,
    valueFormatter: (params) => (params.value ? MASKS['cpf-cnpj'].format(params.value) : '—'),
  },
  {
    field: 'city',
    headerName: 'Cidade/UF',
    width: 180,
    valueGetter: (params) => [params.data.city, params.data.state].filter(Boolean).join('/') || '—',
  },
  { field: 'active', headerName: 'Licença', type: 'badge', width: 140, cellRendererParams: { options: LICENSE_OPTIONS } },
  {
    field: 'expires_at',
    headerName: 'Expira em',
    width: 140,
    sortable: true,
    valueFormatter: (params) => (params.value ? params.value.split('-').reverse().join('/') : 'Sem expiração'),
  },
]

const fetchCompanies = async () => {
  try {
    const params = new URLSearchParams({ page: currentPage.value, per_page: pageSize.value, search: searchQuery.value })
    const response = await apiFetch(`/companies?${params}`).then((res) => res.json())
    companies.value = response.data
    totalRows.value = response.total
    selected.value = []
  } catch (err) {
    console.error('Erro ao buscar empresas:', err)
  }
}

const debounceSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    currentPage.value = 1
    fetchCompanies()
  }, 400)
}

const handlePageChange = (page) => {
  currentPage.value = page
  fetchCompanies()
}

const handlePageSizeChange = (size) => {
  pageSize.value = size
  currentPage.value = 1
  fetchCompanies()
}

const edit = (company) => router.push(`/restricted/company/form/${company.id}`)

const deleteSelected = async () => {
  const targets = [...selected.value]
  // No `count`: it would replace this text with "não pode ser desfeita" (it can — soft delete).
  const ok = await swal.confirmDelete({
    title: targets.length > 1 ? `Excluir ${targets.length} empresas` : 'Excluir empresa',
    text: 'Os dados são mantidos, mas os usuários perdem o acesso imediatamente.',
  })
  if (!ok) return

  try {
    for (const company of targets) {
      const res = await apiFetch(`/companies/${company.id}`, { method: 'DELETE' })
      if (!res.ok) throw new Error((await res.json()).message)
    }
    await fetchCompanies()
    swal.toastSuccess(targets.length > 1 ? 'Empresas excluídas.' : 'Empresa excluída.')
  } catch (err) {
    await fetchCompanies()
    swal.toastError(err.message || 'Falha ao excluir empresa(s).')
  }
}

onMounted(fetchCompanies)
</script>
