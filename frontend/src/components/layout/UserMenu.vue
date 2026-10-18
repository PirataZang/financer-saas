<template>
  <div ref="root" class="user-menu">
    <button
      ref="trigger"
      type="button"
      class="trigger"
      :class="{ 'is-open': open }"
      aria-haspopup="dialog"
      :aria-expanded="open"
      aria-controls="user-menu-card"
      @click="open ? close() : show()"
    >
      <span class="trigger__text">
        <strong>{{ auth.user?.name }}</strong>
        <span>{{ role.label }}</span>
      </span>
      <span class="avatar">{{ auth.userInitials }}</span>
      <i class="fa-solid fa-chevron-down trigger__chevron" aria-hidden="true" />
    </button>

    <Transition name="card-pop">
      <div
        v-if="open"
        id="user-menu-card"
        ref="card"
        class="card"
        role="dialog"
        aria-label="Conta"
        @keydown.esc.stop="close(true)"
      >
        <button type="button" class="card__close" aria-label="Fechar" @click="close(true)">
          <i class="fa-solid fa-xmark" aria-hidden="true" />
        </button>

        <span class="avatar avatar--lg">{{ auth.userInitials }}</span>
        <strong class="card__name">{{ auth.user?.name }}</strong>
        <span class="card__email">{{ auth.user?.email }}</span>
        <StatusBadge class="card__role" :label="role.label" :color="role.color" :icon="role.icon" small />

        <div class="card__actions">
          <router-link to="/settings?tab=preferences" class="action" @click="close()">
            <i class="fa-solid fa-sliders" aria-hidden="true" />
            Preferências
          </router-link>
          <button type="button" class="action action--danger" :disabled="loggingOut" @click="logout">
            <i class="fa-solid fa-right-from-bracket" aria-hidden="true" />
            {{ loggingOut ? 'Saindo...' : 'Sair da conta' }}
          </button>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { computed, nextTick, onUnmounted, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import StatusBadge from '@/components/ui/StatusBadge.vue'

const auth = useAuthStore()
const root = ref(null)
const trigger = ref(null)
const card = ref(null)
const open = ref(false)
const loggingOut = ref(false)

// Master in the restricted area's amber; everyone else neutral.
const role = computed(() =>
  auth.user?.master
    ? { label: 'Master', color: 'warning', icon: 'fa-solid fa-shield-halved' }
    : { label: 'Usuário', color: 'neutral', icon: 'fa-solid fa-user' },
)

const show = async () => {
  open.value = true
  await nextTick()
  card.value?.querySelector('.action')?.focus()
}

const close = (refocus = false) => {
  open.value = false
  if (refocus) trigger.value?.focus()
}

const logout = async () => {
  loggingOut.value = true
  await auth.logout()
  loggingOut.value = false
}

// A light popover, not a modal: no backdrop; any click outside closes it.
const onClickOutside = (event) => {
  if (!root.value?.contains(event.target)) close()
}
watch(open, (isOpen) => {
  if (isOpen) document.addEventListener('mousedown', onClickOutside)
  else document.removeEventListener('mousedown', onClickOutside)
})
onUnmounted(() => document.removeEventListener('mousedown', onClickOutside))
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.user-menu {
  position: relative;
}

.avatar {
  flex: 0 0 36px;
  width: 36px;
  height: 36px;
  display: grid;
  place-items: center;
  border-radius: $radius-full;
  background: $color-primary;
  color: #ffffff;
  font-size: $font-xs;
  font-weight: 700;
  letter-spacing: 0.02em;
}

.avatar--lg {
  flex-basis: 64px; // the card is a column: the basis is the height
  width: 64px;
  height: 64px;
  font-size: 1.35rem;
  box-shadow: 0 6px 16px color-mix(in srgb, #{$color-primary} 28%, transparent);
}

// --- Trigger: name + role, avatar, chevron ---
.trigger {
  display: flex;
  align-items: center;
  gap: 10px;
  height: 46px;
  padding: 4px 8px 4px 12px;
  border: 1px solid transparent;
  border-radius: $radius-full;
  background: transparent;
  font: inherit;
  cursor: pointer;
  transition: background $transition-fast, border-color $transition-fast, box-shadow $transition-fast;

  &:hover {
    background: $color-bg-hover;
    border-color: $color-border;
  }

  // The "light focus" while the card is open: the trigger stays marked, the page untouched.
  &.is-open {
    background: $color-bg-hover;
    border-color: $color-border-strong;
    box-shadow: 0 0 0 3px $color-primary-soft;
  }

  &:focus-visible {
    outline: 2px solid $color-primary;
    outline-offset: 2px;
  }
}

.trigger__text {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  line-height: 1.15;

  strong {
    max-width: 180px;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-size: $font-sm;
    font-weight: 600;
    color: $color-text;
  }

  span {
    font-size: $font-xs;
    color: $color-text-muted;
  }
}

.trigger__chevron {
  font-size: 0.65rem;
  color: $color-text-muted;
  transition: transform $transition-fast;

  .is-open & {
    transform: rotate(180deg);
  }
}

// --- Card ---
.card {
  position: absolute;
  top: calc(100% + 10px);
  right: 0;
  z-index: $z-dropdown;
  width: 300px;
  display: flex;
  flex-direction: column;
  align-items: center;
  padding: 28px 16px 12px;
  border: 1px solid $color-border;
  border-radius: $radius-xl;
  background: #ffffff;
  box-shadow: $shadow-lg;
  transform-origin: top right;
}

.card__close {
  position: absolute;
  top: 10px;
  right: 10px;
  width: 32px;
  height: 32px;
  display: grid;
  place-items: center;
  border: 0;
  border-radius: $radius-md;
  background: transparent;
  color: $color-text-muted;
  cursor: pointer;

  &:hover {
    background: $color-bg-muted;
    color: $color-text;
  }

  &:focus-visible {
    outline: 2px solid $color-primary;
    outline-offset: 2px;
  }
}

.card__name {
  margin-top: 14px;
  max-width: 100%;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: $font-lg;
  font-weight: 700;
  color: $color-text;
}

.card__email {
  margin-top: 2px;
  max-width: 100%;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: $font-sm;
  color: $color-text-muted;
}

.card__role {
  margin-top: 12px;
}

.card__actions {
  align-self: stretch;
  display: flex;
  flex-direction: column;
  gap: 2px;
  margin-top: 18px;
  padding-top: 8px;
  border-top: 1px solid $color-border;
}

.action {
  display: flex;
  align-items: center;
  gap: 12px;
  min-height: 42px;
  padding: 0 12px;
  border: 0;
  border-radius: $radius-md;
  background: transparent;
  color: $color-text-secondary;
  font: inherit;
  font-size: $font-md;
  font-weight: 500;
  text-align: left;
  text-decoration: none;
  cursor: pointer;
  transition: background $transition-fast;

  &:hover:not(:disabled) {
    background: $color-bg-muted;
  }

  &:focus-visible {
    outline: 2px solid $color-primary;
    outline-offset: -2px;
  }

  &:disabled {
    opacity: 0.6;
    cursor: wait;
  }
}

// Leaving the account is destructive-ish: the system's only red.
.action--danger {
  color: $color-danger;

  &:hover:not(:disabled) {
    background: $color-danger-soft;
  }
}

.card-pop-enter-active,
.card-pop-leave-active {
  transition: opacity 0.16s $ease, transform 0.16s $ease;
}

.card-pop-enter-from,
.card-pop-leave-to {
  opacity: 0;
  transform: translateY(-6px) scale(0.98);
}

@media (max-width: 900px) {
  .trigger__text {
    display: none;
  }
}

// Phones: full width beside the icon rail (the header sits below the menu's layer).
@media (max-width: 480px) {
  .card {
    position: fixed;
    top: 72px;
    right: 12px;
    left: calc(#{$sidebar-rail} + 12px);
    width: auto;
  }
}
</style>
