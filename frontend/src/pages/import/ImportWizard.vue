<template>
  <div class="page fade-in">
    <PageHeader
      :title="`Nova importação de ${config.subject}`"
      subtitle="Envie a planilha, relacione as colunas e revise os dados antes de importar."
      icon="fa-solid fa-file-arrow-up"
    >
      <Button variant="secondary" icon="fa-solid fa-xmark" label="Cancelar" @click="router.push(config.path)" />
    </PageHeader>

    <ol class="stepper" aria-label="Etapas da importação">
      <li v-for="(label, i) in STEPS" :key="label" :class="{ active: step === i + 1, done: step > i + 1 }" :aria-current="step === i + 1 ? 'step' : undefined">
        <span class="stepper__dot"><i v-if="step > i + 1" class="fa-solid fa-check" aria-hidden="true" /><template v-else>{{ i + 1 }}</template></span>
        {{ label }}
      </li>
    </ol>

    <!-- 1 · Arquivo -->
    <div v-if="step === 1" class="step-file">
      <label
        class="dropzone"
        :class="{ 'is-over': dragging, 'is-busy': uploading }"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
      >
        <input type="file" accept=".xlsx,.csv,.txt" class="sr-only" :disabled="uploading" @change="onPick" />
        <i :class="uploading ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-file-arrow-up'" aria-hidden="true" />
        <strong>{{ uploading ? 'Lendo a planilha...' : 'Arraste a planilha aqui ou clique para escolher' }}</strong>
        <span>Excel (.xlsx) ou CSV · até 5 MB e 5.000 linhas · títulos das colunas na primeira linha</span>
      </label>

      <div v-if="uploadError" class="form-error" role="alert">{{ uploadError }}</div>

      <aside class="help-card">
        <h3>Primeira vez?</h3>
        <p>Baixe o modelo: as colunas dele são reconhecidas automaticamente.</p>
        <Button variant="secondary" icon="fa-solid fa-download" label="Baixar modelo" @click="downloadTemplate" />
        <ul>
          <li v-for="tip in config.help" :key="tip">{{ tip }}</li>
          <li>Reenviar o CSV de erros? A coluna <strong>Erro</strong> é ignorada.</li>
        </ul>
      </aside>
    </div>

    <!-- 2 · Colunas -->
    <div v-else-if="step === 2" class="form-card">
      <div class="map-head">
        <div>
          <h3 class="form-section">Relacione as colunas</h3>
          <p class="field-hint">
            <strong>{{ fileName }}</strong> · {{ rawRows.length }} linhas · {{ headers.length }} colunas.
            Escolha, para cada campo {{ config.fieldsOf }}, de qual coluna da planilha vem a informação.
          </p>
        </div>
        <span class="chip" :class="mappingProblem ? 'chip--warning' : 'chip--success'">
          {{ mappedCount }} de {{ fields.length }} campos relacionados
        </span>
      </div>

      <div class="map-table" role="table" aria-label="Relação entre campos do cliente e colunas da planilha">
        <div class="map-row map-row--head" role="row">
          <span role="columnheader">Campo {{ config.fieldsOf }}</span>
          <span aria-hidden="true" />
          <span role="columnheader">Coluna da planilha</span>
          <span role="columnheader">Exemplos</span>
        </div>
        <div v-for="field in fields" :key="field.key" class="map-row" role="row">
          <span role="cell" class="map-field">
            {{ field.label }}<b v-if="field.required" class="req" title="Obrigatório">*</b>
          </span>
          <i class="fa-solid fa-arrow-left-long map-arrow" aria-hidden="true" />
          <Select
            role="cell"
            v-model="mapping[field.key]"
            :options="headerOptions"
            :aria-label="`Coluna da planilha para ${field.label}`"
            placeholder="Não importar"
            :clearable="!field.required"
            searchable
            small
            @change="(value) => claim(field.key, value)"
          />
          <span role="cell" class="map-sample" :title="field.secret ? '' : sample(field.key)">{{ field.secret && mapping[field.key] !== '' ? '••••••••' : sample(field.key) }}</span>
        </div>
      </div>

      <p v-if="mappingProblem" class="form-error" role="alert">{{ mappingProblem }}</p>
      <p v-else-if="ignoredColumns" class="field-hint">
        {{ ignoredColumns }} coluna(s) da planilha não foram relacionadas e serão ignoradas.
      </p>

      <div class="wizard-actions">
        <Button variant="secondary" icon="fa-solid fa-arrow-left" label="Voltar" @click="step = 1" />
        <Button variant="primary" icon="fa-solid fa-arrow-right" label="Revisar dados" :disabled="!!mappingProblem" @click="review" />
      </div>
    </div>

    <!-- 3 · Revisão -->
    <div v-else class="review">
      <div v-if="validating" class="form-card loading-state">
        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
        <span>Validando {{ rows.length }} registros...</span>
      </div>

      <template v-else>
        <div v-if="counts.update > 0" class="notice notice--warning" role="alert">
          <i class="fa-solid fa-triangle-exclamation" aria-hidden="true" />
          <div>
            <strong>{{ counts.update }} {{ counts.update === 1 ? 'registro já existe' : 'registros já existem' }}</strong>
            (mesmo {{ config.matchBy }}) e <strong>terão os dados substituídos</strong> pelos da importação.
            Campos em branco na planilha não apagam o que já está cadastrado.
            <button type="button" class="link" @click="filter = 'update'">Ver quais</button>
          </div>
        </div>

        <div v-if="counts.error > 0" class="notice notice--danger" role="alert">
          <i class="fa-solid fa-circle-exclamation" aria-hidden="true" />
          <div>
            <strong>{{ counts.error }} {{ counts.error === 1 ? 'linha tem erro' : 'linhas têm erro' }}.</strong>
            Corrija nas células em vermelho; o que continuar com erro não será importado e fica em "Registros que não foram importados".
            <button type="button" class="link" @click="filter = 'error'">Ver com erro</button>
          </div>
        </div>

        <div class="review__bar">
          <Tabs v-model="filter" :tabs="filterTabs" label="Filtrar registros" />
          <Input class="search-input" type="search" v-model="search" placeholder="Buscar nos registros...">
            <template #prefix><i class="fa-solid fa-magnifying-glass" aria-hidden="true" /></template>
          </Input>
        </div>

        <p class="field-hint">Clique em uma célula para editar. Cada alteração é validada na hora.</p>

        <div id="tabpanel-review" class="grid-wrap review__grid" role="tabpanel">
          <AgGrid
            :rowData="pageRows"
            :columnDefs="columnDefs"
            :defaultColDef="defaultColDef"
            :currentPage="page"
            :pageSize="pageSize"
            :totalRows="filtered.length"
            :selectable="false"
            :exportable="false"
            gridHeight="60vh"
            @update:page="(p) => (page = p)"
            @update:pageSize="(s) => { pageSize = s; page = 1 }"
            @cell-edit="onEdit"
          />
        </div>

        <div class="wizard-actions">
          <Button variant="secondary" icon="fa-solid fa-arrow-left" label="Voltar" :disabled="saving" @click="step = 2" />
          <Button
            variant="primary"
            :icon="saving ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-check'"
            :label="importLabel"
            :disabled="saving || counts.new + counts.update === 0"
            @click="confirmImport"
          />
        </div>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, reactive, ref, shallowRef, watch } from 'vue'
