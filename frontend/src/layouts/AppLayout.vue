<template>
  <div class="shell">
    <AppSidebar />

    <div class="shell__main">
      <header class="topbar">
        <div class="topbar__left">
          <GlobalSearch />
        </div>

        <div class="topbar__right">
          <div class="whoami">
            <div class="whoami__text">
              <strong>{{ auth.user?.name }}</strong>
              <span>{{ auth.user?.email }}</span>
            </div>
            <div class="whoami__avatar">{{ auth.userInitials }}</div>
          </div>

          <button type="button" class="logout" :disabled="loggingOut" @click="handleLogout">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true" />
            <span>{{ loggingOut ? 'Saindo...' : 'Sair' }}</span>
          </button>
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
import { useAuthStore } from '@/stores/auth'
import { canAccessRoute } from '@/permissions'
import AppSidebar from '@/components/layout/AppSidebar.vue'
import GlobalSearch from '@/components/layout/GlobalSearch.vue'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
const loggingOut = ref(false)

// Permission revoked while on the page (picked up by the session poll): leave it.
watch(
  () => auth.user?.permissions,
  () => {
    if (auth.user && !canAccessRoute(auth.user, route)) router.replace('/')
  },
)

const handleLogout = async () => {
  loggingOut.value = true
  await auth.logout()
  loggingOut.value = false
}

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

.topbar__left,
.topbar__right {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}


.whoami {
  display: flex;
  align-items: center;
  gap: 10px;
}

.whoami__text {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  line-height: 1.15;

  strong {
    font-size: $font-sm;
    font-weight: 600;
    color: $color-text;
  }

  span {
    font-size: $font-xs;
    color: $color-text-muted;
  }
}

.whoami__avatar {
  width: 36px;
  height: 36px;
  display: grid;
  place-items: center;
  border-radius: $radius-full;
  background: $color-primary;
  color: #fff;
  font-size: $font-xs;
  font-weight: 700;
}

.logout {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 36px;
  padding: 0 14px;
  border: 1px solid $color-primary-muted;
  border-radius: $radius-full;
  background: $color-primary-soft;
  color: $color-primary;
  font-size: $font-sm;
  font-weight: 600;
  cursor: pointer;

  &:hover:not(:disabled) {
    background: $color-primary-muted;
  }

  &:disabled {
    opacity: 0.7;
    cursor: not-allowed;
  }
}

.shell__content {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  padding: 24px;
}

@media (max-width: 900px) {
  .whoami__text {
    display: none;
  }

  .logout span {
    display: none;
  }
}

@media (max-width: 640px) {
  .shell__content {
    padding: 16px;
  }
}
</style>
