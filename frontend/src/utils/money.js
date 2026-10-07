const brl = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' })

// Display only: the API sends/receives amounts as decimal strings ("150.50").
export function formatMoney(value) {
  return brl.format(Number(value) || 0)
}