import { useRouter } from 'vue-router'
import AgGrid from '@/components/utils/AgGrid.vue'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import Tabs from '@/components/ui/Tabs.vue'
import { apiFetch, downloadFile } from '@/services/api'
import { swal } from '@/plugins/swal'
import { normalize } from '@/permissions'
import { IMPORT_TYPES } from './types'

const STEPS = ['Arquivo', 'Colunas', 'Revisão']
const ROW_STATE = [
  { value: 'new', label: 'Novo', color: 'success', icon: 'fa-solid fa-plus' },
  { value: 'update', label: 'Atualiza existente', color: 'warning', icon: 'fa-solid fa-rotate' },
  { value: 'error', label: 'Com erro', color: 'danger', icon: 'fa-solid fa-circle-exclamation' },
]

const props = defineProps({ type: { type: String, required: true } })
const router = useRouter()
const config = IMPORT_TYPES[props.type]
const step = ref(1)

/* ---------- 1 · arquivo ---------- */
const dragging = ref(false)
const uploading = ref(false)
const uploadError = ref('')
const fileName = ref('')
const headers = ref([])
const rawRows = ref([])
const fields = ref([])
const requires = ref({ all: [], any: [] })
const mapping = reactive({}) // campo => índice da coluna (string) | ''

const downloadTemplate = () =>
  downloadFile(`/imports/${props.type}/template`, config.templateFile).catch((err) => swal.toastError(err.message))

