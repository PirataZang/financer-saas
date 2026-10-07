<template>
  <li class="item" :class="{ open: isOpen, nested: depth > 0 }">
    <component
      :is="item.route ? 'router-link' : 'button'"
      v-bind="bindings"
      class="link"
      :class="{ active: isActive }"
      :title="expanded ? undefined : item.name"
      @click="onClick"
    >
      <span class="icon" v-html="item.icon" />
      <span v-show="expanded" class="label">{{ item.name }}</span>
      <i
        v-if="hasChildren && expanded"
        class="fa-solid fa-chevron-right chevron"
        :class="{ rotated: isOpen }"
        aria-hidden="true"
      />
    </component>

    <ul v-if="hasChildren && expanded && isOpen" class="submenu">
      <SidebarMenuItem
        v-for="child in item.children"
        :key="keyOf(child)"
        :item="child"
        :depth="depth + 1"
        :expanded="expanded"
        :open-keys="openKeys"
        @toggle="$emit('toggle', $event)"
      />
    </ul>
  </li>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute } from 'vue-router'

const props = defineProps({
  item: { type: Object, required: true },
  depth: { type: Number, default: 0 },
  expanded: { type: Boolean, default: false },
  openKeys: { type: Object, required: true },
})

const emit = defineEmits(['toggle'])
const route = useRoute()

const keyOf = (item) => item.route || item.name
const itemKey = computed(() => keyOf(props.item))
const hasChildren = computed(
  () => Array.isArray(props.item.children) && props.item.children.length > 0,
)
const isOpen = computed(() => props.openKeys.has(itemKey.value))

const matchRoute = (item) => {
  const kids = Array.isArray(item.children) ? item.children : []
  if (kids.length) return kids.some((c) => matchRoute(c))
  if (!item.route) return false
  if (item.route === '/dashboard') return route.path === '/dashboard' || route.path === '/'
  // Listagens marcam ativo também em cadastro/edição filhos
  return route.path === item.route || route.path.startsWith(`${item.route}/`)
}

const isActive = computed(() => matchRoute(props.item))

const bindings = computed(() =>
  props.item.route
    ? { to: props.item.route, activeClass: 'active' }
    : { type: 'button' },
)

const onClick = (event) => {
  if (!hasChildren.value) return
  if (!props.item.route) event.preventDefault()
  emit('toggle', itemKey.value)
}
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.item {
  list-style: none;
}

.link {
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  min-height: 44px;
  padding: 0 12px;
  border: 0;
  border-radius: $radius-md;
  background: transparent;
  color: $color-text-secondary;
  font-size: $font-md;
  font-weight: 500;
  text-align: left;
  cursor: pointer;
  transition: background $transition-fast, color $transition-fast;

  &:hover {
    background: $sidebar-item-hover;
    color: $color-text;
  }

  &.active {
    background: $sidebar-item-active;
    color: $sidebar-item-active-text;
    font-weight: 600;
  }
}

.icon {
  flex: 0 0 24px;
  width: 24px;
  height: 24px;
  display: grid;
  place-items: center;
  font-size: 1.05rem;
  line-height: 1;
  color: inherit;

  :deep(i) {
    width: 1.1em;
    text-align: center;
  }
}

.label {
  flex: 1 1 auto;
  min-width: 0;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 0.9rem;
  letter-spacing: 0.01em;
  color: inherit;
}

.chevron {
  flex: 0 0 auto;
  font-size: 0.65rem;
  opacity: 0.65;
  transition: transform $transition-fast;

  &.rotated {
    transform: rotate(90deg);
  }
}

.submenu {
  list-style: none;
  margin: 4px 0 4px 18px;
  padding: 0 0 0 10px;
  border-left: 2px solid $color-border;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.nested .link {
  min-height: 38px;
  font-size: $font-sm;
}
</style>
