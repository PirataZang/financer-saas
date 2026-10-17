<template>
  <li
    class="item"
    :class="[{ open: isOpen, nested: depth > 0, 'is-group': hasChildren && expanded && isOpen && depth === 0 }, item.tone && `tone-${item.tone}`]"
    @mouseenter="showFlyout"
    @mouseleave="hideFlyout"
  >
    <component
      :is="item.route ? 'router-link' : 'button'"
      ref="link"
      v-bind="bindings"
      class="link"
      :class="{ active: isActive }"
      :aria-label="expanded ? undefined : item.name"
      :aria-expanded="hasChildren ? (expanded ? isOpen : flyoutOpen) : undefined"
      @click="onClick"
      @focus="!hasChildren && showFlyout()"
      @blur="!hasChildren && hideFlyout()"
      @keydown.esc="closeFlyout(true)"
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

    <!-- Compact menu: the name (tooltip) or, for a group, its submenu, floating beside the rail.
         Teleported so the panel's overflow doesn't clip it. -->
    <Teleport to="body">
      <Transition name="flyout">
        <div
          v-if="flyoutVisible"
          class="flyout"
          :class="[{ 'flyout--menu': hasChildren }, item.tone && `tone-${item.tone}`]"
          :style="flyoutStyle"
          :role="hasChildren ? 'menu' : 'tooltip'"
          @mouseenter="showFlyout"
          @mouseleave="hideFlyout"
          @keydown.esc="closeFlyout(true)"
        >
          <span class="flyout__title">{{ item.name }}</span>
          <ul v-if="hasChildren" ref="flyoutList" class="flyout__list">
            <li v-for="child in item.children" :key="keyOf(child)" role="none">
              <router-link
                :to="child.route"
                class="flyout__link"
                :class="{ active: matchRoute(child) }"
                role="menuitem"
                @click="closeFlyout()"
              >
                <span class="icon" v-html="child.icon" />
                <span>{{ child.name }}</span>
              </router-link>
            </li>
          </ul>
        </div>
      </Transition>
    </Teleport>
  </li>
</template>

<script setup>
import { computed, nextTick, onUnmounted, ref } from 'vue'
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
  if (props.expanded) emit('toggle', itemKey.value)
  else toggleFlyout()
}

// --- Flyout (compact menu only) ---
const link = ref(null)
const flyoutList = ref(null)
const flyoutOpen = ref(false)
const flyoutStyle = ref({})
let hideTimer = null

const flyoutVisible = computed(() => flyoutOpen.value && !props.expanded && props.depth === 0)

const showFlyout = () => {
  if (props.expanded || props.depth > 0) return
  clearTimeout(hideTimer)
  const rect = (link.value?.$el ?? link.value).getBoundingClientRect()
  flyoutStyle.value = { top: `${rect.top}px`, left: `${rect.right + 12}px`, minHeight: `${rect.height}px` }
  flyoutOpen.value = true
}

// Small delay so the pointer can travel from the rail into the submenu.
const hideFlyout = () => {
  clearTimeout(hideTimer)
  hideTimer = setTimeout(() => (flyoutOpen.value = false), 140)
}

const closeFlyout = (refocus = false) => {
  clearTimeout(hideTimer)
  flyoutOpen.value = false
  if (refocus) (link.value?.$el ?? link.value)?.focus()
}

// Click/Enter on a group: open and move focus into its submenu (keyboard and touch).
const toggleFlyout = async () => {
  if (flyoutOpen.value) return closeFlyout()
  showFlyout()
  await nextTick()
  flyoutList.value?.querySelector('a')?.focus()
}

onUnmounted(() => clearTimeout(hideTimer))
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

  &:focus-visible {
    outline: 2px solid $color-primary;
    outline-offset: -2px;
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

// Restricted area (masters): amber, so it never reads as a regular module.
.tone-restricted > .link {
  color: $color-warning-text;

  .icon {
    color: $color-warning;
  }

  &:hover {
    background: $color-warning-soft;
  }
}

// Open group (expanded menu): header and submenu share ONE container, so the children read
// as inside the group. Children are indented to the header's label and hang off a 1px guide.
.item.is-group {
  --group-bg: #{$color-bg-hover};
  --group-line: #{$color-border};

  margin: 4px 0;
  padding: 4px;
  border: 1px solid var(--group-line);
  border-radius: $radius-lg;
  background: var(--group-bg);

  &.tone-restricted {
    --group-bg: #{$color-warning-soft};
    --group-line: #{rgba($color-warning, 0.3)};
  }

  // The header names the group; the current page is marked on the child, not here.
  > .link,
  > .link.active {
    min-height: 40px;
    padding: 0 8px;
    background: transparent;
    font-weight: 600;
  }

  &:not([class*='tone-']) > .link.active {
    color: $color-text;
  }
}

.submenu {
  position: relative;
  list-style: none;
  margin: 2px 0 2px;
  padding: 0 0 0 28px;
  display: flex;
  flex-direction: column;
  gap: 2px;

  // Guide under the header icon (8px padding + half of its 24px column).
  &::before {
    content: '';
    position: absolute;
    top: 2px;
    bottom: 8px;
    left: 19px;
    width: 1px;
    background: var(--group-line);
  }
}

.flyout {
  position: fixed;
  z-index: $z-dropdown;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 8px 12px;
  border: 1px solid $color-border;
  border-radius: $radius-md;
  background: #ffffff;
  box-shadow: $shadow-lg;
  pointer-events: none;

  &.flyout--menu {
    justify-content: flex-start;
    min-width: 200px;
    padding: 8px;
    pointer-events: auto;
  }
}

.flyout__title {
  font-size: $font-sm;
  font-weight: 600;
  color: $color-text;
  white-space: nowrap;

  .flyout--menu & {
    padding: 4px 8px 8px;
    font-size: $font-xs;
    color: $color-text-muted;
  }

  .tone-restricted & {
    color: $color-warning-text;
  }
}

.flyout__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.flyout__link {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 38px;
  padding: 0 10px;
  border-radius: $radius-md;
  color: $color-text-secondary;
  font-size: $font-sm;
  font-weight: 500;
  text-decoration: none;
  transition: background $transition-fast, color $transition-fast;

  &:hover {
    background: $sidebar-item-hover;
    color: $color-text;
  }

  &:focus-visible {
    outline: 2px solid $color-primary;
    outline-offset: -2px;
  }

  &.active {
    background: $sidebar-item-active;
    color: $sidebar-item-active-text;
    font-weight: 600;
  }
}

.flyout-enter-active,
.flyout-leave-active {
  transition: opacity 0.12s $ease, transform 0.12s $ease;
}

.flyout-enter-from,
.flyout-leave-to {
  opacity: 0;
  transform: translateX(-4px);
}

.nested .link {
  min-height: 36px;
  padding: 0 10px;
  gap: 10px;
  color: $color-text-secondary;
  font-size: $font-sm;

  .icon {
    flex-basis: 18px;
    width: 18px;
    font-size: 0.85rem;
    color: $color-text-muted;
  }

  // Raised on the group container: white, like a selected tab on its track.
  &:hover {
    background: rgba(#ffffff, 0.75);
    color: $color-text;
  }

  &.active {
    background: #ffffff;
    color: $sidebar-item-active-text;
    font-weight: 600;
    box-shadow: $shadow-sm;

    .icon {
      color: $sidebar-item-active-text;
    }
  }
}
</style>
