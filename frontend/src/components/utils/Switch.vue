<template>
  <div class="switch-container">
    <span v-if="label" class="switch-label">
      {{ label }}
      <InfoTip v-if="tip" :title="label" :text="tip" />
    </span>

    <div
      class="switch-wrapper"
      :class="{ disabled }"
      role="switch"
      tabindex="0"
      :aria-checked="modelValue"
      :aria-label="ariaLabel || label || undefined"
      :aria-disabled="disabled || undefined"
      @click="toggle"
      @keydown.enter.space.prevent="toggle"
    >
      <div class="switch" :class="{ 'is-checked': modelValue }">
        <div class="switch-handle"></div>
      </div>
      <span v-if="textLabel" class="switch-text">{{ textLabel }}</span>
    </div>
  </div>
</template>

<script setup>
import InfoTip from '@/components/ui/InfoTip.vue'

const props = defineProps({
  /** Help shown by an ⓘ next to the label (components/ui/InfoTip.vue): what the field is and what it does. */
  tip: { type: String, default: '' },
  modelValue: {
    type: Boolean,
    default: false,
  },
  disabled: {
    type: Boolean,
    default: false,
  },
  label: {
    type: String,
    default: '',
  },
  textLabel: {
    type: String,
    default: '',
  },
  ariaLabel: {
    type: String,
    default: '',
  },
})

const emit = defineEmits(['update:modelValue', 'change'])

const toggle = () => {
  if (props.disabled) return
  const newValue = !props.modelValue
  emit('update:modelValue', newValue)
  emit('change', newValue)
}
</script>

<style scoped lang="scss">
.switch-container {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-family: var(--font-sans);
}

.switch-label {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: var(--text-secondary);
}

.switch-wrapper {
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  user-select: none;

  &.disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  &:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 3px;
    border-radius: 999px;
  }
}

.switch-text {
  font-size: 14px;
  color: var(--text-secondary);
}

.switch {
  position: relative;
  width: 35px;
  height: 20px;
  background-color: var(--text-muted);
  border-radius: 999px;
  transition: background-color 0.3s ease;
  display: flex;
  align-items: center;
  padding: 2px;

  &.is-checked {
    background-color: var(--primary);

    .switch-handle {
      transform: translateX(15px);
    }
  }
}

.switch-handle {
  width: 15px;
  height: 15px;
  background-color: #ffffff;
  border-radius: 50%;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.15);
  transition: transform 0.25s cubic-bezier(0.4, 0, 0.2, 1);
}
</style>
