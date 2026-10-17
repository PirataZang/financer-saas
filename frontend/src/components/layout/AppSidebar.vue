<template>
  <aside class="sidebar" :class="{ visible, expanded: isExpanded }">
    <div class="sidebar__panel">
      <div class="brand">
        <div class="brand__mark" aria-hidden="true"><i class="fa-solid fa-chart-line" /></div>
        <div v-show="isExpanded" class="brand__text">
          <strong>Financial Bot</strong>
          <span>Painel Admin</span>
        </div>
      </div>

      <nav class="nav" aria-label="Menu principal">
        <ul class="nav__list">
          <SidebarMenuItem
            v-for="item in menuItems"
            :key="itemKey(item)"
            :item="item"
            :expanded="isExpanded"
            :open-keys="openKeys"
            @toggle="toggleOpen"
          />
        </ul>
      </nav>

    </div>
  </aside>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { menuItems as allowedMenuItems, menuTree } from '@/permissions'
import SidebarMenuItem from './SidebarMenuItem.vue'

// Open/closed is owned by AppLayout (topbar toggle, remembered per browser). Expanded = fixed
// next to the content; compact = icon rail, where groups open as flyouts (SidebarMenuItem).
const props = defineProps({
  visible: { type: Boolean, default: true },
  expanded: { type: Boolean, default: false },
})

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const openKeys = ref(new Set())

const isExpanded = computed(() => props.visible && props.expanded)

// Itens vêm do meta.menu das rotas (router/index.js), já filtrados pela permissão
// do usuário — para um novo menu, declare meta.permission + meta.menu na rota;
// meta.menu.group aninha o item num submenu (MENU_GROUPS em permissions.js).
const toSidebarItem = (item) => ({
  name: item.label,
  route: item.route,
  icon: `<i class="${item.icon}"></i>`,
  tone: item.tone,
  children: (item.children || []).map(toSidebarItem),
})

const menuItems = computed(() =>
  menuTree(allowedMenuItems(router.getRoutes(), auth.user).filter((item) => item.sidebar !== false)).map(toSidebarItem),
)

const itemKey = (item) => item.route || item.name
const hasChildren = (item) => Array.isArray(item.children) && item.children.length > 0

const isActiveBranch = (item) => {
  if (hasChildren(item)) return item.children.some((c) => isActiveBranch(c))
  if (!item.route) return false
  if (item.route === '/dashboard') return route.path === '/dashboard' || route.path === '/'
  // Listagens marcam o item pai ativo também nos formulários filhos
  return route.path === item.route || route.path.startsWith(`${item.route}/`)
}

const toggleOpen = (key) => {
  const next = new Set(openKeys.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  openKeys.value = next
}

// Groups start open so their submenu shows in the menu; the chevron still collapses them.
const knownGroups = new Set()
watch(
  menuItems,
  (items) => {
    const next = new Set(openKeys.value)
    items.filter(hasChildren).forEach((item) => {
      if (knownGroups.has(itemKey(item))) return
      knownGroups.add(itemKey(item))
      next.add(itemKey(item))
    })
    openKeys.value = next
  },
  { immediate: true },
)

watch(
  () => route.path,
  () => {
    const next = new Set(openKeys.value)
    const walk = (items) => {
      items.forEach((item) => {
        if (hasChildren(item) && isActiveBranch(item)) next.add(itemKey(item))
        if (hasChildren(item)) walk(item.children)
      })
    }
    walk(menuItems.value)
    openKeys.value = next
  },
  { immediate: true },
)
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.sidebar {
  position: relative;
  flex: 0 0 0;
  width: 0;
  height: 100%;
  z-index: $z-sidebar;
  transition: flex-basis $transition-sidebar, width $transition-sidebar;

  &.visible {
    flex-basis: $sidebar-rail;
    width: $sidebar-rail;
  }

  // Expanded is fixed: it takes its width from the content instead of covering it.
  &.visible.expanded {
    flex-basis: $sidebar-width;
    width: $sidebar-width;
  }
}

.sidebar__panel {
  position: absolute;
  top: 0;
  left: 0;
  height: 100%;
  width: $sidebar-rail;
  display: flex;
  flex-direction: column;
  background: #ffffff;
  border-right: 1px solid $color-border;
  box-shadow: none;
  overflow: hidden;
  transition: width $transition-sidebar, box-shadow $transition-sidebar;

  .sidebar:not(.visible) & {
    transform: translateX(-100%);
  }

  .sidebar.expanded & {
    width: $sidebar-width;
  }
}

// Small screens: the expanded menu floats over the content (AppLayout adds the backdrop).
@media (max-width: 900px) {
  .sidebar.visible.expanded {
    flex-basis: $sidebar-rail;
    width: $sidebar-rail;
  }

  .sidebar.expanded .sidebar__panel {
    box-shadow: $shadow-sidebar;
  }
}

.brand {
  display: flex;
  align-items: center;
  gap: 12px;
  height: $topbar-height;
  min-height: $topbar-height;
  padding: 0 16px;
  border-bottom: 1px solid $color-border;
  background: #ffffff;
}

.brand__mark {
  flex: 0 0 40px;
  width: 40px;
  height: 40px;
  display: grid;
  place-items: center;
  border-radius: $radius-md;
  background: $color-primary-soft;
  color: $color-primary;
  font-size: 1.15rem;
}

.brand__text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.15;

  strong {
    font-size: $font-lg;
    font-weight: 700;
    color: $color-text;
  }

  span {
    font-size: $font-xs;
    color: $color-text-muted;
    font-weight: 500;
  }
}

.nav {
  flex: 1 1 auto;
  min-height: 0;
  overflow-x: hidden;
  overflow-y: auto;
  padding: 12px 10px;
  background: #ffffff;
}

.nav__list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

</style>
