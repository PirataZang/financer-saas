<template>
  <div class="shell">
    <AppSidebar :expanded="menuExpanded" />
    <div v-if="menuExpanded" class="shell__backdrop" aria-hidden="true" @click="setMenu(false)" />

    <div class="shell__main">
      <header class="topbar">
        <div class="topbar__left">
          <button
            type="button"
            class="menu-toggle"
            :aria-label="menuExpanded ? 'Recolher menu' : 'Expandir menu'"
            :title="menuExpanded ? 'Recolher menu' : 'Expandir menu'"
            :aria-expanded="menuExpanded"
            @click="setMenu(!menuExpanded)"
          >
            <i :class="menuExpanded ? 'fa-solid fa-angles-left' : 'fa-solid fa-bars'" aria-hidden="true" />
          </button>
          <GlobalSearch />
        </div>

        <div class="topbar__right">
          <CompanySwitcher />
          <span class="topbar__divider" aria-hidden="true" />
          <UserMenu />
        </div>
      </header>

      <main class="shell__content">
        <router-view />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { PREFERENCE_PREFIX, useAuthStore } from '@/stores/auth'
import { canAccessRoute } from '@/permissions'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import GlobalSearch from '@/components/layout/GlobalSearch.vue'
import CompanySwitcher from '@/components/layout/CompanySwitcher.vue'
import UserMenu from '@/components/layout/UserMenu.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

// Menu opens/closes only with the toggle (no hover) and stays as the user left it, per browser.
// First visit: open on wide screens. Small screens: floats over the content, closes on navigation.
const MENU_KEY = `${PREFERENCE_PREFIX}menu_expanded`
const isSmallScreen = () => window.matchMedia('(max-width: 900px)').matches
const readMenuPreference = () => {
  try {
    const stored = localStorage.getItem(MENU_KEY)
    return stored === null ? window.innerWidth >= 1200 : stored === '1'
  } catch {
    return !isSmallScreen()
  }
}
const menuExpanded = ref(!isSmallScreen() && readMenuPreference())

const setMenu = (open) => {
  menuExpanded.value = open
  // The overlay state on a phone isn't a preference worth remembering.
  if (isSmallScreen()) return
  try {
    localStorage.setItem(MENU_KEY, open ? '1' : '0')
  } catch {
    // Storage blocked (private mode): the toggle still works for this visit.
  }
}

watch(
  () => route.fullPath,
  () => {
    if (isSmallScreen()) menuExpanded.value = false
  },
)

// Permission revoked while on the page (picked up by the session poll): leave it.
watch(
  () => auth.user?.permissions,
  () => {
    if (auth.user && !canAccessRoute(auth.user, route)) router.replace('/')
  },
)

onMounted(() => auth.startPolling())
onUnmounted(() => auth.stopPolling())
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.shell {
  display: flex;
  width: 100%;
  height: 100vh;
  height: 100dvh;
  overflow: hidden;
  background: $color-bg-app;
}

.shell__main {
  flex: 1 1 auto;
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
  background: $color-bg-app;
}

.topbar {
  flex: 0 0 $topbar-height;
  height: $topbar-height;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 0 20px;
  background: #ffffff;
  border-bottom: 1px solid $color-border;
  z-index: $z-topbar;
}

.menu-toggle {
  flex: 0 0 auto;
  width: 38px;
  height: 38px;
  display: grid;
  place-items: center;
  border: 1px solid $color-border;
  border-radius: $radius-md;
  background: #ffffff;
  color: $color-text-secondary;
  font-size: 0.95rem;
  cursor: pointer;
  transition: background $transition-fast, color $transition-fast, border-color $transition-fast;

  &:hover {
    background: $color-bg-hover;
    border-color: $color-border-strong;
    color: $color-text;
  }

  &:focus-visible {
    outline: 2px solid $color-primary;
    outline-offset: 2px;
  }
}

.shell__backdrop {
  display: none;
}

@media (max-width: 900px) {
  .shell__backdrop {
    display: block;
    position: fixed;
    inset: 0;
    z-index: $z-sidebar - 1;
    background: rgba(15, 23, 42, 0.35);
  }
}

.topbar__left,
.topbar__right {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}


.topbar__divider {
  width: 1px;
  height: 28px;
  background: $color-border;
}

.shell__content {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  padding: 24px;
}

@media (max-width: 640px) {
  .shell__content {
    padding: 16px;
  }
}
</style>
