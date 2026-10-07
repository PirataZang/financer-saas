<template>
  <div class="form-actions">
    <Button
      native-type="submit"
      variant="primary"
      :icon="saveIcon"
      :label="saving ? savingLabel : saveLabel"
      :disabled="saving || disabled"
    />
    <Button
      type="button"
      variant="secondary"
      :icon="cancelIcon"
      :label="cancelLabel"
      :disabled="saving"
      @click="$emit('cancel')"
    />
    <slot />
  </div>
</template>

<script setup>
import Button from '@/components/utils/Button.vue'

defineProps({
  saving: { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
  saveLabel: { type: String, default: 'Salvar' },
  savingLabel: { type: String, default: 'Salvando...' },
  cancelLabel: { type: String, default: 'Cancelar' },
  saveIcon: { type: String, default: 'fa-solid fa-check' },
  cancelIcon: { type: String, default: 'fa-solid fa-xmark' },
})

defineEmits(['cancel'])
</script>
