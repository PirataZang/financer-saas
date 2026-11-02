<template>
  <div class="page fade-in">
    <PageHeader
      :title="config.title"
      :subtitle="config.subtitle"
      icon="fa-solid fa-file-import"
    />

    <div class="list-toolbar">
      <div class="list-toolbar__actions">
        <Button v-can="'imports.create'" variant="create" icon="fa-solid fa-file-arrow-up" label="Nova importação" @click="router.push(`${config.path}/new`)" />
        <Button variant="secondary" icon="fa-solid fa-download" label="Baixar modelo" @click="downloadTemplate" />
        <Button variant="primary" icon="fa-solid fa-magnifying-glass" label="Detalhes" :disabled="selected.length !== 1" @click="open(selected[0])" />
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
        :exportable="false"
        @update:page="handlePageChange"
        @update:pageSize="handlePageSizeChange"
        @update:selection="(selection) => (selected = selection)"
        @row-dblclick="open"
      />
    </div>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import AgGrid from '@/components/utils/AgGrid.vue'
import Button from '@/components/utils/Button.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { apiFetch, downloadFile } from '@/services/api'
import { swal } from '@/plugins/swal'
import { IMPORT_STATUS } from './status'
import { IMPORT_TYPES } from './types'

const props = defineProps({ type: { type: String, required: true } })
const router = useRouter()
// Clientes ↔ Usuários no menu reaproveita esta mesma instância: o tipo precisa ser reativo.
const config = computed(() => IMPORT_TYPES[props.type])

const rows = ref([])
const totalRows = ref(0)
const currentPage = ref(1)
const pageSize = ref(10)
const selected = ref([])
let timer = null

const columnDefs = [
  { field: 'filename', headerName: 'Arquivo', flex: 1 },
  { field: 'created_at', headerName: 'Enviada em', type: 'datetime', width: 190 },
  { field: 'user.name', headerName: 'Por', width: 160 },
  { field: 'total', headerName: 'Linhas', width: 100, cellClass: 'cell-center' },
  { field: 'created_count', headerName: 'Criados', width: 110, cellClass: 'cell-center' },
  { field: 'updated_count', headerName: 'Atualizados', width: 130, cellClass: 'cell-center' },
  { field: 'failed_count', headerName: 'Com erro', width: 120, cellClass: 'cell-center' },
  { field: 'status', headerName: 'Situação', type: 'badge', width: 150, cellRendererParams: { options: IMPORT_STATUS } },
]

const fetchRows = async () => {
  try {
    const params = new URLSearchParams({ type: props.type, page: currentPage.value, per_page: pageSize.value })
    const response = await apiFetch(`/imports?${params}`).then((res) => res.json())
    rows.value = response.data
    totalRows.value = response.total
  } catch (err) {
    console.error('Erro ao buscar importações:', err)
  }
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

const open = (row) => row && router.push(`${config.value.path}/${row.id}`)

const downloadTemplate = () =>
  downloadFile(`/imports/${props.type}/template`, config.value.templateFile).catch((err) => swal.toastError(err.message))

watch(() => props.type, () => {
  rows.value = []
  currentPage.value = 1
  selected.value = []
  fetchRows()
})

// Importações em andamento: atualiza a lista até terminarem.
onMounted(() => {
  fetchRows()
  timer = setInterval(() => rows.value.some((r) => r.status === 'processing') && fetchRows(), 3000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>
