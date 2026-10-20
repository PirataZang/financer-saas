// "2026-10-07" (date-only, from the API) → "07/10/2026" without the UTC shift of new Date("2026-10-07").
export function formatDate(value) {
  if (!value) return ''
  if (/^\d{4}-\d{2}-\d{2}$/.test(value)) return value.split('-').reverse().join('/')
  return new Date(value).toLocaleDateString('pt-BR')
}

// "há 3 dias", "hoje", "ontem" — for announcement timestamps.
export function timeAgo(value, now = new Date()) {
  const date = new Date(value)
  const startOf = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()
  const days = Math.round((startOf(now) - startOf(date)) / 86_400_000)
  if (days <= 0) return 'hoje'
  if (days === 1) return 'ontem'
  if (days < 30) return `há ${days} dias`
  return date.toLocaleDateString('pt-BR')
}
