<template>
  <div class="portal">
    <header class="portal-top">
      <div class="portal-top__inner">
        <router-link :to="{ name: 'PortalHome', params: { slug } }" class="portal-brand">
          <span class="portal-brand__mark" aria-hidden="true">{{ initials(company?.name) }}</span>
          <span class="portal-brand__text">
            <strong>{{ company?.name || 'Portal do cliente' }}</strong>
            <small>Portal do cliente</small>
          </span>
        </router-link>

        <!-- Phone: fixed tab bar at the bottom (thumb reach). Wider screens: inline in the header. -->
        <nav class="portal-nav" aria-label="Seções do portal">
          <router-link
            v-for="item in NAV"
            :key="item.name"
            :to="{ name: item.name, params: { slug } }"
            class="portal-nav__item"
            :class="{ 'is-active': isActive(item) }"
            :aria-current="isActive(item) ? 'page' : undefined"
          >
            <span class="portal-nav__icon" :class="{ 'is-ringing': unseen(item) }">
              <i :class="item.icon" aria-hidden="true" />
              <span v-if="unseen(item)" class="portal-nav__badge" aria-hidden="true">{{ unseen(item) > 9 ? '9+' : unseen(item) }}</span>
            </span>
            <span>{{ item.label }}</span>
            <span v-if="unseen(item)" class="sr-only">({{ unseen(item) }} {{ unseen(item) === 1 ? 'novo' : 'novos' }})</span>
          </router-link>
        </nav>

        <button type="button" class="portal-exit" :title="`Sair (${customer?.name || ''})`" @click="logout">
          <span class="portal-exit__avatar" aria-hidden="true">{{ initials(customer?.name) }}</span>
          <span class="sr-only">Sair do portal</span>
          <i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true" />
        </button>
      </div>
    </header>

    <main class="portal-main">
      <router-view v-if="portal.me" />
      <div v-else class="p-state" role="status">
        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
        <span>Carregando...</span>
      </div>
    </main>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { usePortalStore } from '@/stores/portal'
import { swal } from '@/plugins/swal'
import { applyAppearance, resetAppearance } from '@/utils/theme'

const NAV = [
  { name: 'PortalHome', label: 'Início', icon: 'fa-solid fa-house', also: ['PortalProject'] },
  { name: 'PortalBills', label: 'Contas', icon: 'fa-solid fa-receipt' },
  { name: 'PortalAnnouncements', label: 'Avisos', icon: 'fa-solid fa-bell' },
]

const route = useRoute()
const portal = usePortalStore()

const slug = computed(() => route.params.slug)
const company = computed(() => portal.me?.company)
const customer = computed(() => portal.me?.customer)

// Notification bell: unread count over the Avisos icon.
const unseen = (item) => (item.name === 'PortalAnnouncements' ? portal.unseenAnnouncements : 0)

const isActive = (item) => item.name === route.name || item.also?.includes(route.name)

const initials = (name) =>
  String(name || '?')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join('')

const logout = async () => {
  const ok = await swal.confirm({ title: 'Sair do portal?', text: 'Para entrar de novo, você recebe um novo código por e-mail.', confirmButtonText: 'Sair', icon: 'question' })
  if (ok) await portal.logout()
}

// The company's color and name, like the CRM (Configurações › Aparência).
onMounted(async () => {
  const me = await portal.loadMe()
  if (!me) return
  applyAppearance({ primary_color: me.company.primary_color, brand_name: me.company.name })
  if (route.name !== 'PortalAnnouncements') portal.checkAnnouncements()
})
onUnmounted(resetAppearance)
</script>

<style scoped lang="scss">
.portal {
  min-height: 100dvh;
  background: var(--color-bg-app);
  padding-bottom: calc(64px + env(safe-area-inset-bottom));
}

.portal-top {
  position: sticky;
  top: 0;
  z-index: 50;
  background: var(--color-bg-card);
  border-bottom: 1px solid var(--color-border);
}

.portal-top__inner {
  display: flex;
  align-items: center;
  gap: 12px;
  max-width: 760px;
  height: 60px;
  margin: 0 auto;
  padding: 0 16px;
}

.portal-brand {
  display: flex;
  flex: 1;
  align-items: center;
  gap: 10px;
  min-width: 0;
  border-radius: 10px;

  &:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 3px;
  }
}

.portal-brand__mark {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: var(--color-primary);
  color: #ffffff;
  font-size: 0.8rem;
  font-weight: 700;
}

.portal-brand__text {
  min-width: 0;

  strong {
    display: block;
    overflow: hidden;
    color: var(--color-text);
    font-size: 0.95rem;
    line-height: 1.2;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  small {
    color: var(--color-text-muted);
    font-size: 0.72rem;
  }
}

.portal-nav {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 0;
  z-index: 50;
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  padding-bottom: env(safe-area-inset-bottom);
  background: var(--color-bg-card);
  border-top: 1px solid var(--color-border);
  box-shadow: 0 -4px 16px rgba(15, 23, 42, 0.05);
}

.portal-nav__item {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
  min-height: 64px;
  color: var(--color-text-muted);
  font-size: 0.72rem;
  font-weight: 600;

  i {
    display: grid;
    place-items: center;
    width: 52px;
    height: 28px;
    border-radius: 999px;
    font-size: 1.05rem;
    transition: background 0.15s ease, color 0.15s ease;
  }

  &.is-active {
    color: var(--color-primary);

    i {
      background: var(--color-primary-soft);
    }
  }

  &:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: -4px;
    border-radius: 12px;
  }
}

