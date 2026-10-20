<template>
  <div class="page fade-in">
    <PageHeader
      :title="isEdit ? 'Editar Projeto' : 'Novo Projeto'"
      subtitle="O cliente acompanha o status, as etapas e o progresso no portal do cliente."
      :icon="isEdit ? 'fa-solid fa-pen-ruler' : 'fa-solid fa-diagram-project'"
    />

    <div class="form-card">
      <div v-if="loading" class="loading-state">
        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
        <span>Carregando projeto...</span>
      </div>

      <form v-else @submit.prevent="submitForm">
        <h3 class="form-section">Projeto</h3>
        <div class="form-grid">
          <Input class="col-6" v-model="form.name" label="Nome" placeholder="Ex.: Site institucional" required minlength="2" maxlength="120" />
          <CustomerSelect class="col-6" v-model="form.customer_id" label="Cliente" placeholder="Selecione o cliente..." />
          <Select class="col-4" v-model="form.status" label="Status" :options="statusOptions" />
          <Input class="col-4" v-model="form.start_date" label="Início" type="date" />
          <Input class="col-4" v-model="form.due_date" label="Previsão de entrega" type="date" :min="form.start_date || undefined" />
          <TextArea class="col-12" v-model="form.description" label="Descrição" placeholder="O que será entregue (o cliente vê este texto)" :rows="4" maxlength="5000" />
        </div>

        <div class="steps-head">
          <h3 class="form-section">Etapas</h3>
          <span class="steps-progress">Progresso: <b>{{ progress }}%</b></span>
        </div>
        <p class="field-hint steps-hint">Na ordem em que acontecem. O progresso é a fração de etapas concluídas.</p>

        <ol v-if="form.steps.length" class="steps">
          <li v-for="(step, index) in form.steps" :key="step.key" class="step">
            <span class="step__number" aria-hidden="true">{{ index + 1 }}</span>
            <div class="step__fields">
              <Input v-model="step.name" :aria-label="`Nome da etapa ${index + 1}`" placeholder="Nome da etapa" required maxlength="120" />
              <Select v-model="step.status" :options="stepStatusOptions" :aria-label="`Status da etapa ${index + 1}`" />
              <Input v-model="step.due_date" type="date" :aria-label="`Prazo da etapa ${index + 1}`" title="Prazo (opcional)" />
            </div>
            <div class="step__actions">
              <Button variant="ghost" size="sm" icon-only icon="fa-solid fa-arrow-up" :title="'Subir'" :aria-label="`Subir etapa ${index + 1}`" :disabled="index === 0" @click="move(index, -1)" />
              <Button variant="ghost" size="sm" icon-only icon="fa-solid fa-arrow-down" :title="'Descer'" :aria-label="`Descer etapa ${index + 1}`" :disabled="index === form.steps.length - 1" @click="move(index, 1)" />
              <Button variant="ghost" size="sm" icon-only icon="fa-solid fa-xmark" :title="'Remover'" :aria-label="`Remover etapa ${index + 1}`" @click="form.steps.splice(index, 1)" />
            </div>
          </li>
        </ol>

        <Button class="add-step" variant="secondary" icon="fa-solid fa-plus" label="Adicionar etapa" :disabled="form.steps.length >= 50" @click="addStep" />

        <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

        <FormActions :saving="saving" @cancel="$router.push('/project')" />
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiFetch } from '@/services/api'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import TextArea from '@/components/utils/TextArea.vue'
import CustomerSelect from '@/components/customer/CustomerSelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'

const router = useRouter()
const route = useRoute()

const projectId = computed(() => route.params.id)
const isEdit = computed(() => !!projectId.value)

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const statusOptions = ref([])
const stepStatusOptions = ref([])
const form = ref({ name: '', customer_id: '', status: 'planning', start_date: '', due_date: '', description: '', steps: [] })

// Local key only for v-for while rows move around; the API replaces the whole list on save.
let nextKey = 0
const withKey = (step) => ({ ...step, key: nextKey++ })

// Mirrors Project::progress (display only).
const progress = computed(() => {
  const total = form.value.steps.length
  if (!total) return form.value.status === 'done' ? 100 : 0
  return Math.round((form.value.steps.filter((step) => step.status === 'done').length * 100) / total)
})

const addStep = async () => {
  form.value.steps.push(withKey({ name: '', status: 'pending', due_date: '' }))
  await nextTick()
  document.querySelector('.step:last-child input')?.focus()
}

const move = (index, delta) => {
  const steps = form.value.steps
  ;[steps[index], steps[index + delta]] = [steps[index + delta], steps[index]]
}

const fetchProject = async () => {
  if (!isEdit.value) return
  loading.value = true
  try {
    const response = await apiFetch(`/projects/${projectId.value}`)
    if (!response.ok) throw new Error('Não foi possível carregar este projeto.')
    const data = await response.json()
    form.value = {
      name: data.name,
      customer_id: data.customer_id,
      status: data.status,
      start_date: data.start_date ?? '',
      due_date: data.due_date ?? '',
      description: data.description ?? '',
      steps: data.steps.map(({ name, status, due_date }) => withKey({ name, status, due_date: due_date ?? '' })),
    }
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

const submitForm = async () => {
  error.value = ''
  if (!form.value.customer_id) {
    error.value = 'Selecione o cliente do projeto.'
    return
  }

  saving.value = true
  try {
    const payload = { ...form.value, steps: form.value.steps.map(({ key, ...step }) => step) }
    const response = await apiFetch(isEdit.value ? `/projects/${projectId.value}` : '/projects', {
      method: isEdit.value ? 'PUT' : 'POST',
      body: JSON.stringify(payload),
    })
    const data = await response.json()
    if (!response.ok) throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || 'Erro ao salvar o projeto.')
    router.push('/project')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  const response = await apiFetch('/projects/statuses')
  if (response.ok) {
    const data = await response.json()
    statusOptions.value = data.statuses
    stepStatusOptions.value = data.step_statuses
  }
  await fetchProject()
})
</script>

<style scoped>
.steps-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin-top: 24px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}

.steps-head .form-section {
  margin: 0;
}

.steps-progress {
  color: var(--color-text-muted);
  font-size: 0.85rem;
}

.steps-progress b {
  color: var(--color-text);
}


.steps-hint {
  margin: 4px 0 14px;
}

.steps {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin: 0 0 14px;
  padding: 0;
  list-style: none;
}

.step {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  background: var(--color-bg-hover);
}

.step__number {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 999px;
  background: var(--color-primary-soft);
  color: var(--color-primary);
  font-size: 0.8rem;
  font-weight: 700;
}

.step__fields {
  display: grid;
  flex: 1;
  grid-template-columns: minmax(0, 1fr) 190px 170px;
  gap: 10px;
  min-width: 0;
}

.step__actions {
  display: flex;
  flex-shrink: 0;
  gap: 2px;
}

@media (max-width: 768px) {
  .step {
    align-items: flex-start;
  }

  .step__fields {
    grid-template-columns: 1fr;
  }

  .step__actions {
    flex-direction: column;
  }
}
</style>
