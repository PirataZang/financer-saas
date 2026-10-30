<template>
  <div class="page fade-in editor-page">
    <PageHeader
      :title="isEdit ? 'Editar Documento' : 'Novo Documento'"
      :subtitle="isEdit ? 'Atualize o conteúdo deste documento.' : 'Crie um documento para reaproveitar depois.'"
      :icon="isEdit ? 'fa-solid fa-file-pen' : 'fa-solid fa-file-circle-plus'"
    />

    <div v-if="loading" class="loading-state">
      <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
      <span>Carregando documento...</span>
    </div>

    <template v-else>
      <div class="doc-card">
        <div class="form-grid">
          <Input
            class="col-12 doc-name"
            label="Título do documento"
            v-model="form.name"
            placeholder="Documento sem título"
          />
          <Input class="col-5" label="Assunto" v-model="form.subject" placeholder="Assunto do documento" />
          <Input
            class="col-5"
            label="Descrição breve"
            v-model="form.description"
            placeholder="Até 25 caracteres"
            maxlength="25"
          />
          <Switch class="col-2 doc-active" v-model="form.active" label="Situação" :text-label="form.active ? 'Ativo' : 'Inativo'" />
        </div>
      </div>

      <div class="doc-toolbar">
        <button type="button" class="tb-btn" title="Desfazer" @click="exec('undo')">
          <i class="fa-solid fa-rotate-left" />
        </button>
        <button type="button" class="tb-btn" title="Refazer" @click="exec('redo')">
          <i class="fa-solid fa-rotate-right" />
        </button>

        <span class="tb-sep" />

        <select class="tb-select" title="Estilo" @change="applyBlock($event.target.value)">
          <option value="p">Texto normal</option>
          <option value="h1">Título 1</option>
          <option value="h2">Título 2</option>
          <option value="h3">Título 3</option>
          <option value="blockquote">Citação</option>
        </select>

        <span class="tb-sep" />

        <button type="button" class="tb-btn" title="Negrito" @click="exec('bold')">
          <i class="fa-solid fa-bold" />
        </button>
        <button type="button" class="tb-btn" title="Itálico" @click="exec('italic')">
          <i class="fa-solid fa-italic" />
        </button>
        <button type="button" class="tb-btn" title="Sublinhado" @click="exec('underline')">
          <i class="fa-solid fa-underline" />
        </button>
        <button type="button" class="tb-btn" title="Tachado" @click="exec('strikeThrough')">
          <i class="fa-solid fa-strikethrough" />
        </button>

        <span class="tb-sep" />

        <label class="tb-btn tb-color" title="Cor do texto">
          <i class="fa-solid fa-font" />
          <input type="color" @input="exec('foreColor', $event.target.value)" />
        </label>
        <label class="tb-btn tb-color" title="Cor de destaque">
          <i class="fa-solid fa-highlighter" />
          <input type="color" @input="exec('hiliteColor', $event.target.value)" />
        </label>

        <span class="tb-sep" />

        <button type="button" class="tb-btn" title="Alinhar à esquerda" @click="exec('justifyLeft')">
          <i class="fa-solid fa-align-left" />
        </button>
        <button type="button" class="tb-btn" title="Centralizar" @click="exec('justifyCenter')">
          <i class="fa-solid fa-align-center" />
        </button>
        <button type="button" class="tb-btn" title="Alinhar à direita" @click="exec('justifyRight')">
          <i class="fa-solid fa-align-right" />
        </button>
        <button type="button" class="tb-btn" title="Justificar" @click="exec('justifyFull')">
          <i class="fa-solid fa-align-justify" />
        </button>

        <span class="tb-sep" />

        <button type="button" class="tb-btn" title="Lista com marcadores" @click="exec('insertUnorderedList')">
          <i class="fa-solid fa-list-ul" />
        </button>
        <button type="button" class="tb-btn" title="Lista numerada" @click="exec('insertOrderedList')">
          <i class="fa-solid fa-list-ol" />
        </button>
        <button type="button" class="tb-btn" title="Diminuir recuo" @click="exec('outdent')">
          <i class="fa-solid fa-outdent" />
        </button>
        <button type="button" class="tb-btn" title="Aumentar recuo" @click="exec('indent')">
          <i class="fa-solid fa-indent" />
        </button>

        <span class="tb-sep" />

        <button type="button" class="tb-btn" title="Inserir link" @click="insertLink">
          <i class="fa-solid fa-link" />
        </button>
        <button type="button" class="tb-btn" title="Linha horizontal" @click="exec('insertHorizontalRule')">
          <i class="fa-solid fa-ruler-horizontal" />
        </button>
        <button type="button" class="tb-btn" title="Limpar formatação" @click="exec('removeFormat')">
          <i class="fa-solid fa-text-slash" />
        </button>

        <span class="page-counter">
          <i class="fa-solid fa-file-lines" aria-hidden="true" />
          {{ pageCount }} {{ pageCount === 1 ? 'folha' : 'folhas' }}
        </span>
      </div>

      <div class="doc-layout">
        <aside class="var-panel" aria-label="Variáveis do documento">
          <header class="var-panel__head">
            <h3><i class="fa-solid fa-code" aria-hidden="true" /> Variáveis</h3>
            <p>Clique para inserir onde o cursor está. Elas são preenchidas ao emitir o PDF.</p>
            <Input v-model="variableQuery" type="search" small placeholder="Buscar variável..." aria-label="Buscar variável">
              <template #prefix>
                <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
              </template>
            </Input>
          </header>

          <div class="var-panel__body">
            <section v-for="group in groupedVariables" :key="group.name" class="var-group" :data-group="group.name">
              <button
                type="button"
                class="var-group__title"
                :aria-expanded="!isCollapsed(group.name)"
                @click="toggleGroup(group.name)"
              >
                <i :class="group.icon" aria-hidden="true" />
                <span>{{ group.name }}</span>
                <span class="var-group__count">{{ group.items.length }}</span>
                <i class="fa-solid fa-chevron-down var-group__chevron" :class="{ 'is-closed': isCollapsed(group.name) }" aria-hidden="true" />
              </button>
              <ul v-show="!isCollapsed(group.name)" class="var-group__list">
                <li v-for="item in group.items" :key="item.key">
                  <button type="button" class="var-item" :title="`Inserir ${token(item.key)}`" @mousedown.prevent @click="insertVariable(item.key)">
                    <span class="var-item__name">{{ item.name }}</span>
                    <code class="var-item__key">{{ token(item.key) }}</code>
                  </button>
                </li>
              </ul>
            </section>
            <p v-if="!groupedVariables.length" class="var-panel__empty">
              {{ variables.length ? 'Nenhuma variável encontrada.' : 'Carregando variáveis...' }}
            </p>
          </div>
        </aside>

        <div class="doc-sheet-wrap">
        <div class="doc-sheet-shell">
          <div
            ref="editorRef"
            class="doc-sheet"
            contenteditable="true"
            v-html="initialContent"
            @mouseup="saveSelection"
            @keyup="saveSelection"
          />
          <div class="page-breaks" aria-hidden="true">
            <div
              v-for="n in pageCount - 1"
              :key="n"
              class="page-break-line"
              :style="{ top: n * PAGE_HEIGHT + 'px' }"
            >
              <span class="page-break-label">Fim da folha {{ n }}</span>
            </div>
          </div>
        </div>
        </div>
      </div>

      <div v-if="error" class="form-error">{{ error }}</div>

      <FormActions :saving="saving" @cancel="$router.push('/document')" @click="handleFormActionsClick" />
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, nextTick } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { apiFetch } from '@/services/api'
import { normalize } from '@/permissions'
import Input from '@/components/utils/Input.vue'
import Switch from '@/components/utils/Switch.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'

