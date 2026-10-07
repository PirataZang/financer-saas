<template>
  <div class="field">
    <label v-if="label" class="field-label" :for="inputId">{{ label }}</label>

    <div
      class="field-shell field-control"
      :class="{
        'is-focused': isFocused,
        'is-disabled': disabled,
        'has-prefix': hasPrefix,
        'has-suffix': hasSuffix,
      }"
    >
      <span v-if="hasPrefix" class="field-slot field-prefix">
        <slot name="prefix" />
      </span>

      <input
        v-if="activeMask"
        :id="inputId"
        :value="displayValue"
        class="field-input"
        :class="{ 'is-small': small }"
        :type="type"
        :name="inputName"
        :placeholder="placeholder"
        :disabled="disabled"
        :autocomplete="autocompleteValue"
        inputmode="numeric"
        v-bind="inputAttrs"
        @input="handleMaskedInput"
        @focus="isFocused = true"
        @blur="isFocused = false"
      />
      <input
        v-else
        :id="inputId"
        v-model="model"
        class="field-input"
        :class="{ 'is-small': small }"
        :type="type"
        :name="inputName"
        :placeholder="placeholder"
        :disabled="disabled"
        :autocomplete="autocompleteValue"
        v-bind="inputAttrs"
        @focus="isFocused = true"
        @blur="isFocused = false"
      />

      <span v-if="hasSuffix" class="field-slot field-suffix">
        <slot name="suffix" />
      </span>
    </div>
  </div>
</template>

<script setup>
import { computed, useAttrs, useSlots, ref, nextTick } from 'vue'
import { MASKS } from '@/utils/masks'

// class="col-6" cai no root automaticamente (inheritAttrs default)
const props = defineProps({
  width: { type: String, default: '' },
  label: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  type: { type: String, default: 'text' },
  disabled: { type: Boolean, default: false },
  small: { type: Boolean, default: false },
  name: { type: String, default: null },
  autocomplete: { type: String, default: null },
  id: { type: String, default: '' },
  /** cpf | cnpj | cpf-cnpj | phone | cep — modelValue guarda sempre o valor sem máscara */
  mask: { type: String, default: '' },
})

const model = defineModel({ default: '' })
const attrs = useAttrs()
const slots = useSlots()
const isFocused = ref(false)

const activeMask = computed(() => MASKS[props.mask] || null)
const displayValue = computed(() => (activeMask.value ? activeMask.value.format(model.value) : model.value))

const handleMaskedInput = (event) => {
  const input = event.target
  const digitsBeforeCursor = onlyDigitsBefore(input.value, input.selectionStart)

  model.value = activeMask.value.unmask(input.value)

  nextTick(() => {
    input.value = displayValue.value
    input.selectionStart = input.selectionEnd = cursorPositionAfterDigits(input.value, digitsBeforeCursor)
  })
}

function onlyDigitsBefore(value, cursor) {
  return value.slice(0, cursor).replace(/\D/g, '').length
}

function cursorPositionAfterDigits(formatted, digitCount) {
  let seen = 0
  for (let i = 0; i < formatted.length; i++) {
    if (seen === digitCount) return i
    if (/\d/.test(formatted[i])) seen++
  }
  return formatted.length
}

const hasPrefix = computed(() => !!slots.prefix)
const hasSuffix = computed(() => !!slots.suffix)

const generatedName = 'inp_' + Math.random().toString(36).slice(2, 9)
const inputName = computed(() => props.name || generatedName)
const inputId = computed(() => props.id || inputName.value)

const autocompleteValue = computed(() => {
  if (props.autocomplete) return props.autocomplete
  if (props.type === 'password') return 'new-password'
  return 'off'
})

// repassa required e demais attrs pro input, sem class/style (ficam no root)
const inputAttrs = computed(() => {
  const { class: _c, style: _s, ...rest } = attrs
  return rest
})
</script>

<style scoped lang="scss">
.field {
  display: flex;
  flex-direction: column;
  min-width: 0;
  width: 100%;
  box-sizing: border-box;
}

.field-shell {
  display: flex;
  align-items: center;
  width: 100%;
  box-sizing: border-box;
  overflow: hidden;
  min-height: 42px;
}

.field-slot {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  color: var(--color-text-muted);
  font-size: 13px;
}

.field-prefix {
  padding-left: 12px;
}

.field-suffix {
  padding-right: 12px;
}

.field-input {
  flex: 1 1 auto;
  width: 100%;
  min-width: 0;
  padding: 10px 12px;
  border: 0;
  background: transparent;
  color: var(--color-text);
  font-size: 0.875rem;
  outline: none;
  font-family: inherit;
  box-sizing: border-box;

  &::placeholder {
    color: var(--color-text-faint);
  }

  &:disabled {
    cursor: not-allowed;
  }

  &[type='color'] {
    padding: 4px;
    height: 42px;
    cursor: pointer;
  }

  &.is-small {
    padding: 6px 10px;
    font-size: 0.8125rem;
  }
}

.has-prefix .field-input {
  padding-left: 8px;
}

.has-suffix .field-input {
  padding-right: 8px;
}
</style>
