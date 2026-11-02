<template>
    <div class="grid-wrapper ag-theme-quartz" :class="{ 'auto-height': isAutoHeight }">
        <AgGridVue class="grid" theme="legacy" :rowData="rowData" :columnDefs="columnDefs" :columnTypes="columnTypes"
            :defaultColDef="defaultColDef" :localeText="localeText" :rowSelection="rowSelectionConfig"
            :context="context" :domLayout="domLayout" :rowHeight="rowHeight" singleClickEdit :stopEditingWhenCellsLoseFocus="true" :tooltipShowDelay="300" @cell-value-changed="emit('cell-edit', $event)" @grid-ready="onGridReady" @selection-changed="onSelectionChanged"
            @row-clicked="onRowClicked" @row-double-clicked="onRowDoubleClicked" />

        <!-- Painel de Paginação Premium Customizado -->
        <div class="custom-pagination">
            <div class="pagination-left">
                <div class="pagination-info">
                    Exibindo <span class="highlight">{{ fromRecord }}</span> a <span class="highlight">{{ toRecord }}</span>
                    de <span class="highlight">{{ totalRows }}</span> registros
                </div>
                <div v-if="exportable" class="pagination-export">
                    <button class="export-excel" title="Exportar para Excel" aria-label="Exportar para Excel"
                        @click="askExport('xlsx')">
                        <i class="fa-solid fa-file-excel"></i>
                    </button>
                    <button class="export-csv" @click="askExport('csv')">
                        <i class="fa-solid fa-file-export"></i> Exportar CSV
                    </button>
                </div>
            </div>

            <div class="pagination-controls">
                <button class="pag-btn" :disabled="currentPage === 1" @click="changePage(1)" title="Primeira Página">
                    <i class="fa-solid fa-angles-left"></i>
                </button>
                <button class="pag-btn" :disabled="currentPage === 1" @click="changePage(currentPage - 1)"
                    title="Página Anterior">
                    <i class="fa-solid fa-angle-left"></i>
                </button>

                <button v-for="page in visiblePages" :key="page" class="pag-btn page-num"
                    :class="{ active: page === currentPage }" @click="changePage(page)">
                    {{ page }}
                </button>

                <button class="pag-btn" :disabled="currentPage === totalPages" @click="changePage(currentPage + 1)"
                    title="Próxima Página">
                    <i class="fa-solid fa-angle-right"></i>
                </button>
                <button class="pag-btn" :disabled="currentPage === totalPages" @click="changePage(totalPages)"
                    title="Última Página">
                    <i class="fa-solid fa-angles-right"></i>
                </button>
            </div>

            <div class="pagination-size">
                <span>Registros por página:</span>
                <select :value="pageSize" @change="changePageSize($event)" class="size-select">
                    <option :value="10">10</option>
                    <option :value="25">25</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select>
                <button class="columns-btn" title="Exibir/ocultar colunas" @click="openColumns">
                    <i class="fa-solid fa-table-columns"></i> Colunas
                </button>
            </div>
        </div>

        <Modal v-model="columnsOpen" title="Colunas do grid" size="sm">
            <p class="export-text">Marque as colunas que devem ficar visíveis.</p>
            <ul class="columns-list">
                <li v-for="col in columnList" :key="col.id">
                    <label>
                        <input type="checkbox" :checked="col.visible" @change="toggleColumn(col, $event.target.checked)" />
                        {{ col.name }}
                    </label>
                </li>
            </ul>
            <template #footer>
                <Button label="Fechar" @click="columnsOpen = false" />
            </template>
        </Modal>

        <Modal :modelValue="!!exportFormat" @update:modelValue="exportFormat = ''" title="Confirmar exportação" size="sm">
            <p class="export-text">
                Exportar <strong>{{ exportCount }}</strong> registro(s) da tela atual para
                <strong>{{ exportFormat === 'xlsx' ? 'Excel (.xlsx)' : 'CSV (.csv)' }}</strong>?
                As colunas seguem a ordem e os títulos exibidos no grid.
            </p>
            <template #footer>
                <Button variant="secondary" label="Cancelar" @click="exportFormat = ''" />
                <Button label="Exportar" icon="fa-solid fa-download" @click="doExport" />
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, h, defineComponent, markRaw } from 'vue'
import { AgGridVue } from 'ag-grid-vue3'
import { ModuleRegistry, ClientSideRowModelModule, ColumnApiModule, CellApiModule, CellStyleModule, RowSelectionModule, TextEditorModule, TooltipModule, TextFilterModule, NumberFilterModule, DateFilterModule, LocaleModule, ValidationModule } from 'ag-grid-community'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import Modal from '@/components/utils/Modal.vue'
import Button from '@/components/utils/Button.vue'
import { toCsv, toXlsx, download } from '@/utils/export'
import { useAuthStore } from '@/stores/auth'

