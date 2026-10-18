<template>
  <div class="tabs" role="tablist" :aria-label="label" @keydown="onKeydown">
    <button
      v-for="tab in tabs"
      :id="`tab-${tab.value}`"
      :key="tab.value"
      type="button"
      role="tab"
      class="tab"
      :class="{ active: tab.value === model }"
      :aria-selected="tab.value === model"
      :aria-controls="`tabpanel-${tab.value}`"
      :tabindex="tab.value === model ? 0 : -1"
      @click="model = tab.value"
    >
      <i v-if="tab.icon" :class="tab.icon" aria-hidden="true" />
      {{ tab.label }}
      <span v-if="tab.count != null" class="tab__count">{{ tab.count }}</span>
    </button>
  </div>
</template>

<script setup>
// Abas: tabs = [{ value, label, icon?, count? }], v-model = aba ativa. O painel de cada aba usa
// id="tabpanel-<value>" role="tabpanel" aria-labelledby="tab-<value>". Setas/Home/End trocam de aba.
const props = defineProps({
  tabs: { type: Array, required: true },
  label: { type: String, default: '' },
})

const model = defineModel({ type: String, required: true })

const onKeydown = (event) => {
  const index = props.tabs.findIndex((tab) => tab.value === model.value)
  const last = props.tabs.length - 1
  const next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: last }[event.key]
  if (next === undefined) return
  event.preventDefault()
  model.value = props.tabs[(next + props.tabs.length) % props.tabs.length].value
  document.getElementById(`tab-${model.value}`)?.focus()
}
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.tabs {
  display: flex;
  gap: 4px;
  margin-bottom: 20px;
  border-bottom: 1px solid $color-border;
  overflow-x: auto;
  scrollbar-width: none;
}

.tab {
  flex: 0 0 auto;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border: 0;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  background: transparent;
  color: $color-text-muted;
  font: inherit;
  font-size: $font-md;
  font-weight: 600;
  white-space: nowrap;
  cursor: pointer;
  transition: color $transition-fast, border-color $transition-fast;

  &:hover {
    color: $color-text;
  }

  &.active {
    color: $color-primary;
    border-bottom-color: $color-primary;
  }

  &:focus-visible {
    outline: 2px solid $color-primary;
    outline-offset: -2px;
    border-radius: $radius-sm $radius-sm 0 0;
  }
}

.tab__count {
  min-width: 20px;
  padding: 1px 6px;
  border-radius: $radius-full;
  background: $color-primary-soft;
  color: $color-primary;
  font-size: 0.7rem;
  text-align: center;
}
</style>