const router = useRouter()
const route = useRoute()

const documentId = computed(() => route.params.id)
const isEdit = computed(() => !!documentId.value)

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const editorRef = ref(null)
const initialContent = ref('')

const form = ref({ name: '', subject: '', description: '', active: true })

// A4 a 96dpi (297mm), só um guia visual de onde a impressão quebraria a
// folha — não faz reflow real de conteúdo entre "páginas" separadas.
const PAGE_HEIGHT = 1123
const sheetHeight = ref(0)
const pageCount = computed(() => Math.max(1, Math.ceil(sheetHeight.value / PAGE_HEIGHT)))
let resizeObserver = null

// A toolbar (principalmente o Select de variáveis, que foca um <input> de
// busca ao abrir) rouba o foco do editor e some com o cursor; guardamos o
// último Range colocado dentro do editor pra poder devolver o cursor pro
// lugar certo antes de qualquer comando, em vez de cair sempre no topo.
let savedRange = null

const saveSelection = () => {
  const selection = window.getSelection()
  if (selection && selection.rangeCount > 0 && editorRef.value?.contains(selection.anchorNode)) {
    savedRange = selection.getRangeAt(0).cloneRange()
  }
}

const restoreSelection = () => {
  editorRef.value?.focus()
  if (!savedRange) return
  const selection = window.getSelection()
  selection.removeAllRanges()
  selection.addRange(savedRange)
}

