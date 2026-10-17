// Cada máscara expõe format(rawDigits) -> string exibida e unmask(displayValue) -> valor salvo.
// unmask() é sempre a fonte da verdade enviada para o backend (só dígitos, sem pontuação).

function onlyDigits(value) {
  return (value || '').replace(/\D/g, '')
}

function applyPattern(digits, pattern) {
  // pattern usa '9' como placeholder de dígito, ex: '999.999.999-99'
  let result = ''
  let digitIndex = 0

  for (const char of pattern) {
    if (digitIndex >= digits.length) break
    if (char === '9') {
      result += digits[digitIndex]
      digitIndex++
    } else {
      result += char
    }
  }

  return result
}

const cpf = {
  format(value) {
    return applyPattern(onlyDigits(value).slice(0, 11), '999.999.999-99')
  },
  unmask: onlyDigits,
}

const cnpj = {
  format(value) {
    return applyPattern(onlyDigits(value).slice(0, 14), '99.999.999/9999-99')
  },
  unmask: onlyDigits,
}

// Alterna CPF (até 11 dígitos) / CNPJ (12+ dígitos) automaticamente no mesmo campo.
const cpfCnpj = {
  format(value) {
    const digits = onlyDigits(value).slice(0, 14)
    return digits.length > 11 ? cnpj.format(digits) : cpf.format(digits)
  },
  unmask: onlyDigits,
}

const phone = {
  format(value) {
    const digits = onlyDigits(value).slice(0, 11)
    return digits.length > 10 ? applyPattern(digits, '(99) 99999-9999') : applyPattern(digits, '(99) 9999-9999')
  },
  unmask: onlyDigits,
}

const cep = {
  format(value) {
    return applyPattern(onlyDigits(value).slice(0, 8), '99999-999')
  },
  unmask: onlyDigits,
}

// Valor em reais: o model guarda o decimal da API ("1234.56"); a tela mostra "1.234,56". Digitar empurra
// da direita (centavos primeiro, como na maquininha). Tudo por string: dinheiro nunca passa por float.
const money = {
  fromRight: true,
  format(value) {
    if (value === '' || value == null) return ''
    const [whole, fraction = ''] = String(value).split('.')
    return `${Number(whole).toLocaleString('pt-BR')},${fraction.padEnd(2, '0').slice(0, 2)}`
  },
  unmask(value) {
    const digits = onlyDigits(value).replace(/^0+/, '').slice(0, 15)
    if (!digits) return ''
    const padded = digits.padStart(3, '0')
    return `${padded.slice(0, -2)}.${padded.slice(-2)}`
  },
}

export const MASKS = { cpf, cnpj, 'cpf-cnpj': cpfCnpj, phone, cep, money }
