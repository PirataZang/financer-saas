<template>
  <form @submit.prevent="save">
    <h3 class="form-section">Cor principal</h3>
    <div class="form-grid">
      <div class="col-6">
        <Input v-model="form.primary_color" label="Cor" type="color" />
        <div class="swatches" role="group" aria-label="Sugestões de cor">
          <button
            v-for="color in COLORS"
            :key="color"
            type="button"
            class="swatch swatch--color"
            :class="{ selected: form.primary_color === color }"
            :style="{ background: color }"
            :aria-label="`Usar a cor ${color}`"
            @click="form.primary_color = color"
          />
        </div>
        <p class="field-hint" :class="{ 'is-error': !readable }" role="status">
          <template v-if="readable">Botões, links, item ativo do menu e destaques usam esta cor. A prévia já vale para a tela inteira.</template>
          <template v-else>
            Texto branco fica pouco legível nesta cor (contraste {{ contrast.toFixed(1).replace('.', ',') }}:1, mínimo 4,5:1).
            Escolha um tom mais escuro.
          </template>
        </p>
      </div>

      <div class="col-6 preview" aria-label="Pré-visualização">
        <span class="field-label">Pré-visualização</span>
        <div class="preview__card">
          <div class="preview__brand">
            <span class="preview__mark" aria-hidden="true"><i class="fa-solid fa-chart-line" /></span>
            <strong>{{ form.brand_name || APP_NAME }}</strong>
          </div>
          <span class="preview__item"><i class="fa-solid fa-wallet" aria-hidden="true" /> Item ativo do menu</span>
          <div class="preview__actions">
            <Button label="Botão principal" icon="fa-solid fa-check" size="sm" />
            <a href="#" class="preview__link" @click.prevent>Um link</a>
          </div>
        </div>
      </div>
    </div>

    <h3 class="form-section">Identidade</h3>
    <div class="form-grid">
      <div class="col-6">
        <Input v-model="form.brand_name" label="Nome exibido" :placeholder="APP_NAME" maxlength="40" />
        <p class="field-hint">Aparece no topo do menu e na aba do navegador. Vazio usa "{{ APP_NAME }}".</p>
      </div>
    </div>

    <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

    <FormActions :saving="saving" :disabled="!readable" cancel-label="Descartar alterações" cancel-icon="fa-solid fa-rotate-left" @cancel="reset">
      <Button variant="ghost" icon="fa-solid fa-arrow-rotate-left" label="Restaurar padrão" :disabled="saving" @click="restoreDefault" />
    </FormActions>
  </form>
</template>

<script setup>
import { computed, onUnmounted, ref, watch } from 'vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'
import { APP_NAME, applyAppearance, contrastWithWhite } from '@/utils/theme'
import Input from '@/components/utils/Input.vue'
import Button from '@/components/utils/Button.vue'
import FormActions from '@/components/ui/FormActions.vue'

const DEFAULT_COLOR = '#2563eb'
// Dark enough for white text (≥ 4,5:1). No red: it means danger in this system.
const COLORS = ['#2563eb', '#4f46e5', '#7c3aed', '#c026d3', '#db2777', '#c2410c', '#a16207', '#047857', '#0f766e', '#0e7490', '#334155']

const auth = useAuthStore()
const saved = () => ({ primary_color: auth.settings.appearance.primary_color || DEFAULT_COLOR, brand_name: auth.settings.appearance.brand_name || '' })
const form = ref(saved())
const saving = ref(false)
const error = ref('')

const contrast = computed(() => contrastWithWhite(form.value.primary_color))
const readable = computed(() => contrast.value >= 4.5)

// Live preview on the whole app; leaving without saving puts the saved look back.
watch(form, (value) => applyAppearance(value), { deep: true })
onUnmounted(() => applyAppearance(auth.settings.appearance))

const reset = () => (form.value = saved())
const restoreDefault = () => (form.value = { primary_color: DEFAULT_COLOR, brand_name: '' })

const save = async () => {
  saving.value = true
  error.value = ''
  try {
    const response = await apiFetch('/settings/appearance', {
      method: 'PUT',
      body: JSON.stringify({ ...form.value, brand_name: form.value.brand_name.trim() || null }),
    })
    const data = await response.json()
    if (!response.ok) throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || 'Não foi possível salvar.')
    await auth.refreshUser()
    swal.toastSuccess('Aparência salva para toda a empresa.')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.preview {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.preview__card {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px;
  border: 1px solid $color-border;
  border-radius: $radius-lg;
  background: $color-bg-app;
}

.preview__brand {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;

  strong {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-size: $font-lg;
    color: $color-text;
  }
}

.preview__mark {
  flex: 0 0 36px;
  width: 36px;
  height: 36px;
  display: grid;
  place-items: center;
  border-radius: $radius-md;
  background: $color-primary-soft;
  color: $color-primary;
}

.preview__item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 12px;
  border-radius: $radius-md;
  background: $color-primary-soft;
  color: $color-primary;
  font-size: $font-md;
  font-weight: 600;
}

.preview__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 16px;
}

.preview__link {
  color: $color-primary;
  font-size: $font-md;
  font-weight: 600;
}
</style>