const exec = (command, value = null) => {
  restoreSelection()
  document.execCommand(command, false, value)
  saveSelection()
}

const applyBlock = (tag) => {
  restoreSelection()
  document.execCommand('formatBlock', false, tag)
  saveSelection()
}

const insertLink = () => {
  const url = window.prompt('URL do link:')
  if (!url) return
  exec('createLink', url)
}

// A lista vem do backend (GET /documents/variables), que é a fonte única: variável nova = uma linha em
// DocumentService::VARIABLES. O painel lateral agrupa pela chave ("Cliente.name" → grupo Cliente) e filtra
// pela busca (sem acento); os grupos começam fechados, abrem com um clique, e a busca abre tudo.
const variables = ref([])
const variableQuery = ref('')
const expandedGroups = ref({})

const GROUP_ICONS = {
  Cliente: 'fa-solid fa-user',
  Projeto: 'fa-solid fa-diagram-project',
  Empresa: 'fa-solid fa-building',
  Geral: 'fa-solid fa-wand-magic-sparkles',
}

const groupedVariables = computed(() => {
  const words = normalize(variableQuery.value).split(/\s+/).filter(Boolean)
  const groups = new Map()
  for (const { key, label } of variables.value) {
    const name = key.includes('.') ? key.split('.')[0] : 'Geral'
    const title = label.includes(' - ') ? label.split(' - ').slice(1).join(' - ') : label
    if (words.length && !words.every((word) => normalize(`${name} ${title} ${key}`).includes(word))) continue
    if (!groups.has(name)) groups.set(name, { name, icon: GROUP_ICONS[name] || 'fa-solid fa-tag', items: [] })
    groups.get(name).items.push({ key, name: title })
  }
  return [...groups.values()]
})

const searching = computed(() => variableQuery.value.trim() !== '')
const isCollapsed = (name) => !searching.value && !expandedGroups.value[name]
const toggleGroup = (name) => {
  expandedGroups.value = { ...expandedGroups.value, [name]: !expandedGroups.value[name] }
}

const token = (key) => '{{' + key + '}}'

// In the document a variable is a pill (its readable name, colored by group) but it is saved as the plain
// {{Cliente.name}} text, so storage, sanitizing and emission don't know about pills: decorate() turns the
// tokens of a loaded document into pills, serialize() turns them back before saving. A zero-width space
// after each pill keeps the caret usable at the end of a line (and is stripped when saving).
const ZWSP = '\u200B'
const groupOf = (key) => (key.includes('.') ? key.split('.')[0] : 'Geral')
const variableLabels = computed(() => Object.fromEntries(variables.value.map((v) => [v.key, v.label])))
const escapeHtml = (text) => text.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c])

const pillHtml = (key) =>
  `<span class="var-pill" data-var="${key}" data-group="${groupOf(key)}" contenteditable="false">${escapeHtml(variableLabels.value[key])}</span>${ZWSP}`

// Unknown keys stay as text (a typo shows up as {{Cliente.nmae}}, and emission reports it).
const decorate = (html) => html.replace(/\{\{([A-Za-z_]+(?:\.[A-Za-z_]+)?)\}\}/g, (match, key) => (variableLabels.value[key] ? pillHtml(key) : match))

const serialize = () => {
  const root = editorRef.value.cloneNode(true)
  root.querySelectorAll('.var-pill[data-var]').forEach((pill) => pill.replaceWith(document.createTextNode(token(pill.dataset.var))))
  return root.innerHTML.replaceAll(ZWSP, '')
}

const insertVariable = (key) => exec('insertHTML', pillHtml(key))

const fetchVariables = async () => {
  const response = await apiFetch('/documents/variables')
  if (response.ok) variables.value = await response.json()
}

