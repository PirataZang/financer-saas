<template>
  <div ref="root" class="global-search">
    <label class="search">
      <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
      <input
        ref="input"
        v-model="query"
        type="search"
        placeholder="Buscar recursos..."
        role="combobox"
        aria-autocomplete="list"
        aria-controls="global-search-results"
        :aria-expanded="open"
        :aria-activedescendant="open && results.length ? `global-search-${active}` : undefined"
        @focus="open = true"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="go(results[active])"
        @keydown.esc="close"
      />
      <kbd class="search__hint" aria-hidden="true">Ctrl K</kbd>
    </label>

    <ul v-if="open && query.trim()" id="global-search-results" class="results" role="listbox">
      <li
        v-for="(item, index) in results"
        :id="`global-search-${index}`"
        :key="item.route"
        role="option"
        class="result"
        :class="{ active: index === active }"
        :aria-selected="index === active"
        @mousedown.prevent="go(item)"
        @mousemove="active = index"
      >
        <span class="result__icon"><i :class="item.icon" aria-hidden="true" /></span>
        <span class="result__text">
          <strong>{{ item.label }}</strong>
          <small>{{ item.description }}</small>
        </span>
        <span v-if="item.module" class="result__module">{{ item.module }}</span>
      </li>
      <li v-if="!results.length" class="empty">Nenhum recurso encontrado para "{{ query }}".</li>
    </ul>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { menuItems, searchMenu } from '@/permissions'

// Searches only menu entries the current user may open (same rule as the route guard);
// navigation still goes through the guard and the API still checks every request.
const router = useRouter()
const auth = useAuthStore()

const root = ref(null)
const input = ref(null)
const query = ref('')
const open = ref(false)
const active = ref(0)

const results = computed(() => searchMenu(menuItems(router.getRoutes(), auth.user), query.value))

watch(query, () => {
  active.value = 0
  open.value = true
})

const move = (step) => {
  if (!results.value.length) return
  active.value = (active.value + step + results.value.length) % results.value.length
}

const close = () => {
  open.value = false
}

const go = (item) => {
  if (!item) return
  query.value = ''
  close()
  input.value?.blur()
  router.push(item.route)
}

const onClickOutside = (event) => {
  if (!root.value?.contains(event.target)) close()
}

const onShortcut = (event) => {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    input.value?.focus()
  }
}

onMounted(() => {
  document.addEventListener('mousedown', onClickOutside)
  document.addEventListener('keydown', onShortcut)
})

onUnmounted(() => {
  document.removeEventListener('mousedown', onClickOutside)
  document.removeEventListener('keydown', onShortcut)
})
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.global-search {
  position: relative;
}

.search {
  display: flex;
  align-items: center;
  gap: 10px;
  width: min(340px, 40vw);
  height: 40px;
  padding: 0 14px;
  border: 1px solid $color-border;
  border-radius: $radius-full;
  background: $color-bg-muted;
  color: $color-text-muted;

  &:focus-within {
    background: #ffffff;
    border-color: $color-primary;
    box-shadow: 0 0 0 3px $color-primary-soft;
    color: $color-text-secondary;
  }

  input {
    width: 100%;
    border: 0;
    outline: 0;
    background: transparent;
    color: $color-text;

    &::placeholder {
      color: $color-text-faint;
    }
  }
}

.search__hint {
  flex: 0 0 auto;
  padding: 2px 6px;
  border: 1px solid $color-border;
  border-radius: $radius-sm;
  background: #ffffff;
  color: $color-text-faint;
  font-family: inherit;
  font-size: 0.7rem;
}

.results {
  position: absolute;
  top: calc(100% + 8px);
  left: 0;
  width: max(100%, 380px);
  max-height: 60vh;
  overflow-y: auto;
  margin: 0;
  padding: 6px;
  list-style: none;
  background: #ffffff;
  border: 1px solid $color-border;
  border-radius: $radius-lg;
  box-shadow: $shadow-lg;
  z-index: $z-topbar + 1;
}

.result {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px;
  border-radius: $radius-md;
  cursor: pointer;

  &.active {
    background: $color-primary-soft;
  }
}

.result__icon {
  flex: 0 0 34px;
  height: 34px;
  display: grid;
  place-items: center;
  border-radius: $radius-md;
  background: $color-bg-muted;
  color: $color-primary;

  .active & {
    background: #ffffff;
  }
}

.result__text {
  flex: 1 1 auto;
  min-width: 0;
  display: flex;
  flex-direction: column;
  line-height: 1.25;

  strong {
    font-size: $font-md;
    font-weight: 600;
    color: $color-text;
  }

  small {
    font-size: $font-xs;
    color: $color-text-muted;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
}

.result__module {
  flex: 0 0 auto;
  padding: 2px 8px;
  border-radius: $radius-full;
  background: $color-bg-muted;
  color: $color-text-muted;
  font-size: 0.7rem;
  font-weight: 600;
}

.empty {
  padding: 14px 10px;
  color: $color-text-muted;
  font-size: $font-sm;
}

@media (max-width: 640px) {
  .global-search {
    display: none;
  }
}
</style>
