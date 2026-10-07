<template>
  <span
    class="status-badge"
    :class="[isHex ? '' : `tone-${color || 'neutral'}`, { 'is-small': small }]"
    :style="isHex ? { '--badge-fg': color, '--badge-bg': `color-mix(in srgb, ${color} 12%, white)` } : null"
  >
    <i v-if="icon?.startsWith('fa')" :class="icon" aria-hidden="true" />
    <span v-else-if="icon" class="status-badge__emoji" aria-hidden="true">{{ icon }}</span>
    <span>{{ label }}</span>
  </span>
</template>

<script setup>
import { computed } from 'vue'

// Pill colorida para valores de enum. `color` é um tom do design system
// (info | success | warning | danger | neutral) — vem do enum no backend — ou um hex escolhido
// pelo usuário (ex.: cor da categoria financeira). `icon`: classe Font Awesome ou um emoji.
const props = defineProps({
  label: { type: String, required: true },
  color: { type: String, default: 'neutral' },
  icon: { type: String, default: '' },
  small: { type: Boolean, default: false },
})

const isHex = computed(() => props.color?.startsWith('#'))
</script>

<style scoped lang="scss">
.status-badge {
  --badge-fg: var(--color-text-secondary);
  --badge-bg: var(--color-bg-muted);

  display: inline-flex;
  align-items: center;
  gap: 6px;
  max-width: 100%;
  padding: 4px 10px;
  border-radius: 999px;
  background: var(--badge-bg);
  color: var(--badge-fg);
  font-size: 0.78rem;
  font-weight: 600;
  line-height: 1.2;
  white-space: nowrap;
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--badge-fg) 18%, transparent);

  i {
    font-size: 0.72rem;
  }

  .status-badge__emoji {
    font-size: 0.85rem;
    line-height: 1;
  }

  span {
    overflow: hidden;
    text-overflow: ellipsis;
  }

  &.is-small {
    padding: 2px 8px;
    font-size: 0.72rem;
  }
}

.tone-info {
  --badge-fg: var(--color-info);
  --badge-bg: var(--color-info-soft);
}

.tone-success {
  --badge-fg: var(--color-success);
  --badge-bg: var(--color-success-soft);
}

.tone-warning {
  --badge-fg: var(--color-warning);
  --badge-bg: var(--color-warning-soft);
}

.tone-danger {
  --badge-fg: var(--color-danger);
  --badge-bg: var(--color-danger-soft);
}

.tone-neutral {
  --badge-fg: var(--color-text-muted);
  --badge-bg: var(--color-bg-muted);
}
</style>
