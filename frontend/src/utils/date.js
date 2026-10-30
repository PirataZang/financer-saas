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

// "agora", "há 12 min", "há 3 h", then the date and time — for sync timestamps.
export function sinceTime(value, now = new Date()) {
  const minutes = Math.floor((now - new Date(value)) / 60_000)
  if (minutes < 1) return 'agora'
  if (minutes < 60) return `há ${minutes} min`
  if (minutes < 24 * 60) return `há ${Math.floor(minutes / 60)} h`
  return new Date(value).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}
