import { apiFetch } from '@/services/api'

// Endereço por CEP via backend (GET /zip-codes/{cep} → ZipCodeService → ViaCEP).
// Nunca lança: devolve { address } ou { error } para a tela seguir com preenchimento manual.
export async function lookupZipCode(zipCode) {
  const digits = (zipCode || '').replace(/\D/g, '')
  if (digits.length !== 8) {
    return { error: 'CEP inválido. Informe 8 dígitos.' }
  }

  try {
    const response = await apiFetch(`/zip-codes/${digits}`, { signal: AbortSignal.timeout(8000) })
    const data = await response.json().catch(() => ({}))

    if (!response.ok) {
      return { error: data.message || 'Não foi possível consultar o CEP. Preencha o endereço manualmente.' }
    }

    return { address: data }
  } catch {
    return { error: 'Não foi possível consultar o CEP agora. Preencha o endereço manualmente.' }
  }
}