const fetchDocument = async () => {
  if (!isEdit.value) return

  loading.value = true
  error.value = ''
  try {
    const response = await apiFetch(`/documents/${documentId.value}`)
    if (!response.ok) {
      throw new Error('Não foi possível carregar este documento.')
    }
    const data = await response.json()
    form.value.name = data.name
    form.value.subject = data.subject || ''
    form.value.description = data.description || ''
    form.value.active = data.active ?? true
    initialContent.value = decorate(data.content || '')
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

const submitForm = async () => {
  saving.value = true
  error.value = ''

  const payload = {
    name: form.value.name || 'Documento sem título',
    subject: form.value.subject || null,
    description: form.value.description || null,
    active: form.value.active,
    content: editorRef.value ? serialize() : '',
  }

  const url = isEdit.value ? `/documents/${documentId.value}` : '/documents'
  const method = isEdit.value ? 'PUT' : 'POST'

  try {
    const response = await apiFetch(url, {
      method,
      body: JSON.stringify(payload),
    })

    const data = await response.json()

    if (!response.ok) {
      throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || 'Erro ao salvar documento.')
    }

    router.push('/document')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

// Sem <form> ao redor (a área editável não pode ficar dentro de um <form>,
// o Enter dispararia submit no meio da digitação) — o botão Salvar não tem
// nada para submeter, então o clique é pego aqui por delegação.
const handleFormActionsClick = (event) => {
  if (event.target.closest('button[type="submit"]')) submitForm()
}

onMounted(async () => {
  await fetchVariables() // the pills need the names before the content is shown
  await fetchDocument()
  await nextTick()
  if (editorRef.value) {
    resizeObserver = new ResizeObserver(() => {
      sheetHeight.value = editorRef.value.offsetHeight
    })
    resizeObserver.observe(editorRef.value)
  }
})

onBeforeUnmount(() => {
  resizeObserver?.disconnect()
})
</script>

<style scoped>
.editor-page {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.doc-card {
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  box-shadow: var(--shadow-sm);
  padding: 16px;
}

.doc-name :deep(.field-input) {
  padding: 10px 12px;
  font-size: 1.1rem;
  font-weight: 600;
}

.doc-active {
  justify-content: flex-end;
}

.doc-active :deep(.switch-wrapper) {
  height: 42px;
}

.loading-state {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 48px 24px;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.doc-toolbar {
  position: sticky;
  top: 0;
  z-index: 5;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  box-shadow: var(--shadow-sm);
  padding: 8px;
}

.tb-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 34px;
  height: 34px;
  border: 1px solid transparent;
  border-radius: 8px;
  background: transparent;
  color: var(--color-text-secondary);
  cursor: pointer;
  font-size: 0.875rem;
  position: relative;
}

.tb-btn:hover {
  background: var(--color-bg-muted);
  color: var(--color-text);
}

.tb-color input[type='color'] {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}

.tb-select {
  height: 34px;
  padding: 0 8px;
  border: 1px solid var(--color-border);
  border-radius: 8px;
  background: #ffffff;
  color: var(--color-text);
  font-family: var(--font-sans);
  font-size: 0.8125rem;
  cursor: pointer;
}

.tb-sep {
  width: 1px;
  height: 22px;
  background: var(--color-border);
  margin: 0 4px;
}

.page-counter {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin-left: auto;
  padding: 0 10px;
  height: 34px;
  color: var(--color-text-muted);
  font-size: 0.8125rem;
  white-space: nowrap;
}

.doc-layout {
  display: grid;
  grid-template-columns: clamp(340px, 30vw, 460px) minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

/* Sticky under the toolbar, with its own scroll: the sheet can be long, the variables stay in reach. */
.var-panel {
  position: sticky;
  top: 64px;
  display: flex;
  flex-direction: column;
  max-height: calc(100vh - 80px);
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  box-shadow: var(--shadow-sm);
  overflow: hidden;
}

.var-panel__head {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 14px 14px 12px;
  border-bottom: 1px solid var(--color-border);
}

.var-panel__head h3 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  color: var(--color-text);
  font-size: 0.95rem;
  font-weight: 700;
}

.var-panel__head h3 i {
  color: var(--color-primary);
}

.var-panel__head p {
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.78rem;
  line-height: 1.45;
}

.var-panel__body {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 8px;
  overflow-y: auto;
}

.var-panel__empty {
  margin: 12px 8px;
  color: var(--color-text-muted);
  font-size: 0.85rem;
  text-align: center;
}

.var-group__title {
  display: flex;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 8px 8px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  color: var(--color-text);
  font-family: var(--font-sans);
  font-size: 0.82rem;
  font-weight: 700;
  text-align: left;
  cursor: pointer;
}

.var-group__title:hover {
  background: var(--color-bg-muted);
}

.var-group__title > i:first-child {
  width: 16px;
  color: var(--color-primary);
  text-align: center;
}

.var-group__count {
  padding: 1px 7px;
  border-radius: var(--radius-full);
  background: var(--color-bg-muted);
  color: var(--color-text-muted);
  font-size: 0.7rem;
  font-weight: 600;
}

.var-group__chevron {
  margin-left: auto;
  color: var(--color-text-faint);
  font-size: 0.7rem;
  transition: transform 0.15s ease;
}

.var-group__chevron.is-closed {
  transform: rotate(-90deg);
}

.var-group__list {
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin: 0 0 6px;
  padding: 0;
  list-style: none;
}

.var-item {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: 2px 12px;
  width: 100%;
  padding: 8px 12px 8px 34px;
  border: 0;
  border-radius: 8px;
  background: transparent;
  text-align: left;
  cursor: pointer;
}

.var-item:hover,
.var-item:focus-visible {
  background: var(--color-primary-soft);
  outline: none;
}

.var-item__name {
  color: var(--color-text);
  font-family: var(--font-sans);
  font-size: 0.85rem;
  font-weight: 600;
}

.var-item__key {
  color: var(--color-text-muted);
  font-size: 0.72rem;
}

@media (max-width: 1023px) {
  .doc-layout {
    grid-template-columns: minmax(0, 1fr);
  }

  .var-panel {
    position: static;
    max-height: 280px;
  }
}

.doc-sheet-wrap {
  display: flex;
  justify-content: center;
  background: var(--color-bg-app);
  border-radius: 16px;
  padding: 24px;
}

.doc-sheet-shell {
  position: relative;
  width: 100%;
  max-width: 850px;
}

.doc-sheet {
  width: 100%;
  min-height: 1123px;
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 4px;
  box-shadow: var(--shadow-md);
  padding: 64px 72px;
  color: var(--color-text);
  font-size: 0.9375rem;
  line-height: 1.6;
  outline: none;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.page-breaks {
  position: absolute;
  inset: 0;
  pointer-events: none;
}

.page-break-line {
  position: absolute;
  left: 0;
  right: 0;
  border-top: 2px dashed var(--color-border-strong);
}

.page-break-label {
  position: absolute;
  top: 4px;
  right: 0;
  padding: 2px 8px;
  border-radius: var(--radius-full);
  background: var(--color-bg-app);
  color: var(--color-text-muted);
  font-size: 0.6875rem;
  font-weight: 600;
  white-space: nowrap;
}

.doc-sheet :deep(.var-pill) {
  display: inline-block;
  margin: 0 1px;
  padding: 0 10px;
  border: 1px solid var(--g-border, #bfdbfe);
  border-radius: 999px;
  background: var(--g-bg, #eff6ff);
  color: var(--g-fg, #1d4ed8);
  font-size: 0.85em;
  font-weight: 600;
  line-height: 1.6;
  white-space: nowrap;
  cursor: default;
  user-select: all;
}

.doc-sheet :deep(.var-pill[data-group='Projeto']),
.var-group[data-group='Projeto'] {
  --g-fg: #6d28d9;
  --g-bg: #f5f3ff;
  --g-border: #ddd6fe;
}

.doc-sheet :deep(.var-pill[data-group='Empresa']),
.var-group[data-group='Empresa'] {
  --g-fg: #0f766e;
  --g-bg: #f0fdfa;
  --g-border: #99f6e4;
}

.doc-sheet :deep(.var-pill[data-group='Geral']),
.var-group[data-group='Geral'] {
  --g-fg: #b45309;
  --g-bg: #fffbeb;
  --g-border: #fde68a;
}

.var-group[data-group] .var-group__title > i:first-child {
  color: var(--g-fg, #1d4ed8);
}

.var-group[data-group] .var-item__key {
  padding: 0 7px;
  border-radius: 999px;
  background: var(--g-bg, #eff6ff);
  color: var(--g-fg, #1d4ed8);
}

.doc-sheet :deep(h1) {
  font-size: 1.75rem;
  font-weight: 700;
  margin: 0 0 12px;
}

.doc-sheet :deep(h2) {
  font-size: 1.375rem;
  font-weight: 700;
  margin: 0 0 10px;
}

.doc-sheet :deep(h3) {
  font-size: 1.125rem;
  font-weight: 700;
  margin: 0 0 8px;
}

.doc-sheet :deep(blockquote) {
  margin: 0 0 12px;
  padding-left: 16px;
  border-left: 3px solid var(--color-border-strong);
  color: var(--color-text-secondary);
}

.doc-sheet :deep(p) {
  margin: 0 0 12px;
}

.doc-sheet :deep(a) {
  color: var(--color-secondary);
}
</style>