// Imports dos estilos do AG Grid
import 'ag-grid-community/styles/ag-grid.css'
import 'ag-grid-community/styles/ag-theme-quartz.css'

/* ===============================
   REGISTRO DOS MÓDULOS
================================ */
ModuleRegistry.registerModules([ClientSideRowModelModule, ColumnApiModule, CellApiModule, CellStyleModule, RowSelectionModule, TextEditorModule, TooltipModule, TextFilterModule, NumberFilterModule, DateFilterModule, LocaleModule, ValidationModule])

/* ===============================
   PROPS
================================ */
const props = defineProps({
    rowData: { type: Array, required: true },
    columnDefs: { type: Array, required: true },
    defaultColDef: { type: Object, default: () => ({ sortable: true, filter: true, floatingFilter: false, resizable: true }) },
    currentPage: { type: Number, default: 1 },
    pageSize: { type: Number, default: 50 },
    totalRows: { type: Number, default: 0 },
    selectable: { type: Boolean, default: true },
    gridHeight: { type: String, default: '80vh' },
    context: { type: Object, default: () => ({}) },
    // Rodapé com exportação Excel/CSV; passe :exportable="false" pra ocultar.
    exportable: { type: Boolean, default: true },
    exportName: { type: String, default: 'exportacao' },
})

/* ===============================
   EMITS
================================ */
const emit = defineEmits(['update:selection', 'update:page', 'update:pageSize', 'row-click', 'row-dblclick', 'cell-edit'])

/* ===============================
   GRID API
================================ */
const gridApi = ref(null)

const onGridReady = (params) => {
    gridApi.value = params.api
}

/* ===============================
   COLUNAS VISÍVEIS
   Colunas com `hide: true` no columnDefs já nascem escondidas; aqui o usuário alterna.
================================ */
const columnsOpen = ref(false)
const columnList = ref([])

const openColumns = () => {
    columnList.value = gridApi.value
        .getColumns()
        .filter((c) => c.getColDef().field || c.getColDef().valueGetter)
        .map((c) => ({ id: c.getColId(), name: c.getColDef().headerName ?? c.getColDef().field, visible: c.isVisible() }))
    columnsOpen.value = true
}

const toggleColumn = (col, visible) => {
    col.visible = visible
    gridApi.value.setColumnsVisible([col.id], visible)
}

/* ===============================
   EXPORTAÇÃO (Excel / CSV)
   Exporta as linhas carregadas no grid (página atual), na ordem, com os
   títulos e os textos exibidos nas colunas.
================================ */
const exportFormat = ref('') // '' = modal fechado · 'xlsx' | 'csv'
const exportCount = ref(0)

const askExport = (format) => {
    exportCount.value = gridApi.value?.getDisplayedRowCount() ?? 0
    exportFormat.value = format
}

const cellText = (col, node) => {
    const api = gridApi.value
    const def = col.getColDef()
    const params = def.cellRendererParams
    const raw = api.getCellValue({ colKey: col, rowNode: node })
    if (def.type === 'badge') {
        const options = typeof params?.options === 'function' ? params.options() : params?.options || []
        return options.find((o) => o.value === raw)?.label ?? raw ?? ''
    }
    if (def.type === 'boolean') return raw ? params?.trueLabel || 'Sim' : params?.falseLabel || 'Não'
    return api.getCellValue({ colKey: col, rowNode: node, useFormatter: true }) ?? ''
}

