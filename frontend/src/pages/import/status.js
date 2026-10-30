// Opções do badge de status de uma importação (type: 'badge' do AgGrid). O status vem da API: processing | completed.
export const IMPORT_STATUS = [
  { value: 'processing', label: 'Processando', color: 'warning', icon: 'fa-solid fa-spinner fa-spin' },
  { value: 'completed', label: 'Concluída', color: 'success', icon: 'fa-solid fa-circle-check' },
]
