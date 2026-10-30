<template>
  <div class="page fade-in">
    <PageHeader
      :title="importData ? importData.filename : 'Importação'"
      :subtitle="subtitle"
      icon="fa-solid fa-file-import"
    >
      <Button variant="secondary" icon="fa-solid fa-arrow-left" label="Voltar" @click="router.push(config.path)" />
    </PageHeader>

    <div v-if="!importData" class="form-card loading-state">
      <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
      <span>Carregando importação...</span>
    </div>

    <template v-else>
      <section class="summary" aria-label="Resumo da importação">
        <div class="stat"><span>Linhas</span><strong>{{ importData.total }}</strong></div>
        <div class="stat stat--success"><span>Criados</span><strong>{{ importData.created_count }}</strong></div>
        <div class="stat stat--info"><span>Atualizados</span><strong>{{ importData.updated_count }}</strong></div>
        <div class="stat" :class="{ 'stat--danger': importData.failed_count > 0 }"><span>Com erro</span><strong>{{ importData.failed_count }}</strong></div>
      </section>

      <div v-if="processing" class="form-card progress-card" role="status">
        <div class="progress-card__text">
          <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
          Importando em segundo plano: <strong>{{ done }}</strong> de <strong>{{ importData.total }}</strong> linhas processadas.
          Pode sair desta tela, a importação continua.
        </div>
        <div class="progress" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100">
          <div class="progress__bar" :style="{ width: percent + '%' }" />
        </div>
      </div>

      <section v-if="importedTotal > 0" class="errors imported">
        <div class="errors__head">
          <div>
            <h3 class="form-section"><i class="fa-solid fa-circle-check" aria-hidden="true" /> Registros importados</h3>
            <p class="field-hint">Dados da planilha que viraram registros novos ou atualizaram registros existentes.</p>
          </div>
        </div>

        <div class="grid-wrap">
          <AgGrid
            :rowData="importedRows"
            :columnDefs="importedColumns"
            :currentPage="importedPage"
            :pageSize="importedPageSize"
            :totalRows="importedTotal"
            :selectable="false"
            :exportable="false"
            @update:page="(p) => { importedPage = p; fetchImported() }"
            @update:pageSize="(s) => { importedPageSize = s; importedPage = 1; fetchImported() }"
          />
        </div>
      </section>

      <section v-if="importData.failed_count > 0" class="errors">
        <div class="errors__head">
          <div>
            <h3 class="form-section">Registros que não foram importados</h3>
            <p class="field-hint">
              Corrija a planilha e envie de novo: o CSV baixado já traz a coluna Erro, que o sistema ignora ao importar.
            </p>
          </div>
          <Button variant="primary" icon="fa-solid fa-file-csv" label="Baixar CSV de erros" @click="downloadErrors" />
        </div>

        <div class="grid-wrap">
          <AgGrid
            :rowData="errorRows"
            :columnDefs="errorColumns"
            :currentPage="errorPage"
            :pageSize="errorPageSize"
            :totalRows="errorTotal"
            :selectable="false"
            :exportable="false"
            @update:page="(p) => { errorPage = p; fetchErrors() }"
            @update:pageSize="(s) => { errorPageSize = s; errorPage = 1; fetchErrors() }"
          />
        </div>
      </section>

      <div v-else-if="!processing" class="form-card empty-ok">
        <i class="fa-solid fa-circle-check" aria-hidden="true" />
        Nenhum erro: todos os registros válidos foram importados.
      </div>
    </template>
  </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import AgGrid from '@/components/utils/AgGrid.vue'
import Button from '@/components/utils/Button.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { apiFetch, downloadFile } from '@/services/api'
import { swal } from '@/plugins/swal'
import { IMPORT_TYPES } from './types'

const props = defineProps({ id: { type: [String, Number], required: true }, type: { type: String, required: true } })
const router = useRouter()
const config = IMPORT_TYPES[props.type]

const importData = ref(null)
const importedRows = ref([])
const importedTotal = ref(0)
const importedPage = ref(1)
const importedPageSize = ref(10)
const errorRows = ref([])
const errorTotal = ref(0)
const errorPage = ref(1)
const errorPageSize = ref(10)
let timer = null

