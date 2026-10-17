import { ref, watch } from 'vue'
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

// Form glue shared by every address form: looks the CEP up as soon as it has 8 digits (or on
// blur), fills street/neighborhood/city/state/country and hands focus to the number field.
// On any failure the fields stay editable. Call markZipLoaded() after loading a saved record so
// its hand-edited address isn't overwritten.
export function useZipCodeLookup(form, numberInput) {
  const zipLoading = ref(false)
  const zipMessage = ref('')
  const zipError = ref(false)
  let lastZipLookup = ''

  const searchZipCode = async () => {
    const zipCode = form.value.zip_code || ''
    zipError.value = false
    zipMessage.value = ''

    if (!zipCode || zipCode === lastZipLookup) return
    if (zipCode.length !== 8) {
      zipError.value = true
      zipMessage.value = 'CEP inválido. Informe 8 dígitos.'
      return
    }

    lastZipLookup = zipCode
    zipLoading.value = true
    const { address, error } = await lookupZipCode(zipCode)
    zipLoading.value = false

    if (form.value.zip_code !== zipCode) return // CEP changed while loading

    if (error) {
      zipError.value = true
      zipMessage.value = error
      lastZipLookup = ''
      return
    }

    form.value.street = address.street || form.value.street
    form.value.neighborhood = address.neighborhood || form.value.neighborhood
    form.value.city = address.city
    form.value.state = address.state
    form.value.country = address.country
    zipMessage.value = 'Endereço preenchido pelo CEP. Confira e informe o número.'
    // The CEP never brings the number: hand focus to it.
    numberInput.value?.$el.querySelector('input')?.focus()
  }

  watch(
    () => form.value.zip_code,
    (zipCode) => {
      if (zipCode?.length === 8) searchZipCode()
    },
  )

  return {
    zipLoading,
    zipMessage,
    zipError,
    searchZipCode,
    markZipLoaded: () => (lastZipLookup = form.value.zip_code),
  }
}
