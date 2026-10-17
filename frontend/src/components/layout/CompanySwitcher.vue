<template>
  <!-- Header: the company you're working in. Masters open a searchable list (alphabetical, max 7). -->
  <Select
    v-if="auth.user?.master"
    v-model="selectedId"
    class="switcher"
    :options="options"
    aria-label="Trocar de empresa"
    searchable
    :limit="7"
  >
    <template #value>
      <span class="pill">
        <span class="avatar" :class="{ 'is-inactive': !current.active }">{{ current.initials }}</span>
        <span class="pill__text">
          <strong class="pill__name">{{ current.label }}</strong>
          <span class="pill__meta" :class="{ 'is-warning': !current.active }">
            <i v-if="!current.active" class="fa-solid fa-lock" aria-hidden="true" />
            {{ current.active ? 'Empresa' : 'Licença inativa' }}
          </span>
        </span>
        <i class="fa-solid fa-sort pill__chevron" aria-hidden="true" />
      </span>
    </template>

    <template #option="{ option }">
      <span class="option">
        <span class="avatar avatar--sm" :class="{ 'is-inactive': !option.active }">{{ option.initials }}</span>
        <span class="option__label">{{ option.label }}</span>
        <i v-if="!option.active" class="fa-solid fa-lock option__lock" title="Licença inativa" aria-label="Licença inativa" />
      </span>
    </template>

    <template #footer="{ close }">
      <router-link to="/restricted/company" class="manage" @click="close()">
        <i class="fa-solid fa-gear" aria-hidden="true" />
        Gerenciar empresas
      </router-link>
    </template>
  </Select>

  <!-- Everyone else: which company this is, informative only. -->
  <div v-else class="switcher switcher--static pill" :title="`Empresa: ${current.label}`">
    <span class="avatar" :class="{ 'is-inactive': !current.active }">{{ current.initials }}</span>
    <span class="pill__text">
      <strong class="pill__name">{{ current.label }}</strong>
      <span class="pill__meta">Empresa</span>
    </span>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'
import Select from '@/components/utils/Select.vue'

const auth = useAuthStore()

// "Padaria São José Ltda" → "PS": first letters of the first two meaningful words.
const IGNORED = new Set(['ltda', 'eireli', 'me', 'epp', 'sa', 's/a', 'de', 'da', 'do', 'das', 'dos', 'e'])
const initials = (name = '') => {
  const words = name.split(/\s+/).filter((w) => w && !IGNORED.has(w.toLowerCase()))
  return (words.length > 1 ? words[0][0] + words[1][0] : (words[0] || '?').slice(0, 2)).toUpperCase()
}

const describe = (c = {}) => {
  const label = c.trade_name || c.name || ''
  return { value: c.id, label, initials: initials(label), active: c.active !== false }
}

const current = computed(() => describe(auth.user?.company))
// Alphabetical, so the (accent-insensitive) search keeps narrowing an A→Z list.
const options = computed(() =>
  (auth.user?.companies || []).map(describe).sort((a, b) => a.label.localeCompare(b.label, 'pt-BR', { sensitivity: 'base' })),
)

const selectedId = ref(current.value.value)
watch(() => current.value.value, (id) => (selectedId.value = id))

// Switching reloads the app (stores.auth.switchCompany clears every client cache).
watch(selectedId, (id) => {
  if (id && id !== current.value.value) auth.switchCompany(id)
})
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

// --- Avatar: the company's initials; amber when the license is off ---
.avatar {
  flex: 0 0 32px;
  width: 32px;
  height: 32px;
  display: grid;
  place-items: center;
  border-radius: $radius-md;
  background: $color-primary-soft;
  color: $color-primary;
  font-size: $font-xs;
  font-weight: 700;
  letter-spacing: 0.02em;

  &.is-inactive {
    background: $color-warning-soft;
    color: $color-warning-text;
  }
}

.avatar--sm {
  flex-basis: 28px;
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  font-size: 0.7rem;
}

// --- Pill: current company ---
.switcher {
  width: auto;
  min-width: 0;
}

.pill {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.switcher--static {
  height: 46px;
  padding: 0 12px 0 7px;
}

.pill__text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.15;
  text-align: left;
}

.pill__name {
  max-width: 200px;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  font-size: $font-sm;
  font-weight: 600;
  color: $color-text;
}

.pill__meta {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: $font-xs;
  color: $color-text-muted;

  &.is-warning {
    font-weight: 600;
    color: $color-warning-text;
  }
}

.pill__chevron {
  flex: 0 0 auto;
  font-size: 0.75rem;
  color: $color-text-faint;
}

// The Select trigger becomes the pill: no input chrome, same hover/open language as the profile.
.switcher:deep(.select-trigger) {
  height: 46px;
  min-height: 46px;
  padding: 0 12px 0 7px;
  border: 1px solid $color-border;
  border-radius: $radius-full;
  background: #ffffff;
  box-shadow: none;
  transition: background $transition-fast, border-color $transition-fast, box-shadow $transition-fast;

  &:hover {
    background: $color-bg-hover;
    border-color: $color-border-strong;
  }

  &.is-focused,
  &:focus-visible {
    background: $color-bg-hover;
    border-color: $color-border-strong;
    box-shadow: 0 0 0 3px $color-primary-soft;
    outline: none;
  }
}

.switcher :deep(.select-chevron) {
  display: none;
}

// Opens under the pill, right-aligned, wider than it.
.switcher :deep(.select-menu) {
  left: auto;
  right: 0;
  width: 300px;
  padding: 8px;
}

// `limit` (7) already bounds the list; rows with avatars are taller than the default cap.
.switcher :deep(.select-menu) {
  max-height: none;
}

// --- List ---
.option {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.option__label {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.option__lock {
  flex: 0 0 auto;
  font-size: 0.7rem;
  color: $color-warning;
}

.manage {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 6px;
  padding: 10px 8px 4px;
  border-top: 1px solid $color-border;
  color: $color-text-muted;
  font-size: $font-sm;
  font-weight: 500;
  text-decoration: none;
  transition: color $transition-fast;

  &:hover {
    color: $color-primary;
  }

  &:focus-visible {
    outline: 2px solid $color-primary;
    outline-offset: 2px;
    border-radius: $radius-sm;
  }
}

@media (max-width: 900px) {
  .pill__text {
    display: none;
  }
}

// Phones: full width beside the icon rail (the header sits below the menu's layer).
@media (max-width: 480px) {
  .switcher :deep(.select-menu) {
    position: fixed;
    top: 72px;
    right: 12px;
    left: calc(#{$sidebar-rail} + 12px);
    width: auto;
  }
}
</style>