const processing = computed(() => importData.value?.status === 'processing')
const done = computed(() => (importData.value ? importData.value.total - importData.value.pending_count : 0))
const percent = computed(() => (importData.value?.total ? Math.round((done.value / importData.value.total) * 100) : 0))
const subtitle = computed(() =>
  importData.value
    ? `Enviada em ${new Date(importData.value.created_at).toLocaleString('pt-BR')}${importData.value.user ? ` por ${importData.value.user.name}` : ''}`
    : '',
)

const RESULT = [
  { value: 'created', label: 'Criado', color: 'success', icon: 'fa-solid fa-plus' },
  { value: 'updated', label: 'Atualizado', color: 'warning', icon: 'fa-solid fa-rotate' },
]

const previewColumns = config.preview.map(([key, label]) => ({ field: `data.${key}`, headerName: label, flex: 1, minWidth: 160 }))

const importedColumns = [
  { field: 'line', headerName: 'Linha', width: 90, cellClass: 'cell-center' },
  { field: 'status', headerName: 'Resultado', type: 'badge', width: 140, cellRendererParams: { options: RESULT } },
  ...previewColumns,
]

const errorColumns = [
  { field: 'line', headerName: 'Linha', width: 90, cellClass: 'cell-center' },
  ...previewColumns,
  { field: 'error', headerName: 'Motivo do erro', flex: 1, minWidth: 320, tooltip: (p) => p.value },
]

const fetchImport = async () => {
  try {
    const response = await apiFetch(`/imports/${props.id}`)
    if (!response.ok) throw new Error((await response.json()).message)
    importData.value = await response.json()
  } catch (err) {
    swal.toastError(err.message || 'Não foi possível carregar a importação.')
    router.replace(config.path)
  }
}

const fetchImported = async () => {
  const params = new URLSearchParams({ page: importedPage.value, per_page: importedPageSize.value })
  const response = await apiFetch(`/imports/${props.id}/imported?${params}`).then((res) => res.json())
  importedRows.value = response.data
  importedTotal.value = response.total
}

const fetchErrors = async () => {
  const params = new URLSearchParams({ page: errorPage.value, per_page: errorPageSize.value })
  const response = await apiFetch(`/imports/${props.id}/errors?${params}`).then((res) => res.json())
  errorRows.value = response.data
  errorTotal.value = response.total
}

const downloadErrors = () =>
  downloadFile(`/imports/${props.id}/errors/csv`, `erros_importacao_${props.id}.csv`).catch((err) => swal.toastError(err.message))

const refresh = async () => {
  await fetchImport()
  if (importData.value?.created_count + importData.value?.updated_count > 0) await fetchImported()
  if (importData.value?.failed_count > 0) await fetchErrors()
}

onMounted(async () => {
  await refresh()
  timer = setInterval(() => processing.value && refresh(), 2000)
})
onBeforeUnmount(() => clearInterval(timer))
</script>

<style scoped lang="scss">
.summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin-bottom: 16px;
}

.stat {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 12px;
  box-shadow: var(--shadow-sm);

  span {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
  }

  strong {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text);
  }

  &--success strong { color: var(--color-success); }
  &--info strong { color: var(--color-info); }
  &--danger strong { color: var(--color-danger); }
}

.progress-card {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 16px;

  &__text {
    color: var(--color-text);
    line-height: 1.5;
  }
}

.progress {
  height: 8px;
  border-radius: 999px;
  background: var(--color-bg-muted);
  overflow: hidden;

  &__bar {
    height: 100%;
    background: var(--color-primary);
    border-radius: 999px;
    transition: width 0.4s ease;
  }
}

.errors__head {
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;

  .form-section {
    margin-top: 0;
  }
}

.imported {
  .form-section i { color: var(--color-success); margin-right: 4px; }

  // Só um acento discreto: fundo branco como o resto, faixa verde na lateral de cada linha.
  .grid-wrap :deep(.ag-row) {
    border-left-color: color-mix(in srgb, var(--color-success) 85%, #ffffff) !important;
  }
}

.empty-ok {
  display: flex;
  align-items: center;
  gap: 10px;
  color: var(--color-success);
  font-weight: 500;
}
</style>
