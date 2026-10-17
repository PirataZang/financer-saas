<template>
  <div class="permissions">
    <div v-if="lockedCount" class="notice notice--muted">
      <i class="fa-solid fa-lock" aria-hidden="true" />
      <span>Você só pode conceder ou remover permissões que você mesmo possui ({{ lockedCount }} bloqueada(s)).</span>
    </div>

    <div class="permission-grid">
      <section v-for="module in catalog" :key="module.module" class="permission-card">
        <header class="permission-card__header">
          <div class="permission-card__title">
            <strong>{{ module.name }}</strong>
            <span>{{ grantedIn(module) }}/{{ module.permissions.length }} liberadas</span>
          </div>
          <Switch
            :model-value="grantedIn(module) === module.permissions.length"
            :disabled="disabled || module.permissions.every((p) => isLocked(p.key))"
            :aria-label="`Liberar todas as permissões de ${module.name}`"
            @update:model-value="(on) => toggleModule(module, on)"
          />
        </header>

        <ul class="permission-card__body">
          <li v-for="permission in module.permissions" :key="permission.key" class="permission-item">
            <div class="permission-item__text">
              <span>{{ permission.description || permission.action }}</span>
              <code>{{ permission.key }}</code>
            </div>
            <Switch
              :model-value="modelValue.includes(permission.key)"
              :disabled="disabled || isLocked(permission.key)"
              :aria-label="permission.description || permission.key"
              @update:model-value="(on) => toggle(permission.key, on)"
            />
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import Switch from '@/components/utils/Switch.vue'

// Catalog comes from GET /permissions (config/permissions.php synced to the database),
// so new modules show up here without frontend changes.
const props = defineProps({
  catalog: { type: Array, required: true },
  modelValue: { type: Array, required: true },
  disabled: { type: Boolean, default: false },
})

const emit = defineEmits(['update:modelValue'])
const auth = useAuthStore()

// Mirrors the API rule: a non-master can only change permissions they hold.
const isLocked = (key) => !auth.can(key)
const lockedCount = computed(
  () => props.catalog.flatMap((m) => m.permissions).filter((p) => isLocked(p.key)).length,
)

const grantedIn = (module) => module.permissions.filter((p) => props.modelValue.includes(p.key)).length

const toggle = (key, on) => {
  const next = new Set(props.modelValue)
  if (on) next.add(key)
  else next.delete(key)
  emit('update:modelValue', [...next])
}

const toggleModule = (module, on) => {
  const next = new Set(props.modelValue)
  module.permissions
    .filter((p) => !isLocked(p.key))
    .forEach((p) => (on ? next.add(p.key) : next.delete(p.key)))
  emit('update:modelValue', [...next])
}
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.permissions {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.notice {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  border: 1px solid $color-warning;
  border-radius: $radius-md;
  background: $color-warning-soft;
  color: $color-text-secondary;
  font-size: $font-sm;

  i {
    color: $color-warning;
  }

  &--muted {
    border-color: $color-border;
    background: $color-bg-muted;

    i {
      color: $color-text-muted;
    }
  }
}

.permission-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 16px;
}

.permission-card {
  border: 1px solid $color-border;
  border-radius: $radius-lg;
  background: #ffffff;
  overflow: hidden;
}

.permission-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid $color-border;
  background: $color-bg-hover;
}

.permission-card__title {
  display: flex;
  flex-direction: column;
  line-height: 1.25;

  strong {
    font-size: $font-md;
    color: $color-text;
  }

  span {
    font-size: $font-xs;
    color: $color-text-muted;
  }
}

.permission-card__body {
  margin: 0;
  padding: 4px 0;
  list-style: none;
}

.permission-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 16px;

  & + & {
    border-top: 1px solid $color-bg-muted;
  }
}

.permission-item__text {
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.3;

  span {
    font-size: $font-sm;
    color: $color-text-secondary;
  }

  code {
    font-size: 0.7rem;
    color: $color-text-faint;
  }
}
</style>