const upload = async (file) => {
  if (!file) return
  uploadError.value = ''
  uploading.value = true
  try {
    const body = new FormData()
    body.append('file', file)
    const response = await apiFetch(`/imports/${props.type}/parse`, { method: 'POST', body })
    const data = await response.json()
    if (!response.ok) throw new Error(data.errors?.file?.[0] || data.message || 'Não foi possível ler o arquivo.')

    fileName.value = file.name
    headers.value = data.headers
    rawRows.value = data.rows
    fields.value = data.fields
    requires.value = data.requires
    for (const field of data.fields) mapping[field.key] = data.mapping[field.key] == null ? '' : String(data.mapping[field.key])
    step.value = 2
  } catch (err) {
    uploadError.value = err.message
  } finally {
    uploading.value = false
  }
}

const onPick = (event) => {
  upload(event.target.files[0])
  event.target.value = ''
}
const onDrop = (event) => {
  dragging.value = false
  upload(event.dataTransfer.files[0])
}

/* ---------- 2 · colunas ---------- */
const headerOptions = computed(() => headers.value.map((label, i) => ({ value: String(i), label })))

// Uma coluna da planilha alimenta um campo só: escolher uma já usada a tira do outro campo.
const claim = (key, value) => {
  if (value === '') return
  for (const other of Object.keys(mapping)) if (other !== key && mapping[other] === value) mapping[other] = ''
}

const sample = (key) => {
  if (mapping[key] === '') return ''
  return rawRows.value.map((r) => r[mapping[key]]).filter(Boolean).slice(0, 3).join(' · ')
}

const mappedCount = computed(() => Object.values(mapping).filter((v) => v !== '').length)
const ignoredColumns = computed(() => headers.value.length - mappedCount.value)
const labelOf = (key) => fields.value.find((f) => f.key === key)?.label
const mappingProblem = computed(() => {
  const missing = requires.value.all.find((key) => mapping[key] === '')
  if (missing) return `Relacione o campo ${labelOf(missing)}: ele é obrigatório.`
  const { any } = requires.value
  if (any.length && any.every((key) => mapping[key] === '')) return `Relacione ${any.map(labelOf).join(' ou ')}: cada registro precisa de ao menos um deles.`
  return ''
})

/* ---------- 3 · revisão ---------- */
const rows = shallowRef([]) // { _line, _errors, _existing, <campo>: texto }
const validating = ref(false)
const saving = ref(false)
const filter = ref('all')
const search = ref('')
const page = ref(1)
const pageSize = ref(25)

const stateOf = (row) => (Object.keys(row._errors).length ? 'error' : row._existing ? 'update' : 'new')
const counts = computed(() => {
  const c = { all: rows.value.length, new: 0, update: 0, error: 0 }
  for (const row of rows.value) c[stateOf(row)]++
  return c
})
const filterTabs = computed(() => [
  { value: 'all', label: 'Todos', count: counts.value.all },
  { value: 'error', label: 'Com erro', count: counts.value.error },
  { value: 'update', label: 'Atualizam existentes', count: counts.value.update },
  { value: 'new', label: 'Novos', count: counts.value.new },
])

const filtered = computed(() => {
  const words = normalize(search.value).split(/\s+/).filter(Boolean)
  return rows.value.filter(
    (row) =>
      (filter.value === 'all' || stateOf(row) === filter.value) &&
      words.every((w) => normalize(fields.value.map((f) => row[f.key]).join(' ')).includes(w)),
  )
})
const pageRows = computed(() => filtered.value.slice((page.value - 1) * pageSize.value, page.value * pageSize.value))
watch([filter, search], () => (page.value = 1))

const importLabel = computed(() => {
  const n = counts.value.new + counts.value.update
  return `Importar ${n} ${n === 1 ? 'registro' : 'registros'}`
})

const defaultColDef = { editable: true, sortable: false, filter: false, resizable: true }

