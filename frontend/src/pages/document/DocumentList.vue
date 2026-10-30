<template>
  <div class="page fade-in">
    <PageHeader
      title="Documentos"
      subtitle="Templates com variáveis ({{Cliente.name}}) que viram PDF já preenchido ao emitir."
      icon="fa-solid fa-file-lines"
    />

    <div class="list-toolbar">
      <div class="list-toolbar__filters">
        <Input class="search-input" type="search" v-model="searchQuery" @input="debounceSearch" placeholder="Buscar documentos por nome...">
          <template #prefix>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
          </template>
        </Input>
      </div>

      <div class="list-toolbar__actions">
        <Button v-can="'documents.create'" variant="create" icon="fa-solid fa-plus" label="Novo Documento" @click="$router.push('/document/form')" />
        <Button
          v-can="'documents.update'"
          variant="edit"
          icon="fa-solid fa-pen-to-square"
          label="Alterar"
          :disabled="selected.length !== 1"
          @click="editSelected"
        />
        <Button
          v-can="'documents.delete'"
          variant="danger"
          icon="fa-solid fa-trash-can"
          :label="selected.length > 0 ? `Excluir (${selected.length})` : 'Excluir'"
          :disabled="selected.length === 0"
          @click="deleteSelected"
        />
        <Button v-can="'documents.emit'" variant="primary" icon="fa-solid fa-file-export" label="Emitir" @click="openEmitModal" />
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

    <Modal v-model="emit.open" title="Emitir Documento" size="md">
      <form id="emit-form" class="emit-form" @submit.prevent="handleEmit">
        <Select v-model="emit.documentId" label="Documento" :options="activeDocuments" placeholder="Selecione um documento ativo..." searchable />
        <CustomerSelect v-model="emit.customerId" label="Cliente" placeholder="Nenhum" clearable />
        <Select v-model="emit.projectId" label="Projeto" :options="projectOptions" placeholder="Nenhum" searchable clearable />
        <Input v-model="emit.value" label="Valor" type="number" step="0.01" min="0" placeholder="0,00">
          <template #prefix>R$</template>
        </Input>
        <p class="field-hint">Só as variáveis usadas no documento precisam de dados: o PDF avisa o que faltar.</p>
        <div v-if="emit.error" class="form-error">{{ emit.error }}</div>
      </form>

      <template #footer>
        <Button variant="secondary" label="Cancelar" @click="emit.open = false" />
        <Button native-type="submit" form="emit-form" variant="primary" :icon="emit.busy ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-file-export'" label="Emitir PDF" :disabled="emit.busy" />
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import AgGrid from '@/components/utils/AgGrid.vue'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import Modal from '@/components/utils/Modal.vue'
import Select from '@/components/utils/Select.vue'
import CustomerSelect from '@/components/customer/CustomerSelect.vue'
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

const activeDocuments = ref([])
const projectOptions = ref([])
const emit = reactive({ open: false, documentId: '', customerId: '', projectId: '', value: '', busy: false, error: '' })

const columnDefs = ref([
  { field: 'name', headerName: 'Nome', flex: 1, sortable: true },
  { field: 'subject', headerName: 'Assunto', flex: 1, sortable: true },
  { field: 'description', headerName: 'Descrição breve', flex: 1, sortable: true },
  { field: 'active', headerName: 'Situação', type: 'boolean', width: 130, sortable: true },
  { field: 'updated_at', headerName: 'Última alteração', type: 'datetime', width: 200, sortable: true },
])

const fetchRows = async () => {
  try {
    const params = new URLSearchParams({ page: currentPage.value, per_page: pageSize.value, search: searchQuery.value })
    const response = await apiFetch(`/documents?${params}`).then((res) => res.json())
    rows.value = response.data
    totalRows.value = response.total
    selected.value = []
  } catch (err) {
    console.error('Erro ao buscar documentos:', err)
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
  if (auth.can('documents.update')) router.push(`/document/form/${row.id}`)
}

const editSelected = () => router.push(`/document/form/${selected.value[0].id}`)

const deleteSelected = async () => {
  const targets = [...selected.value]
  const ok = await swal.confirmDelete({ count: targets.length, entity: 'documento', entityPlural: 'documentos' })
  if (!ok) return

  try {
    for (const document of targets) {
      const res = await apiFetch(`/documents/${document.id}`, { method: 'DELETE' })
      if (!res.ok) throw new Error((await res.json()).message)
    }
    swal.toastSuccess(targets.length > 1 ? 'Documentos excluídos com sucesso!' : 'Documento excluído com sucesso!')
  } catch (err) {
    swal.toastError(err.message || 'Falha ao excluir documento(s).')
  } finally {
    await fetchRows()
  }
}

// Options are loaded when the modal opens (the list may have changed since the page loaded).
const openEmitModal = async () => {
  const single = selected.value.length === 1 ? selected.value[0] : null
  Object.assign(emit, { open: true, documentId: single?.active ? single.id : '', customerId: '', projectId: '', value: '', busy: false, error: '' })

  const [documents, projects] = await Promise.all([apiFetch('/documents?active=1&per_page=200'), apiFetch('/projects/options')])
  if (documents.ok) activeDocuments.value = ((await documents.json()).data || []).map((d) => ({ value: d.id, label: d.name }))
  if (projects.ok) projectOptions.value = await projects.json()
}

// The PDF needs the Authorization header, so it is fetched and saved through a temporary link.
const handleEmit = async () => {
  if (!emit.documentId) {
    emit.error = 'Selecione um documento para emitir.'
    return
  }
  emit.error = ''
  emit.busy = true
  try {
    const params = new URLSearchParams()
    if (emit.customerId) params.set('customer_id', emit.customerId)
    if (emit.projectId) params.set('project_id', emit.projectId)
    if (emit.value) params.set('value', emit.value)

    const response = await apiFetch(`/documents/${emit.documentId}/emit?${params}`)
    if (!response.ok) {
      const body = await response.json().catch(() => ({}))
      throw new Error(Object.values(body.errors || {})[0]?.[0] || body.message || 'Não foi possível gerar o PDF deste documento.')
    }

    const filename = (response.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)"?/)?.[1] || 'documento.pdf'
    const url = URL.createObjectURL(await response.blob())
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.click()
    URL.revokeObjectURL(url)

    emit.open = false
    swal.toastSuccess('PDF gerado!')
  } catch (err) {
    emit.error = err.message
  } finally {
    emit.busy = false
  }
}

onMounted(fetchRows)
</script>

<style scoped>
.emit-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}
</style>
