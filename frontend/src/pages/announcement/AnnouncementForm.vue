<template>
  <div class="page fade-in">
    <PageHeader
      :title="isEdit ? 'Editar Aviso' : 'Novo Aviso'"
      subtitle="Aparece no portal do cliente assim que salvo."
      icon="fa-solid fa-bullhorn"
    />

    <div class="form-card">
      <div v-if="loading" class="loading-state">
        <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
        <span>Carregando aviso...</span>
      </div>

      <form v-else @submit.prevent="submitForm">
        <div class="form-grid">
          <Input class="col-6" v-model="form.title" label="Título" placeholder="Ex.: Recesso de fim de ano" required minlength="2" maxlength="150" />
          <div class="col-6">
            <Select
              v-model="form.project_id"
              label="Para quem"
              :options="projectOptions"
              placeholder="Todos os clientes (aviso geral)"
              searchable
              clearable
            />
            <p class="field-hint">Com um projeto, só o cliente dele vê o aviso (também na página do projeto).</p>
          </div>
          <TextArea class="col-12" v-model="form.body" label="Mensagem" :rows="8" required maxlength="5000" />
        </div>

        <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

        <FormActions :saving="saving" @cancel="$router.push('/announcement')" />
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiFetch } from '@/services/api'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import TextArea from '@/components/utils/TextArea.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'

const router = useRouter()
const route = useRoute()

const announcementId = computed(() => route.params.id)
const isEdit = computed(() => !!announcementId.value)

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const projectOptions = ref([])
const form = ref({ title: '', project_id: '', body: '' })

const fetchAnnouncement = async () => {
  if (!isEdit.value) return
  loading.value = true
  try {
    const response = await apiFetch(`/announcements/${announcementId.value}`)
    if (!response.ok) throw new Error('Não foi possível carregar este aviso.')
    const data = await response.json()
    form.value = { title: data.title, project_id: data.project_id ?? '', body: data.body }
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
    const response = await apiFetch(isEdit.value ? `/announcements/${announcementId.value}` : '/announcements', {
      method: isEdit.value ? 'PUT' : 'POST',
      body: JSON.stringify({ ...form.value, project_id: form.value.project_id || null }),
    })
    const data = await response.json()
    if (!response.ok) throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || 'Erro ao salvar o aviso.')
    router.push('/announcement')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  const response = await apiFetch('/projects/options')
  if (response.ok) projectOptions.value = await response.json()
  await fetchAnnouncement()
})
</script>

