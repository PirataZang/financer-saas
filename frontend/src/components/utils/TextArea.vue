<template>
  <div class="field">
    <label v-if="label" class="field-label" :for="inputId">{{ label }}</label>

    <div class="field-shell field-control" :class="{ 'is-disabled': disabled }">
      <textarea
        :id="inputId"
        v-model="model"
        class="field-input"
        :class="{ 'is-small': small }"
        :name="inputName"
        :placeholder="placeholder"
        :disabled="disabled"
        :rows="rows"
        v-bind="inputAttrs"
      />
    </div>
  </div>
</template>

<script setup>
import { computed, useAttrs } from 'vue'

const props = defineProps({
  width: { type: String, default: '' },
  label: { type: String, default: '' },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  small: { type: Boolean, default: false },
  name: { type: String, default: null },
  rows: { type: Number, default: 4 },
  id: { type: String, default: '' },
})

const model = defineModel({ default: '' })
const attrs = useAttrs()

const generatedName = 'txt_' + Math.random().toString(36).slice(2, 9)
const inputName = computed(() => props.name || generatedName)
const inputId = computed(() => props.id || inputName.value)

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
  align-items: stretch;
  width: 100%;
  min-height: auto;
  padding: 0;
  box-sizing: border-box;
}

.field-input {
  width: 100%;
  min-width: 0;
  padding: 10px 12px;
  border: 0;
  background: transparent;
  color: var(--color-text);
  font-size: 0.875rem;
  outline: none;
  resize: vertical;
  font-family: inherit;
  line-height: 1.45;
  box-sizing: border-box;

  &::placeholder {
    color: var(--color-text-faint);
  }

  &:disabled {
    cursor: not-allowed;
  }

  &.is-small {
    padding: 6px 10px;
    font-size: 0.8125rem;
  }
}
</style>
