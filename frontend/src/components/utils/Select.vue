<template>
  <div ref="root" class="field" :class="[$attrs.class, { 'is-open': open }]" :style="$attrs.style">
    <label v-if="label" class="field-label" :for="selectId">{{ label }}</label>

    <button
      :id="selectId"
      ref="trigger"
      type="button"
      class="field-control select-trigger"
      :class="{ 'is-small': small, 'is-disabled': disabled, 'is-focused': open, 'has-clear': clearable && hasValue }"
      :disabled="disabled"
      aria-haspopup="listbox"
      :aria-expanded="open"
      :aria-controls="`${selectId}-list`"
      :aria-activedescendant="open && activeIndex >= 0 ? optionId(activeIndex) : undefined"
      v-bind="triggerAttrs"
      @click="toggle"
      @keydown="onKeydown"
    >
      <span class="select-value">
        <template v-if="multiple && selectedOptions.length">
          <StatusBadge
            v-for="option in selectedOptions.slice(0, 2)"
            :key="option.value"
            :label="option.label"
            :color="option.color || 'neutral'"
            :icon="option.icon"
            small
          />
          <span v-if="selectedOptions.length > 2" class="select-more">+{{ selectedOptions.length - 2 }}</span>
        </template>
        <StatusBadge
          v-else-if="selected?.color"
          :label="selected.label"
          :color="selected.color"
          :icon="selected.icon"
          small
        />
        <template v-else-if="selected">
          <i v-if="selected.icon" :class="selected.icon" class="select-icon" aria-hidden="true" />
          <span class="select-text">{{ selected.label }}</span>
        </template>
        <span v-else class="select-placeholder">{{ placeholder || 'Selecione...' }}</span>
      </span>
      <i v-if="loading" class="fa-solid fa-spinner fa-spin select-chevron" aria-label="Carregando" />
      <i v-else class="fa-solid fa-chevron-down select-chevron" aria-hidden="true" />
    </button>

    <button
      v-if="clearable && hasValue && !disabled"
      type="button"
      class="select-clear"
      aria-label="Limpar seleção"
      @click="clear"
    >
      <i class="fa-solid fa-xmark" aria-hidden="true" />
    </button>

    <Transition name="select-pop">
      <ul
        v-if="open"
        :id="`${selectId}-list`"
        class="select-menu"
        :class="{ 'opens-up': opensUp }"
        role="listbox"
        :aria-labelledby="selectId"
        :aria-multiselectable="multiple || undefined"
      >
        <li v-if="searchable" class="select-search" role="presentation">
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
          <input
            ref="searchInput"
            v-model="query"
            type="search"
            placeholder="Buscar..."
            aria-label="Buscar opção"
            autocomplete="off"
            @keydown="onKeydown"
          />
        </li>
        <li
          v-for="(option, index) in items"
          :id="optionId(index)"
          :key="option.value"
          role="option"
          class="select-option"
          :class="{ active: index === activeIndex, selected: isSelected(option) }"
          :aria-selected="isSelected(option)"
          @mousedown.prevent="choose(option)"
          @mousemove="activeIndex = index"
        >
          <StatusBadge v-if="option.color" :label="option.label" :color="option.color" :icon="option.icon" />
          <span v-else class="select-option__label" :class="{ 'is-placeholder': option.value === '' }">
            <i v-if="option.icon" :class="option.icon" class="select-icon" aria-hidden="true" />
            {{ option.label }}
          </span>
          <i v-if="isSelected(option)" class="fa-solid fa-check select-check" aria-hidden="true" />
        </li>
        <li v-if="!items.length" class="select-empty">
          {{ loading ? 'Carregando...' : query ? 'Nenhum resultado' : 'Nenhuma opção disponível' }}
        </li>
      </ul>
    </Transition>
  </div>
</template>

<script setup>
import { computed, nextTick, onUnmounted, ref, useAttrs, watch } from 'vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { normalize } from '@/permissions'

// Base de toda lista de seleção. Mesmo visual de campo do Input (field-label + field-control).
// options = [{ value, label, icon?, color? }] — com `color` (tom do design system ou hex) a opção
// vira StatusBadge. `placeholder` vira a opção vazia (ex.: "Todos os status" num filtro).
// searchable (busca sem acento) · multiple (v-model = array) · clearable · loading · disabled.
// Seletores de entidade (ex. finance/CategorySelect) só carregam as opções e herdam o resto via $attrs.
const props = defineProps({
  label: { type: String, default: '' },
  options: { type: Array, default: () => [] },
  placeholder: { type: String, default: '' },
  disabled: { type: Boolean, default: false },
  small: { type: Boolean, default: false },
  id: { type: String, default: '' },
  searchable: { type: Boolean, default: false },
  multiple: { type: Boolean, default: false },
  clearable: { type: Boolean, default: false },
  loading: { type: Boolean, default: false },
})

