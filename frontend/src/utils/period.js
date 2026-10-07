// Period presets shared by the finance screens. Dates are local YYYY-MM-DD (the API's format):
// toISOString() would shift to UTC and land on the wrong day in Brazil at night.

export const PERIODS = [
  { value: 'today', label: 'Hoje' },
  { value: '7d', label: '7 dias' },
  { value: '30d', label: '30 dias' },
  { value: 'month', label: 'Este mês' },
  { value: '3m', label: 'Últimos 3 meses' },
  { value: 'year', label: 'Este ano' },
  { value: 'custom', label: 'Personalizado' },
]

export function toDateString(date) {
  const pad = (n) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`
}

/** { from, to } for a preset ('custom' → null: the user picks the dates). */
export function periodRange(key, today = new Date()) {
  const y = today.getFullYear()
  const m = today.getMonth()
  const d = today.getDate()
  const from = {
    today: new Date(y, m, d),
    '7d': new Date(y, m, d - 6),
    '30d': new Date(y, m, d - 29),
    month: new Date(y, m, 1),
    '3m': new Date(y, m - 2, 1),
    year: new Date(y, 0, 1),
  }[key]
  return from ? { from: toDateString(from), to: toDateString(today) } : null
}