const columnDefs = computed(() => [
  { headerName: 'Situação', field: '_state', valueGetter: (p) => stateOf(p.data), type: 'badge', cellRendererParams: { options: ROW_STATE }, editable: false, width: 190, pinned: 'left' },
  { field: '_line', headerName: 'Linha', editable: false, width: 90, cellClass: 'cell-center' },
  {
    headerName: 'Cadastro atual',
    editable: false,
    width: 180,
    valueGetter: (p) => p.data._existing?.name || '',
  },
  ...fields.value
    .filter((f) => mapping[f.key] !== '')
    .map((f) => ({
      field: f.key,
      headerName: f.label,
      minWidth: 150,
      flex: f.key === 'name' || f.key === 'email' ? 1 : undefined,
      valueFormatter: f.secret ? (p) => (p.value ? '••••••••' : '') : undefined,
      cellClassRules: { 'cell-error': (p) => !!p.data._errors?.[f.key] },
      tooltip: (p) => p.data._errors?.[f.key],
    })),
])

const validate = async (list) => {
  const response = await apiFetch(`/imports/${props.type}/validate`, {
    method: 'POST',
    body: JSON.stringify({ rows: list.map((row) => Object.fromEntries(fields.value.map((f) => [f.key, row[f.key] ?? '']))) }),
  })
  const data = await response.json()
  if (!response.ok) throw new Error(data.message || 'Não foi possível validar os dados.')
  list.forEach((row, i) => {
    row._errors = data[i].errors
    row._existing = data[i].existing
  })
}

const review = async () => {
  rows.value = rawRows.value.map((values, i) => ({
    _line: i + 2, // linha da planilha (1 = títulos)
    _errors: {},
    _existing: null,
    ...Object.fromEntries(fields.value.map((f) => [f.key, mapping[f.key] === '' ? '' : values[mapping[f.key]] ?? ''])),
  }))
  filter.value = 'all'
  search.value = ''
  page.value = 1
  step.value = 3
  validating.value = true
  try {
    await validate(rows.value)
    rows.value = [...rows.value]
  } catch (err) {
    swal.toastError(err.message)
    step.value = 2
  } finally {
    validating.value = false
  }
}

// Edição de célula: revalida (com debounce) só as linhas alteradas.
const dirty = new Set()
let timer = null
const onEdit = (event) => {
  const row = rows.value.find((r) => r._line === event.data._line)
  if (!row) return
  row[event.colDef.field] = event.newValue ?? ''
  dirty.add(row)
  clearTimeout(timer)
  timer = setTimeout(async () => {
    const batch = [...dirty]
    dirty.clear()
    try {
      await validate(batch)
      rows.value = [...rows.value]
    } catch (err) {
      swal.toastError(err.message)
    }
  }, 400)
}

const confirmImport = async () => {
  const { new: created, update, error } = counts.value
  const ok = await swal.confirm({
    title: 'Confirmar importação',
    text: '',
    icon: update > 0 ? 'warning' : 'question',
    confirmButtonText: 'Importar',
    html:
      `Serão criados <strong>${created}</strong> e atualizados <strong>${update}</strong> ${config.subject}.` +
      (update > 0 ? `<br><br><strong>Os ${update} ${config.subject} que já existem terão os dados substituídos</strong> pelos da planilha.` : '') +
      (error > 0 ? `<br><br>${error} ${error === 1 ? 'linha com erro não será importada' : 'linhas com erro não serão importadas'}.` : ''),
  })
  if (!ok) return

  saving.value = true
  try {
    const response = await apiFetch(`/imports/${props.type}`, {
      method: 'POST',
      body: JSON.stringify({
        filename: fileName.value,
        rows: rows.value.map((row) => ({ line: row._line, data: Object.fromEntries(fields.value.map((f) => [f.key, row[f.key] ?? ''])) })),
      }),
    })
    const data = await response.json()
    if (!response.ok) throw new Error(data.message || 'Não foi possível iniciar a importação.')
    swal.toastSuccess('Importação iniciada.')
    router.push(`${config.path}/${data.id}`)
  } catch (err) {
    swal.toastError(err.message)
    saving.value = false
  }
}
</script>