.portal-exit {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  height: 40px;
  padding: 0 4px 0 2px;
  border: 0;
  border-radius: 999px;
  background: transparent;
  color: var(--color-text-muted);
  cursor: pointer;

  &:hover {
    color: var(--color-text);
  }

  &:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
  }

  > i {
    display: none;
  }
}

.portal-exit__avatar {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 999px;
  background: var(--color-bg-muted);
  color: var(--color-text-secondary);
  font-size: 0.78rem;
  font-weight: 700;
}

.portal-main {
  max-width: 760px;
  margin: 0 auto;
  padding: 20px 16px 32px;
}

// Notification bell: the count sits on the icon's top-right corner and the bell rings once.
.portal-nav__icon {
  position: relative;
  display: inline-grid;
  place-items: center;

  &.is-ringing i::before {
    display: inline-block;
    animation: bell-ring 0.9s ease 0.3s;
    transform-origin: 50% 0;
  }
}

@keyframes bell-ring {
  0%,
  100% {
    transform: rotate(0);
  }

  20%,
  60% {
    transform: rotate(14deg);
  }

  40%,
  80% {
    transform: rotate(-14deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .portal-nav__icon.is-ringing i::before {
    animation: none;
  }
}

.portal-nav__badge {
  position: absolute;
  top: -6px;
  left: calc(50% + 4px);
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border: 2px solid var(--color-bg-card);
  border-radius: 999px;
  background: var(--color-danger);
  color: #ffffff;
  font-size: 0.65rem;
  font-weight: 700;
  line-height: 14px;
  text-align: center;
}

@media (min-width: 768px) {
  .portal {
    padding-bottom: 0;
  }

  .portal-nav {
    position: static;
    display: flex;
    gap: 4px;
    padding: 0;
    background: transparent;
    border: 0;
    box-shadow: none;
  }

  .portal-nav__item {
    flex-direction: row;
    gap: 8px;
    min-height: 40px;
    padding: 0 14px;
    border-radius: 999px;
    font-size: 0.875rem;

    i {
      width: auto;
      height: auto;
      font-size: 0.9rem;
      background: transparent !important;
    }

    &:hover {
      background: var(--color-bg-muted);
    }

    &.is-active {
      background: var(--color-primary-soft);
    }
  }

  // Smaller inline icon: lift the count and keep it off the label.
  .portal-nav__icon.is-ringing {
    margin-right: 8px;
  }

  .portal-nav__badge {
    top: -9px;
  }

  .portal-exit > i {
    display: inline;
  }

  .portal-main {
    padding-top: 28px;
  }
}
</style>

<!-- Building blocks shared by the portal pages (rendered inside this layout). -->
<style lang="scss">
.portal {
  .sr-only {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
  }

  .p-title {
    color: var(--color-text);
    font-size: 1.35rem;
    font-weight: 700;
    line-height: 1.25;
  }

  .p-sub {
    margin-top: 4px;
    color: var(--color-text-muted);
    font-size: 0.9rem;
  }

  .p-section {
    margin-top: 28px;
  }

  .p-section__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 12px;

    h2 {
      color: var(--color-text);
      font-size: 1rem;
      font-weight: 700;
    }

    a {
      color: var(--color-primary);
      font-size: 0.85rem;
      font-weight: 600;
    }
  }

  .p-card {
    display: block;
    padding: 16px;
    border: 1px solid var(--color-border);
    border-radius: var(--radius-xl);
    background: var(--color-bg-card);
    box-shadow: var(--shadow-sm);
  }

  a.p-card {
    transition: border-color 0.15s ease, box-shadow 0.15s ease;

    &:hover {
      border-color: var(--color-border-strong);
      box-shadow: var(--shadow-md);
    }

    &:focus-visible {
      outline: 2px solid var(--color-primary);
      outline-offset: 2px;
    }
  }

  .p-list {
    display: flex;
    flex-direction: column;
    gap: 12px;
  }

  .p-progress {
    height: 8px;
    overflow: hidden;
    border-radius: 999px;
    background: var(--color-bg-muted);

    span {
      display: block;
      height: 100%;
      border-radius: inherit;
      background: var(--color-primary);
      transition: width 0.4s ease;
    }
  }

  .p-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    padding: 40px 16px;
    color: var(--color-text-muted);
    font-size: 0.9rem;
    text-align: center;

    > i {
      color: var(--color-text-faint);
      font-size: 1.6rem;
    }
  }

  .p-back {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    min-height: 40px;
    margin-bottom: 8px;
    color: var(--color-text-muted);
    font-size: 0.875rem;
    font-weight: 600;

    &:hover {
      color: var(--color-text);
    }
  }
}
</style>