const model = defineModel({ default: '' })
const emit = defineEmits(['change'])
const attrs = useAttrs()

defineOptions({ inheritAttrs: false })

const root = ref(null)
const trigger = ref(null)
const searchInput = ref(null)
const query = ref('')
const open = ref(false)
const opensUp = ref(false)
const activeIndex = ref(-1)
const selectId = props.id || 'sel_' + Math.random().toString(36).slice(2, 9)
let typeahead = ''
let typeaheadTimer = null

// class/style ficam no root (ex.: col-6); aria-label etc. vão para o botão.
const triggerAttrs = computed(() => {
  const { class: _c, style: _s, ...rest } = attrs
  return rest
})

const items = computed(() => {
  const words = normalize(query.value)
  const options = words ? props.options.filter((option) => normalize(option.label).includes(words)) : props.options
  return props.placeholder && !props.multiple && !words ? [{ value: '', label: props.placeholder }, ...options] : options
})
const selectedValues = computed(() => (props.multiple ? (Array.isArray(model.value) ? model.value : []) : [model.value]))
const isSelected = (option) => selectedValues.value.includes(option.value)
const selected = computed(() => props.options.find((option) => option.value === model.value) || null)
const selectedOptions = computed(() => props.options.filter(isSelected))
const hasValue = computed(() => (props.multiple ? selectedValues.value.length > 0 : model.value !== '' && model.value != null))
const optionId = (index) => `${selectId}-opt-${index}`

const openMenu = async () => {
  if (props.disabled || open.value) return
  const rect = trigger.value.getBoundingClientRect()
  opensUp.value = window.innerHeight - rect.bottom < 280 && rect.top > 280
  activeIndex.value = Math.max(0, items.value.findIndex(isSelected))
  open.value = true
  await nextTick()
  searchInput.value?.focus()
  scrollActiveIntoView()
}

const close = () => {
  open.value = false
  query.value = ''
}

const toggle = () => (open.value ? close() : openMenu())

const choose = (option) => {
  if (props.multiple) {
    // Lista fica aberta para marcar várias.
    const next = isSelected(option)
      ? selectedValues.value.filter((value) => value !== option.value)
      : [...selectedValues.value, option.value]
    model.value = next
    emit('change', next)
    return
  }
  if (option.value !== model.value) {
    model.value = option.value
    emit('change', option.value)
  }
  close()
  trigger.value?.focus()
}

const clear = () => {
  const empty = props.multiple ? [] : ''
  model.value = empty
  emit('change', empty)
  trigger.value?.focus()
}

watch(query, () => {
  activeIndex.value = 0
})

const move = async (step) => {
  if (!items.value.length) return
  activeIndex.value = (activeIndex.value + step + items.value.length) % items.value.length
  await nextTick()
  scrollActiveIntoView()
}

const scrollActiveIntoView = () => {
  document.getElementById(optionId(activeIndex.value))?.scrollIntoView({ block: 'nearest' })
}

// Digitar letras pula para a primeira opção que começa com elas.
const jumpTo = (char) => {
  clearTimeout(typeaheadTimer)
  typeahead += char.toLowerCase()
  typeaheadTimer = setTimeout(() => (typeahead = ''), 600)
  const index = items.value.findIndex((option) => option.label.toLowerCase().startsWith(typeahead))
  if (index >= 0) {
    activeIndex.value = index
    nextTick(scrollActiveIntoView)
  }
}

const onKeydown = (event) => {
  switch (event.key) {
    case 'ArrowDown':
    case 'ArrowUp':
      event.preventDefault()
      if (!open.value) openMenu()
      else move(event.key === 'ArrowDown' ? 1 : -1)
      break
    case ' ':
      if (props.searchable && open.value) break // espaço digitado na busca
    // falls through
    case 'Enter':
      event.preventDefault()
      if (open.value && activeIndex.value >= 0) choose(items.value[activeIndex.value])
      else openMenu()
      break
    case 'Escape':
      if (open.value) {
        event.preventDefault()
        close()
        trigger.value?.focus()
      }
      break
    case 'Tab':
      close()
      break
    case 'Home':
    case 'End':
      if (open.value && !props.searchable) {
        event.preventDefault()
        activeIndex.value = event.key === 'Home' ? 0 : items.value.length - 1
        nextTick(scrollActiveIntoView)
      }
      break
    default:
      if (event.key.length === 1 && /\S/.test(event.key) && !open.value) {
        if (props.searchable) {
          // Começa a busca já com a tecla digitada.
          event.preventDefault()
          query.value = event.key
          openMenu()
        } else {
          openMenu()
          jumpTo(event.key)
        }
      } else if (open.value && !props.searchable && event.key.length === 1 && /\S/.test(event.key)) {
        jumpTo(event.key)
      }
  }
}

