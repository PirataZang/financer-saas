<template>
  <div class="page fade-in">
    <PageHeader
      :title="isEdit ? 'Editar Categoria' : 'Nova Categoria'"
      :subtitle="isEdit ? 'Atualize a categoria selecionada.' : 'Cadastre uma categoria de receita ou despesa.'"
      :icon="isEdit ? 'fa-solid fa-pen-to-square' : 'fa-solid fa-tag'"
    />

    <div class="form-card">
      <div v-if="loading" class="loading-state">
        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
        <span>Carregando categoria...</span>
      </div>

      <form v-else @submit.prevent="submitForm">
        <div class="form-grid">
          <Input class="col-6" v-model="form.name" label="Nome" placeholder="Ex.: Mercado" required minlength="2" maxlength="60" />
          <Select class="col-3" v-model="form.type" label="Aceita" :options="typeOptions" />
          <Input class="col-3" v-model.number="form.sort_order" label="Ordem" type="number" min="0" max="9999" />

          <CategoryLookFields v-model:icon="form.icon" v-model:color="form.color" />

          <Switch class="col-3" v-model="form.active" label="Status" :text-label="form.active ? 'Ativa' : 'Inativa'" />

          <div class="col-9 preview">
            <span class="field-label">Pré-visualização</span>
            <StatusBadge :label="form.name || 'Categoria'" :color="form.color" :icon="form.icon" />
          </div>
        </div>

        <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

        <FormActions :saving="saving" @cancel="$router.push('/finance/categories')" />
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import Switch from '@/components/utils/Switch.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import CategoryLookFields from '@/components/finance/CategoryLookFields.vue'
import { apiFetch } from '@/services/api'

const router = useRouter()
const route = useRoute()
const categoryId = computed(() => route.params.id)
const isEdit = computed(() => !!categoryId.value)

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const typeOptions = ref([])
const form = ref({ name: '', icon: '', color: '#2563eb', type: 'both', active: true, sort_order: 0 })

const fetchCategory = async () => {
  if (!isEdit.value) return
  loading.value = true
  try {
    const response = await apiFetch(`/finance/categories/${categoryId.value}`)
    if (!response.ok) throw new Error('Não foi possível carregar esta categoria.')
    const data = await response.json()
    Object.keys(form.value).forEach((key) => {
      form.value[key] = data[key] ?? form.value[key]
    })
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

const submitForm = async () => {
  error.value = ''
  saving.value = true
  try {
    const response = await apiFetch(isEdit.value ? `/finance/categories/${categoryId.value}` : '/finance/categories', {
      method: isEdit.value ? 'PUT' : 'POST',
      body: JSON.stringify(form.value),
    })
    const data = await response.json()
    if (!response.ok) throw new Error(data.message || 'Erro ao salvar a categoria.')
    router.push('/finance/categories')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  const response = await apiFetch('/finance/categories/types')
  if (response.ok) typeOptions.value = await response.json()
  await fetchCategory()
})
</script>

<style scoped>
.loading-state {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 48px 24px;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.preview {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
}
</style>
