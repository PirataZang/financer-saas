<template>
  <component
    :is="tag"
    v-bind="bindAttrs"
    class="btn"
    :class="[
      `btn--${resolvedVariant}`,
      `btn--${size}`,
      {
        'is-disabled': disabled,
        'is-block': block,
        'is-icon': iconOnly,
      },
    ]"
    :disabled="isNativeButton ? disabled : undefined"
    :aria-disabled="disabled || undefined"
    @click="onClick"
  >
    <i v-if="icon" :class="icon" aria-hidden="true" />
    <span v-if="label || $slots.default" class="btn__label">
      <slot>{{ label }}</slot>
    </span>
  </component>
</template>

<script setup>
import { computed, useAttrs } from 'vue'

defineOptions({ inheritAttrs: false })

const props = defineProps({
  label: { type: String, default: '' },
  /** primary | secondary | danger | ghost | success | edit | create */
  variant: { type: String, default: 'primary' },
  /** sm | md | lg */
  size: { type: String, default: 'md' },
  /** button | submit | reset */
  nativeType: { type: String, default: 'button' },
  /** button | link | route */
  type: { type: String, default: 'button' },
  href: { type: [String, Object], default: '' },
  icon: { type: String, default: '' },
  iconOnly: { type: Boolean, default: false },
  block: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  /** @deprecated use variant */
  color: { type: String, default: '' },
})

const attrs = useAttrs()

const resolvedVariant = computed(() => {
  if (props.color === '#64748b' || props.color === 'secondary') return 'secondary'
  if (props.color && props.color !== '#ff4d4d') return props.variant || 'primary'
  return props.variant || 'primary'
})

const tag = computed(() => {
  if (props.type === 'link') return 'a'
  if (props.type === 'route') return 'router-link'
  return 'button'
})

const isNativeButton = computed(() => tag.value === 'button')

const bindAttrs = computed(() => {
  const base = { ...attrs }

  if (tag.value === 'button') {
    return { ...base, type: props.nativeType }
  }

  if (tag.value === 'a') {
    return { ...base, href: props.href || '#' }
  }

  return { ...base, to: props.href || '/' }
})

const onClick = (event) => {
  if (props.disabled) {
    event.preventDefault()
    event.stopPropagation()
  }
}
</script>

<style scoped lang="scss">
.btn {
  --btn-bg: var(--color-primary);
  --btn-bg-hover: var(--color-primary-hover);
  --btn-color: #ffffff;
  --btn-border: transparent;
  --btn-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);

  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  height: 40px;
  padding: 0 16px;
  border: 1px solid var(--btn-border);
  border-radius: 10px;
  background: var(--btn-bg);
  color: var(--btn-color);
  font-family: var(--font-sans);
  font-size: 0.875rem;
  font-weight: 600;
  line-height: 1;
  text-decoration: none;
  white-space: nowrap;
  cursor: pointer;
  box-shadow: var(--btn-shadow);
  transition:
    background 0.15s ease,
    color 0.15s ease,
    border-color 0.15s ease,
    box-shadow 0.15s ease,
    transform 0.12s ease,
    opacity 0.15s ease;

  i {
    font-size: 0.9em;
  }

  &:hover:not(.is-disabled):not(:disabled) {
    background: var(--btn-bg-hover);
    transform: translateY(-1px);
  }

  &:active:not(.is-disabled):not(:disabled) {
    transform: translateY(0);
  }

  &:focus-visible {
    outline: 3px solid var(--color-primary-soft);
    outline-offset: 2px;
  }

  &.is-disabled,
  &:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    box-shadow: none;
    transform: none;
    pointer-events: none;
  }

  &.is-block {
    width: 100%;
  }

  &.is-icon {
    width: 40px;
    padding: 0;
  }
}

.btn--sm {
  height: 34px;
  padding: 0 12px;
  font-size: 0.8125rem;
  border-radius: 8px;

  &.is-icon {
    width: 34px;
  }
}

.btn--lg {
  height: 46px;
  padding: 0 20px;
  font-size: 0.9375rem;
}

.btn--primary {
  --btn-bg: var(--color-primary);
  --btn-bg-hover: var(--color-primary-hover);
  --btn-color: #ffffff;
  --btn-border: transparent;
}

/* Incluir = azul */
.btn--create {
  --btn-bg: var(--color-info);
  --btn-bg-hover: #1d4ed8;
  --btn-color: #ffffff;
  --btn-border: transparent;
}

/* Alterar = verde */
.btn--edit,
.btn--success {
  --btn-bg: var(--color-success);
  --btn-bg-hover: #047857;
  --btn-color: #ffffff;
  --btn-border: transparent;
}

/* Excluir = vermelho */
.btn--danger {
  --btn-bg: var(--color-danger);
  --btn-bg-hover: #b91c1c;
  --btn-color: #ffffff;
  --btn-border: transparent;
}

.btn--secondary {
  --btn-bg: #ffffff;
  --btn-bg-hover: var(--color-bg-muted);
  --btn-color: var(--color-text-secondary);
  --btn-border: var(--color-border);
  --btn-shadow: none;
}

.btn--ghost {
  --btn-bg: transparent;
  --btn-bg-hover: var(--color-bg-muted);
  --btn-color: var(--color-text-secondary);
  --btn-border: transparent;
  --btn-shadow: none;
}
</style>
