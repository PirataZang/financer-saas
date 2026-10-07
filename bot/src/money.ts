// Money stays a decimal string end to end ("150.50"); the backend validates it again.

const MAX_INTEGER_DIGITS = 13

/**
 * Parses what a Brazilian user types: "150,50", "R$ 1.234,56", "1234.5", "10".
 * A trailing separator + 1–2 digits is the decimal part; any other separator must split
 * thousands groups of 3 and differ from the decimal one. "1,234" alone is ambiguous in pt-BR
 * (1234 or 1,234?), so it is rejected. Returns "1234.56", or null if invalid/zero.
 */
export function parseMoney(input: string): string | null {
  const text = input.replace(/r\$/i, '').replace(/\s+/g, '')
  const match = /^(\d+|\d{1,3}(?:([.,])\d{3})+)(?:([.,])(\d{1,2}))?$/.exec(text)
  if (!match) return null

  const [, integerPart = '', thousands, decimalSeparator, decimals = ''] = match
  if (thousands && (thousands === decimalSeparator || (thousands === ',' && !decimalSeparator))) return null

  const integer = integerPart.replace(/[.,]/g, '').replace(/^0+(?=\d)/, '')
  if (integer.length > MAX_INTEGER_DIGITS) return null

  const amount = `${integer}.${decimals.padEnd(2, '0')}`
  return amount === '0.00' ? null : amount
}

const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })

/** Display only. */
export function formatMoney(amount: string): string {
  return brl.format(Number(amount))
}

/** YYYY-MM-DD in the given timezone. */
export function todayIn(timeZone: string, now = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now)
}
