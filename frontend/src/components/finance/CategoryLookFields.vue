<template>
  <div class="col-6">
    <Input v-model="icon" label="Ícone (emoji)" :placeholder="EMOJIS[2]" maxlength="16" />
    <div class="swatches" role="group" aria-label="Sugestões de ícone">
      <button
        v-for="emoji in EMOJIS"
        :key="emoji"
        type="button"
        class="swatch swatch--emoji"
        :class="{ selected: icon === emoji }"
        :aria-label="`Usar ${emoji}`"
        @click="icon = emoji"
      >{{ emoji }}</button>
    </div>
  </div>

  <div class="col-6">
    <Input v-model="color" label="Cor" type="color" />
    <div class="swatches" role="group" aria-label="Sugestões de cor">
      <button
        v-for="swatch in COLORS"
        :key="swatch"
        type="button"
        class="swatch swatch--color"
        :class="{ selected: color === swatch }"
        :style="{ background: swatch }"
        :aria-label="`Usar a cor ${swatch}`"
        @click="color = swatch"
      />
    </div>
  </div>
</template>

<script setup>
import Input from '@/components/utils/Input.vue'

// Emoji + color of a category, as two .col-6 cells of the parent .form-grid (Categorias and Metas):
//   <CategoryLookFields v-model:icon="form.icon" v-model:color="form.color" />
// Shortcuts only — any emoji / color can be typed or picked.
const EMOJIS = ['💼', '📈', '🛒', '🍔', '🏠', '🧾', '🚗', '🎮', '🩺', '📚', '📦', '💡', '📱', '✈️', '🎁', '🐶', '👕', '💳', '🏦', '💰']
const COLORS = ['#059669', '#0891b2', '#2563eb', '#4f46e5', '#9333ea', '#db2777', '#d97706', '#ea580c', '#65a30d', '#0d9488', '#64748b']

const icon = defineModel('icon', { default: '' })
const color = defineModel('color', { default: '#2563eb' })
</script>
