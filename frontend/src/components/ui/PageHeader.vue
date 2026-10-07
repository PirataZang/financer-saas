<template>
  <header class="page-header">
    <div class="page-header__identity">
      <span v-if="icon" class="page-header__icon" aria-hidden="true">
        <i :class="icon" />
      </span>
      <div class="page-header__body">
        <h2>{{ title }}</h2>
        <p v-if="subtitle">{{ subtitle }}</p>
      </div>
    </div>

    <div v-if="$slots.default" class="page-header__actions">
      <slot />
    </div>
  </header>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  subtitle: { type: String, default: '' },
  icon: { type: String, default: '' },
})
</script>