<style scoped lang="scss">
.stepper {
  display: flex;
  gap: 8px;
  list-style: none;
  margin: 0 0 20px;
  padding: 0;
  flex-wrap: wrap;

  li {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 16px 8px 8px;
    border-radius: 999px;
    background: #ffffff;
    border: 1px solid var(--color-border);
    color: var(--color-text-muted);
    font-weight: 500;
    font-size: 0.875rem;
  }

  &__dot {
    display: inline-grid;
    place-items: center;
    width: 26px;
    height: 26px;
    border-radius: 999px;
    background: var(--color-bg-muted);
    font-size: 0.8125rem;
    font-weight: 600;
  }

  li.active {
    color: var(--color-text);
    border-color: var(--color-primary);
    .stepper__dot { background: var(--color-primary); color: #ffffff; }
  }

  li.done {
    color: var(--color-text-secondary);
    .stepper__dot { background: var(--color-success-soft); color: var(--color-success); }
  }
}

.step-file {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(0, 1fr);
  gap: 16px;
  align-items: start;

  .form-error { grid-column: 1; }
}

.dropzone {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  min-height: 280px;
  padding: 24px;
  text-align: center;
  background: #ffffff;
  border: 2px dashed var(--color-border-strong);
  border-radius: 16px;
  cursor: pointer;
  transition: border-color 0.2s, background-color 0.2s;

  i { font-size: 2.25rem; color: var(--color-primary); }
  strong { color: var(--color-text); font-size: 1.0625rem; }
  span { color: var(--color-text-secondary); font-size: 0.875rem; }

  &:hover,
  &:focus-within,
  &.is-over { border-color: var(--color-primary); background: var(--color-primary-soft); }
  &.is-busy { cursor: progress; opacity: 0.8; }
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0 0 0 0);
}

.help-card {
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  box-shadow: var(--shadow-sm);
  padding: 20px;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 12px;

  h3 { margin: 0; font-size: 1rem; color: var(--color-text); }
  p { margin: 0; color: var(--color-text-secondary); font-size: 0.875rem; }
  ul { margin: 4px 0 0; padding-left: 18px; color: var(--color-text-secondary); font-size: 0.8125rem; line-height: 1.5; display: flex; flex-direction: column; gap: 6px; }
}

.map-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;

  .form-section { margin-top: 0; }
}

.chip {
  padding: 6px 12px;
  border-radius: 999px;
  font-size: 0.8125rem;
  font-weight: 600;
  white-space: nowrap;

  &--success { background: var(--color-success-soft); color: var(--color-success); }
  &--warning { background: var(--color-warning-soft); color: var(--color-warning); }
}

.map-table {
  margin-top: 16px;
  border: 1px solid var(--color-border);
  border-radius: 12px;
}

.map-row {
  display: grid;
  // Colunas de largura contida: em tela larga o seletor não estica e os exemplos ficam perto dele.
  grid-template-columns: minmax(130px, 200px) 24px minmax(180px, 300px) minmax(0, 1fr);
  align-items: center;
  gap: 12px;
  padding: 10px 16px;
  border-top: 1px solid var(--color-border);

  &:first-child { border-top: 0; }

  &--head {
    background: var(--color-bg-hover);
    border-radius: 12px 12px 0 0;
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--color-text-secondary);
  }
}

.map-field { font-weight: 500; color: var(--color-text); }
.req { color: var(--color-danger); margin-left: 2px; }
.map-arrow { color: var(--color-text-faint); text-align: center; }
.map-sample { color: var(--color-text-secondary); font-size: 0.8125rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.wizard-actions {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  margin-top: 20px;
}

.review {
  display: flex;
  flex-direction: column;
  gap: 12px;

  .wizard-actions { margin-top: 4px; }
  &__bar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
  .search-input { width: 100%; max-width: 320px; }
}

.notice {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 14px 16px;
  border-radius: 12px;
  line-height: 1.5;
  font-size: 0.9375rem;
  color: var(--color-text);

  > i { margin-top: 3px; }

  &--warning { background: var(--color-warning-soft); border: 1px solid color-mix(in srgb, var(--color-warning) 35%, transparent); > i { color: var(--color-warning); } }
  &--danger { background: var(--color-danger-soft); border: 1px solid color-mix(in srgb, var(--color-danger) 30%, transparent); > i { color: var(--color-danger); } }
}

.link {
  background: none;
  border: 0;
  padding: 0;
  margin-left: 4px;
  color: var(--color-primary);
  font: inherit;
  font-weight: 600;
  text-decoration: underline;
  cursor: pointer;
}

.review__grid :deep(.cell-error) {
  background: var(--color-danger-soft);
  color: var(--color-danger);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--color-danger) 45%, transparent);
}

.review__grid :deep(.ag-cell-inline-editing) {
  box-shadow: 0 0 0 2px var(--color-primary);
}

@media (max-width: 860px) {
  .step-file { grid-template-columns: 1fr; }

  .map-row {
    grid-template-columns: 1fr;
    gap: 6px;
  }
  .map-arrow, .map-row--head { display: none; }
}
</style>
