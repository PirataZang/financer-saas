<template>
  <div v-if="loading" class="loading-state">
    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
    <span>Carregando configurações...</span>
  </div>

  <form v-else @submit.prevent="save">
    <h3 class="form-section">Lançamentos</h3>
    <div class="form-grid">
      <div class="col-4">
        <Select v-model="form.default_status" label="Status de um novo lançamento" :options="statuses" />
        <p class="field-hint">Valor inicial do formulário de transação. Lançamentos do Telegram são sempre efetivados.</p>
      </div>

      <div class="col-4">
        <Select v-model="form.default_period" label="Período inicial" :options="PERIOD_OPTIONS" />
        <p class="field-hint">Com que período a lista de transações e o dashboard financeiro abrem.</p>
      </div>
    </div>

    <h3 class="form-section">Bot do Telegram</h3>
    <div class="form-grid">
      <div class="col-6">
        <Select v-model="form.timezone" label="Fuso horário" :options="TIMEZONES" searchable />
        <p class="field-hint">Define o "hoje" dos lançamentos e dos balancetes feitos pelo bot (agora: {{ nowIn }}).</p>
      </div>
    </div>

    <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

    <FormActions :saving="saving" cancel-label="Descartar alterações" cancel-icon="fa-solid fa-rotate-left" @cancel="load" />
  </form>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'
import { PERIODS } from '@/utils/period'
import Select from '@/components/utils/Select.vue'
import FormActions from '@/components/ui/FormActions.vue'

const PERIOD_OPTIONS = PERIODS.filter((period) => period.value !== 'custom')

// "America/Sao Paulo (UTC-03:00)", Brazilian zones first.
const BRAZIL = new Set(['America/Sao_Paulo', 'America/Manaus', 'America/Cuiaba', 'America/Belem', 'America/Fortaleza', 'America/Recife',
  'America/Bahia', 'America/Porto_Velho', 'America/Rio_Branco', 'America/Noronha'])
const offset = (timeZone) =>
  new Intl.DateTimeFormat('en', { timeZone, timeZoneName: 'longOffset' }).formatToParts().find((part) => part.type === 'timeZoneName').value.replace('GMT', 'UTC')
const TIMEZONES = Intl.supportedValuesOf('timeZone')
  .map((zone) => ({ value: zone, label: `${zone.replaceAll('_', ' ')} (${offset(zone)})` }))
  .sort((a, b) => Number(BRAZIL.has(b.value)) - Number(BRAZIL.has(a.value)))

const auth = useAuthStore()
const form = ref({ default_status: 'paid', default_period: 'month', timezone: 'America/Sao_Paulo' })
const statuses = ref([])
const loading = ref(true)
const saving = ref(false)
const error = ref('')

const nowIn = computed(() =>
  new Intl.DateTimeFormat('pt-BR', { timeZone: form.value.timezone, dateStyle: 'short', timeStyle: 'short' }).format(new Date()),
)

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const response = await apiFetch('/settings/finance')
    if (!response.ok) throw new Error('Não foi possível carregar as configurações.')
    const data = await response.json()
    form.value = { ...data.values }
    statuses.value = data.statuses
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

const save = async () => {
  saving.value = true
  error.value = ''
  try {
    const response = await apiFetch('/settings/finance', { method: 'PUT', body: JSON.stringify(form.value) })
    const data = await response.json()
    if (!response.ok) throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || 'Não foi possível salvar.')
    await auth.refreshUser()
    swal.toastSuccess('Padrões do financeiro salvos.')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>