const doExport = () => {
    const api = gridApi.value
    // Só colunas de dados (sem a de checkbox/ações), na ordem em que aparecem.
    const cols = api.getAllDisplayedColumns().filter((c) => c.getColDef().field || c.getColDef().valueGetter)
    const rows = [cols.map((c) => c.getColDef().headerName ?? c.getColDef().field)]
    for (let i = 0; i < api.getDisplayedRowCount(); i++) {
        const node = api.getDisplayedRowAtIndex(i)
        rows.push(cols.map((c) => cellText(c, node)))
    }

    const stamp = new Date().toISOString().slice(0, 10)
    const name = `${props.exportName}-${stamp}.${exportFormat.value}`
    download(exportFormat.value === 'xlsx' ? toXlsx(rows) : new Blob([toCsv(rows)], { type: 'text/csv;charset=utf-8' }), name)
    exportFormat.value = ''
}

const onSelectionChanged = () => {
    if (!gridApi.value || !props.selectable) return
    const selectedData = gridApi.value.getSelectedNodes().map((node) => node.data)
    emit('update:selection', selectedData)
}

const onRowClicked = (event) => {
    // Clicar na linha marca/desmarca o checkbox (mesmo comportamento de clicar
    // nele direto) em vez de abrir o registro; clique duplo é que abre (ver
    // onRowDoubleClicked). Ignora quando o clique já veio do próprio checkbox
    // pra não desfazer o toggle nativo dele.
    const target = event.event?.target
    const clickedCheckbox = target?.closest?.('.ag-selection-checkbox, .ag-checkbox-cell')
    if (props.selectable && !clickedCheckbox) {
        event.node.setSelected(!event.node.isSelected())
    }
    emit('row-click', event.data)
}

const onRowDoubleClicked = (event) => {
    emit('row-dblclick', event.data)
}

const rowSelectionConfig = computed(() => {
    if (!props.selectable) return undefined
    return {
        mode: 'multiRow',
        checkboxes: true,
        headerCheckbox: true,
    }
})

const isAutoHeight = computed(() => props.gridHeight === 'auto')
// Configurações › Preferências: compact rows fit more on screen; undefined = theme default (42px).
const rowHeight = computed(() => (useAuthStore().preferences.table_density === 'compact' ? 34 : undefined))
const domLayout = computed(() => isAutoHeight.value ? 'autoHeight' : 'normal')

const localeText = {
    page: 'Página',
    more: 'Mais',
    to: 'até',
    of: 'de',
    next: 'Próxima',
    last: 'Última',
    first: 'Primeira',
    previous: 'Anterior',
    loadingOoo: 'Carregando...',
    selectAll: 'Selecionar tudo',
    searchOoo: 'Pesquisar...',
    blanks: 'Em branco',

    filterOoo: 'Filtrar...',
    equals: 'Igual',
    notEqual: 'Diferente',
    lessThan: 'Menor que',
    greaterThan: 'Maior que',
    contains: 'Contém',
    notContains: 'Não contém',
    startsWith: 'Começa com',
    endsWith: 'Termina com',
    blank: 'Vazio',
    notBlank: 'Não Vazio',
    after: 'Depois',
    before: 'Antes',
    true: 'Verdadeiro',
    between: 'Entre',
    false: 'Falso',
    applyFilter: 'Aplicar filtro',
    clearFilter: 'Limpar filtro',
}

// A plain function cellRenderer is treated by AG Grid as a JS renderer (string/DOM only), so a
// Vue VNode renders blank. Wrapping it as a component lets ag-grid-vue3 mount it with `params`.
const cellComponent = (render) => markRaw(defineComponent({
    props: ['params'],
    setup: (props) => () => render(props.params),
}))

