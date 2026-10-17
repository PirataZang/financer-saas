// Shared by the Empresas list and form. Mirrors App\Models\Company: the license is active with
// a token and while "expira em" (inclusive) hasn't passed; company 1 can't be deleted.
export const DEFAULT_COMPANY_ID = 1

export const LICENSE_OPTIONS = [
  { value: true, label: 'Ativa', color: 'success', icon: 'fa-solid fa-circle-check' },
  { value: false, label: 'Bloqueada', color: 'danger', icon: 'fa-solid fa-lock' },
]
