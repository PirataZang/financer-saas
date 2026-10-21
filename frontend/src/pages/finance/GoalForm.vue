<template>
  <div class="page fade-in">
    <PageHeader
      :title="isEdit ? 'Editar Meta' : 'Nova Meta'"
      :subtitle="isEdit ? 'Atualize a meta e a categoria dela.' : 'A meta cria a própria categoria: o que for lançado nela é o dinheiro da meta.'"
      :icon="isEdit ? 'fa-solid fa-pen-to-square' : 'fa-solid fa-bullseye'"
    />

    <div class="form-card">
      <div v-if="loading" class="loading-state">
        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
        <span>Carregando meta...</span>
      </div>

      <form v-else @submit.prevent="submitForm">
        <div class="form-grid">
          <Input class="col-6" v-model="form.name" label="Nome" placeholder="Ex.: Reserva de emergência" required minlength="2" maxlength="60" />
          <Select class="col-3" v-model="form.type" label="Tipo" :options="typeOptions" />
          <Input class="col-3" v-model="form.target_amount" :label="form.type === 'spending' ? 'Teto (R$)' : 'Objetivo (R$)'" mask="money" placeholder="0,00" required />

          <p class="col-12 field-hint type-hint">
            <i class="fa-solid fa-circle-info" aria-hidden="true" />
            {{ TYPE_HINT[form.type] }}
          </p>

          <Input class="col-3" v-model="form.start_date" label="Início" type="date" required />
          <Input class="col-3" v-model="form.end_date" label="Prazo (opcional)" type="date" :min="form.start_date" />
          <p class="col-6 field-hint dates-hint">Só contam os lançamentos com data entre o início e o prazo. Sem prazo, a meta fica aberta.</p>

          <CategoryLookFields v-model:icon="form.icon" v-model:color="form.color" />

          <div class="col-12 preview">
            <span class="field-label">Pré-visualização da categoria</span>
            <StatusBadge :label="form.name || 'Meta'" :color="form.color" :icon="form.icon" />
          </div>
        </div>

        <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

        <FormActions :saving="saving" @cancel="$router.push('/finance/goals')" />
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import CategoryLookFields from '@/components/finance/CategoryLookFields.vue'
import { apiFetch } from '@/services/api'
import { toDateString } from '@/utils/period'

// Mirrors GoalService: both kinds count expenses − incomes in the goal's category.
const TYPE_HINT = {
  saving: 'Aporte = despesa na categoria da meta (sai do saldo e fica guardado nela); resgate = receita. Atinge ao juntar o objetivo.',
  spending: 'Cada despesa na categoria da meta consome o teto; estorno = receita. Atinge quem chega ao prazo sem passar do teto.',
}

const router = useRouter()
const route = useRoute()
const goalId = computed(() => route.params.id)
const isEdit = computed(() => !!goalId.value)

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const typeOptions = ref([])
const form = ref({ name: '', icon: '🏦', color: '#059669', type: 'saving', target_amount: '', start_date: toDateString(new Date()), end_date: '' })

const fetchGoal = async () => {
  if (!isEdit.value) return
  loading.value = true
  try {
    const response = await apiFetch(`/finance/goals/${goalId.value}`)
    if (!response.ok) throw new Error('Não foi possível carregar esta meta.')
    const data = await response.json()
    form.value = {
      name: data.category.name,
      icon: data.category.icon ?? '',
      color: data.category.color,
      type: data.type,
      target_amount: data.target_amount,
      start_date: data.start_date,
      end_date: data.end_date ?? '',
    }
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
    const response = await apiFetch(isEdit.value ? `/finance/goals/${goalId.value}` : '/finance/goals', {
      method: isEdit.value ? 'PUT' : 'POST',
      body: JSON.stringify({ ...form.value, end_date: form.value.end_date || null }),
    })
    const data = await response.json()
    if (!response.ok) throw new Error(data.message || 'Erro ao salvar a meta.')
    router.push('/finance/goals')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  const response = await apiFetch('/finance/goals/options')
  if (response.ok) typeOptions.value = (await response.json()).types
  await fetchGoal()
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

.type-hint,
.dates-hint {
  margin: 0;
}

.dates-hint {
  align-self: end;
}

.preview {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 8px;
}
</style>