const onClickOutside = (event) => {
  if (!root.value?.contains(event.target)) close()
}

watch(open, (isOpen) => {
  if (isOpen) document.addEventListener('mousedown', onClickOutside)
  else document.removeEventListener('mousedown', onClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('mousedown', onClickOutside)
  clearTimeout(typeaheadTimer)
})
</script>

<style scoped lang="scss">
@use '@/styles/colors' as *;

.field {
  position: relative;
  display: flex;
  flex-direction: column;
  min-width: 0;
  width: 100%;
}

.select-trigger {
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 0 12px;
  font-family: inherit;
  font-size: 0.875rem;
  text-align: left;
  cursor: pointer;

  &.is-small {
    min-height: 34px;
    padding: 0 10px;
    font-size: 0.8125rem;
  }

  &.is-disabled {
    cursor: not-allowed;
  }
}

.has-clear .select-value {
  margin-right: 22px;
}

.select-value {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.select-text {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: $color-text;
}

.select-placeholder {
  color: $color-text-faint;
}

.select-icon {
  width: 1em;
  color: $color-text-muted;
  text-align: center;
}

.select-chevron {
  flex: 0 0 auto;
  font-size: 0.7rem;
  color: $color-text-muted;
  transition: transform $transition-fast;

  .is-open & {
    transform: rotate(180deg);
    color: $color-primary;
  }
}

.select-menu {
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  right: 0;
  z-index: $z-dropdown;
  max-height: 264px;
  overflow-y: auto;
  margin: 0;
  padding: 6px;
  list-style: none;
  background: #ffffff;
  border: 1px solid $color-border;
  border-radius: $radius-lg;
  box-shadow: $shadow-lg;
  transform-origin: top center;

  &.opens-up {
    top: auto;
    bottom: calc(100% + 6px);
    transform-origin: bottom center;
  }
}

.select-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  min-height: 38px;
  padding: 6px 10px;
  border-radius: $radius-md;
  color: $color-text-secondary;
  font-size: $font-md;
  cursor: pointer;
  transition: background $transition-fast;

  &.active {
    background: $color-bg-muted;
  }

  &.selected {
    color: $color-text;
    font-weight: 600;
  }
}

.select-option__label {
  display: flex;
  align-items: center;
  gap: 8px;

  &.is-placeholder {
    color: $color-text-muted;
    font-weight: 500;
  }
}

.select-check {
  flex: 0 0 auto;
  font-size: 0.75rem;
  color: $color-primary;
}

.select-more {
  flex: 0 0 auto;
  font-size: $font-xs;
  font-weight: 600;
  color: $color-text-muted;
}

.select-clear {
  position: absolute;
  right: 32px;
  bottom: 0;
  height: 42px;
  display: grid;
  place-items: center;
  padding: 0 6px;
  border: 0;
  background: transparent;
  color: $color-text-faint;
  cursor: pointer;

  &:hover {
    color: $color-text;
  }
}

.select-search {
  position: sticky;
  top: -6px;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 8px;
  margin: -6px -6px 6px;
  padding: 8px 12px;
  background: #ffffff;
  border-bottom: 1px solid $color-border;
  color: $color-text-muted;
  font-size: $font-sm;

  input {
    flex: 1;
    min-width: 0;
    border: 0;
    outline: none;
    background: transparent;
    color: $color-text;
    font-family: inherit;
    font-size: $font-md;
  }
}

.select-empty {
  padding: 10px;
  color: $color-text-muted;
  font-size: $font-sm;
}

.select-pop-enter-active,
.select-pop-leave-active {
  transition: opacity 0.14s $ease, transform 0.14s $ease;
}

.select-pop-enter-from,
.select-pop-leave-to {
  opacity: 0;
  transform: translateY(-4px) scale(0.98);
}

.opens-up.select-pop-enter-from,
.opens-up.select-pop-leave-to {
  transform: translateY(4px) scale(0.98);
}
</style>