const columnTypes = {
    date: {
        filter: 'agDateColumnFilter',
        valueFormatter: (params) => {
            if (!params.value) return ''
            // "2026-10-07" (date-only) is parsed as UTC midnight → previous day in Brazil; format it as-is.
            if (/^\d{4}-\d{2}-\d{2}$/.test(params.value)) return params.value.split('-').reverse().join('/')
            return new Date(params.value).toLocaleDateString('pt-BR')
        },
    },

    datetime: {
        filter: 'agDateColumnFilter',
        valueFormatter: (params) => {
            if (!params.value) return ''
            return new Date(params.value).toLocaleString('pt-BR')
        },
    },

    money: {
        filter: 'agNumberColumnFilter',
        valueFormatter: (params) => {
            if (!params.value) return ''
            return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(params.value)
        },
    },

    // Valor de enum como StatusBadge. Passe as opções da API (com label/color/icon) como função,
    // lida a cada render (as opções costumam chegar depois da definição das colunas):
    // { field: 'status', type: 'badge', cellRendererParams: { options: () => statusOptions.value } }
    badge: {
        cellRenderer: cellComponent((params) => {
            const options = typeof params.options === 'function' ? params.options() : params.options || []
            const option = options.find((o) => o.value === params.value)
            return option ? h(StatusBadge, { label: option.label, color: option.color, icon: option.icon, small: true }) : params.value
        }),
    },

    // Ícone de check (sim) ou X (não) em vez de true/false cru. Somente exibição.
    // Rótulos acessíveis: cellRendererParams: { trueLabel: 'Ativo', falseLabel: 'Inativo' }.
    boolean: {
        cellClass: 'cell-center',
        cellRenderer: cellComponent((params) => {
            const on = !!params.value
            const label = on ? params.trueLabel || 'Sim' : params.falseLabel || 'Não'
            return h('span', { class: ['bool-icon', on ? 'is-true' : 'is-false'], title: label, role: 'img', 'aria-label': label }, [
                h('i', { class: on ? 'fa-solid fa-check' : 'fa-solid fa-xmark', 'aria-hidden': 'true' }),
            ])
        }),
    },
}

/* ===============================
   PAGINAÇÃO CUSTOMIZADA LOGIC
================================ */
const totalPages = computed(() => Math.ceil(props.totalRows / props.pageSize) || 1)
const fromRecord = computed(() => props.totalRows === 0 ? 0 : (props.currentPage - 1) * props.pageSize + 1)
const toRecord = computed(() => Math.min(props.currentPage * props.pageSize, props.totalRows))

const visiblePages = computed(() => {
    const pages = []
    const range = 2
    const start = Math.max(1, props.currentPage - range)
    const end = Math.min(totalPages.value, props.currentPage + range)

    for (let i = start; i <= end; i++) {
        pages.push(i)
    }
    return pages
})

const changePage = (page) => {
    if (page >= 1 && page <= totalPages.value && page !== props.currentPage) {
        emit('update:page', page)
    }
}

const changePageSize = (event) => {
    const size = parseInt(event.target.value, 10)
    emit('update:pageSize', size)
    emit('update:page', 1)
}
</script>

<style scoped>
.grid-wrapper {
    width: 100%;
    height: v-bind(gridHeight);
    display: flex;
    flex-direction: column;

}

.grid-wrapper.auto-height {
    height: auto;
}

.grid-wrapper .grid {
    width: 100%;
    flex: 1;
    min-height: 0;
    display: flex;
    top: 0;
    position: relative;
    flex-direction: column;
}

.grid-wrapper.auto-height .grid {
    flex: none;
}

.grid-wrapper .grid :deep(.ag-row) {
    cursor: pointer;
    transition: background-color 0.15s ease, border-left-color 0.15s ease;
    border-left: 3px solid transparent;
}

.grid-wrapper .grid :deep(.ag-row:hover) {
    background-color: #eff6ff !important;
    border-left-color: var(--color-primary);
}

.grid-wrapper .grid :deep(.bool-icon) {
    display: inline-grid;
    place-items: center;
    width: 24px;
    height: 24px;
    border-radius: 999px;
    font-size: 0.75rem;
}

.grid-wrapper .grid :deep(.bool-icon.is-true) {
    background: var(--color-success-soft);
    color: var(--color-success);
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--color-success) 25%, transparent);
}

.grid-wrapper .grid :deep(.bool-icon.is-false) {
    background: var(--color-bg-muted);
    color: var(--color-text-muted);
    box-shadow: inset 0 0 0 1px var(--color-border);
}

