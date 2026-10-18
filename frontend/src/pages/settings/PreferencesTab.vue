<template>
  <form @submit.prevent="save">
    <p class="intro">Só para você, em qualquer computador ou celular em que entrar.</p>

    <div class="form-grid">
      <div class="col-4">
        <Switch v-model="form.sidebar_expanded" label="Menu lateral" :text-label="form.sidebar_expanded ? 'Aberto' : 'Só ícones'" />
        <p class="field-hint">Como o menu fica ao entrar. O botão no topo também troca e lembra a escolha.</p>
      </div>

      <div class="col-4">
        <Select v-model="form.table_density" label="Densidade das tabelas" :options="DENSITIES" />
        <p class="field-hint">Compacta mostra mais linhas por tela.</p>
      </div>

      <div class="col-4">
        <Select v-model="form.start_page" label="Página inicial" :options="startPages" placeholder="Primeiro item do menu" searchable />
        <p class="field-hint">Tela aberta logo depois do login.</p>
      </div>
    </div>

    <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

    <FormActions :saving="saving" cancel-label="Descartar alterações" cancel-icon="fa-solid fa-rotate-left" @cancel="reset" />
  </form>
</template>

<script setup>
import { computed, ref } from 'vue'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'
import { navigationItems } from '@/router'
import Select from '@/components/utils/Select.vue'
import Switch from '@/components/utils/Switch.vue'
import FormActions from '@/components/ui/FormActions.vue'

const DENSITIES = [
  { value: 'comfortable', label: 'Confortável', icon: 'fa-solid fa-grip-lines' },
  { value: 'compact', label: 'Compacta', icon: 'fa-solid fa-bars' },
]

const auth = useAuthStore()
const saving = ref(false)
const error = ref('')

// Only screens this user can open (same list as the menu).
const startPages = computed(() =>
  navigationItems(auth.user)
    .filter((item) => item.sidebar !== false)
    .map((item) => ({ value: item.route, label: item.module && item.module !== item.label ? `${item.label} · ${item.module}` : item.label, icon: item.icon })),
)

const saved = () => ({
  sidebar_expanded: auth.preferences.sidebar_expanded ?? window.innerWidth >= 1200,
  table_density: auth.preferences.table_density || 'comfortable',
  start_page: auth.preferences.start_page || '',
})
const form = ref(saved())

const reset = () => (form.value = saved())

const save = async () => {
  saving.value = true
  error.value = ''
  try {
    await auth.savePreferences({ ...form.value, start_page: form.value.start_page || null })
    swal.toastSuccess('Preferências salvas.')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.intro {
  margin: 0 0 18px;
  font-size: $font-md;
  color: $color-text-secondary;
}
</style>
