<template>
  <div class="page fade-in">
    <PageHeader :title="title" :subtitle="subtitle" :icon="isEdit ? 'fa-solid fa-receipt' : 'fa-solid fa-plus'">
      <StatusBadge v-if="sourceBadge" v-bind="sourceBadge" />
    </PageHeader>

    <div class="form-card">
      <div v-if="loading" class="loading-state">
        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
        <span>Carregando transação...</span>
      </div>

      <form v-else @submit.prevent="submitForm">
        <fieldset class="form-grid" :disabled="readOnly">
          <Select class="col-3" v-model="form.type" label="Tipo" :options="options.types" :disabled="readOnly" @change="onTypeChange" />
          <Input class="col-3" v-model="form.amount" label="Valor (R$)" type="number" min="0.01" step="0.01" placeholder="0,00" required />
          <Input class="col-3" v-model="form.date" label="Data" type="date" required />
          <Select class="col-3" v-model="form.status" label="Status" :options="options.statuses" :disabled="readOnly" />

          <Input class="col-8" v-model="form.description" label="Descrição" placeholder="Ex.: Compra do supermercado" required maxlength="255" />
          <CategorySelect class="col-4" v-model="form.category_id" label="Categoria" :type="form.type" placeholder="Selecione..." :disabled="readOnly" />
        </fieldset>

        <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

        <FormActions v-if="!readOnly" :saving="saving" @cancel="$router.push('/finance/transactions')" />
        <div v-else class="form-actions">
          <Button variant="secondary" icon="fa-solid fa-arrow-left" label="Voltar" @click="$router.push('/finance/transactions')" />
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import CategorySelect from '@/components/finance/CategorySelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { apiFetch } from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import { toDateString } from '@/utils/period'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const transactionId = computed(() => route.params.id)
const isEdit = computed(() => !!transactionId.value)
// The edit route only needs .view: without .update the form is a read-only "Visualizar".
const readOnly = computed(() => isEdit.value && !auth.can('finance.transaction.update'))
const title = computed(() => (!isEdit.value ? 'Nova Transação' : readOnly.value ? 'Transação' : 'Editar Transação'))
const subtitle = computed(() => (isEdit.value ? 'Detalhes do lançamento.' : 'Lance uma receita ou despesa.'))

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const source = ref('')
const options = ref({ types: [], statuses: [], sources: [] })
const form = ref({ type: 'expense', amount: '', date: toDateString(new Date()), status: 'paid', description: '', category_id: '' })

const sourceBadge = computed(() => {
  const option = options.value.sources.find((o) => o.value === source.value)
  return option && { label: `Origem: ${option.label}`, color: option.color, icon: option.icon }
})

// The category list follows the type; a category that doesn't accept the new type must be re-picked.
const onTypeChange = () => {
  form.value.category_id = ''
}

const fetchTransaction = async () => {
  if (!isEdit.value) return
  loading.value = true
  try {
    const response = await apiFetch(`/finance/transactions/${transactionId.value}`)
    if (!response.ok) throw new Error('Não foi possível carregar esta transação.')
    const data = await response.json()
    Object.keys(form.value).forEach((key) => {
      form.value[key] = data[key] ?? form.value[key]
    })
    source.value = data.source
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
    const response = await apiFetch(isEdit.value ? `/finance/transactions/${transactionId.value}` : '/finance/transactions', {
      method: isEdit.value ? 'PUT' : 'POST',
      body: JSON.stringify(form.value),
    })
    const data = await response.json()
    if (!response.ok) throw new Error(data.message || 'Erro ao salvar a transação.')
    router.push('/finance/transactions')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  const response = await apiFetch('/finance/transactions/options')
  if (response.ok) options.value = await response.json()
  await fetchTransaction()
})
</script>

<style scoped>
fieldset {
  border: 0;
  margin: 0;
  padding: 0;
  min-width: 0;
}

.loading-state {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  padding: 48px 24px;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}
</style>