.grid-wrapper .grid :deep(.cell-center) {
    width: 100%;
    display: flex;
    justify-content: center;
    align-items: center;
    text-align: center;
}

.grid-wrapper .grid :deep(.cell-right) {
    display: flex;
    justify-content: flex-end;
    align-items: flex-end;
    text-align: right;
}

.grid-wrapper .grid :deep(.cell-left) {
    display: flex;
    justify-content: flex-start;
    align-items: flex-start;
    text-align: left;
}

.custom-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 20px;
    background: #ffffff;
    border-top: 1px solid #e8e5e0;
    border-bottom-left-radius: 16px;
    border-bottom-right-radius: 16px;
    font-size: 0.875rem;
    color: #5c5852;
    flex-wrap: wrap;
    gap: 12px;
}

.custom-pagination .pagination-info {
    font-weight: 500;
}

.custom-pagination .pagination-info .highlight {
    color: #5c5852;
    font-weight: 600;
}

.custom-pagination .pagination-controls {
    display: flex;
    align-items: center;
    gap: 6px;
}

.custom-pagination .pagination-controls .pag-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 8px;
    border: 1px solid #e8e5e0;
    background: #ffffff;
    color: #3d3a36;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
    outline: none;
}

.custom-pagination .pagination-controls .pag-btn:hover:not(:disabled) {
    background: #f3f1ed;
    border-color: #d4d0ca;
    color: #1f1e1c;
}

.custom-pagination .pagination-controls .pag-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
}

.custom-pagination .pagination-controls .pag-btn.page-num {
    font-size: 0.875rem;
}

.custom-pagination .pagination-controls .pag-btn.page-num.active {
    background: #5c5852;
    border-color: #5c5852;
    color: #ffffff;
    font-weight: 600;
}

.custom-pagination .pagination-left {
    display: flex;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.custom-pagination .pagination-export {
    display: flex;
    align-items: center;
    gap: 8px;
}

.custom-pagination .pagination-export .export-excel,
.custom-pagination .pagination-export .export-csv {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 34px;
    padding: 0 12px;
    border-radius: 8px;
    border: 1px solid #e8e5e0;
    background: #ffffff;
    color: #3d3a36;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.custom-pagination .pagination-export .export-excel {
    justify-content: center;
    width: 34px;
    padding: 0;
    font-size: 1.1rem;
    color: #1d6f42;
}

.custom-pagination .pagination-export .export-excel:hover,
.custom-pagination .pagination-export .export-csv:hover {
    background: #f3f1ed;
    border-color: #d4d0ca;
}

.columns-list {
    list-style: none;
    margin: 12px 0 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 4px;
    max-height: 50vh;
    overflow-y: auto;
}

.columns-list label {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-radius: 8px;
    cursor: pointer;
    color: var(--color-text);
}

.columns-list label:hover {
    background: #f3f1ed;
}

.columns-list input {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.custom-pagination .pagination-size .columns-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    height: 34px;
    margin-left: 8px;
    padding: 0 12px;
    border-radius: 8px;
    border: 1px solid #e8e5e0;
    background: #ffffff;
    color: #3d3a36;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.2s ease;
}

.custom-pagination .pagination-size .columns-btn:hover {
    background: #f3f1ed;
    border-color: #d4d0ca;
}

.export-text {
    margin: 0;
    line-height: 1.5;
    color: var(--color-text);
}

.custom-pagination .pagination-size {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 500;
}

.custom-pagination .pagination-size .size-select {
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid #e8e5e0;
    background-color: #ffffff;
    color: #3d3a36;
    font-size: 0.875rem;
    font-weight: 500;
    cursor: pointer;
    outline: none;
    transition: border-color 0.2s ease;
}

.custom-pagination .pagination-size .size-select:focus {
    border-color: #7a746c;
}

@media (max-width: 768px) {
    .custom-pagination {
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 16px;
    }

    .custom-pagination .pagination-left {
        order: 1;
        justify-content: center;
    }

    .custom-pagination .pagination-controls {
        order: 2;
        width: 100%;
        justify-content: center;
    }

    .custom-pagination .pagination-size {
        order: 3;
    }
}
</style>
