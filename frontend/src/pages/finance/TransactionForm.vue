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
          <Input id="tx-amount" class="col-3" v-model="form.amount" label="Valor" mask="money" placeholder="0,00" required>
            <template #prefix>R$</template>
          </Input>
          <Input class="col-3" v-model="form.date" label="Data" type="date" required />
          <Select class="col-3" v-model="form.status" label="Status" :options="options.statuses" :disabled="readOnly" />

          <Input class="col-8" v-model="form.description" label="Descrição" placeholder="Ex.: Compra do supermercado" required maxlength="255" />
          <CategorySelect class="col-4" v-model="form.category_id" label="Categoria" :type="form.type" placeholder="Selecione..." :disabled="readOnly" />

          <!-- A series is created at once; afterwards each entry is edited on its own. -->
          <template v-if="!isEdit">
            <Select class="col-4" v-model="repeat.mode" label="Repetição" :options="REPEAT_OPTIONS" />
            <Input v-if="repeat.mode !== 'none'" class="col-2" v-model="repeat.count" label="Vezes" type="number" min="2" max="120" required />
            <p v-if="repeatHint" class="col-6 repeat-hint">
              <i class="fa-solid fa-circle-info" aria-hidden="true" />
              {{ repeatHint }}
            </p>
          </template>
        </fieldset>

        <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

        <FormActions v-if="!readOnly" :saving="saving" @cancel="backToList">
          <Button
            v-if="!isEdit"
            native-type="submit"
            variant="secondary"
            icon="fa-solid fa-plus"
            label="Salvar e lançar outra"
            title="Salva e limpa valor e descrição; tipo, data, categoria e status continuam"
            :disabled="saving"
            @click="another = true"
          />
        </FormActions>
        <div v-else class="form-actions">
          <Button variant="secondary" icon="fa-solid fa-arrow-left" label="Voltar" @click="backToList" />
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import CategorySelect from '@/components/finance/CategorySelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'
import { toDateString } from '@/utils/period'
import { formatMoney } from '@/utils/money'

const REPEAT_OPTIONS = [
  { value: 'none', label: 'Não repete' },
  { value: 'installments', label: 'Parcelado (divide o valor)' },
  { value: 'monthly', label: 'Todo mês (mesmo valor)' },
]

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const transactionId = computed(() => route.params.id)
const isEdit = computed(() => !!transactionId.value)
// "Duplicar" on the list: a new entry pre-filled from another one (?duplicate=<id>), dated today.
const duplicateId = computed(() => (isEdit.value ? null : route.query.duplicate))
// The edit route only needs .view: without .update the form is a read-only "Visualizar".
const readOnly = computed(() => isEdit.value && !auth.can('finance.transaction.update'))
const title = computed(() => (!isEdit.value ? 'Nova Transação' : readOnly.value ? 'Transação' : 'Editar Transação'))
const subtitle = computed(() => {
  if (isEdit.value) return 'Detalhes do lançamento.'
  return duplicateId.value ? 'Cópia de outro lançamento, com a data de hoje.' : 'Lance uma receita ou despesa.'
})

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const source = ref('')
const options = ref({ types: [], statuses: [], sources: [] })
// New entries start with the company's default status (Configurações › Financeiro).
const form = ref({ type: 'expense', amount: '', date: toDateString(new Date()), status: auth.settings.finance.default_status || 'paid', description: '', category_id: '' })
const repeat = ref({ mode: 'none', count: 2 })
// Set by "Salvar e lançar outra" right before the form submits (Enter / "Salvar" leave it false).
let another = false

const sourceBadge = computed(() => {
  const option = options.value.sources.find((o) => o.value === source.value)
  return option && { label: `Origem: ${option.label}`, color: option.color, icon: option.icon }
})

// Mirrors TransactionService::createSeries (display only; the API does the real split).
const repeatHint = computed(() => {
  const count = Number(repeat.value.count)
  const cents = Math.round(Number(form.value.amount) * 100)
  if (repeat.value.mode === 'none' || !cents || count < 2 || count > 120) return ''
  const later = 'Os de datas futuras ficam pendentes.'
  if (repeat.value.mode === 'monthly') {
    return `${formatMoney(cents / 100)} por mês durante ${count} meses (total ${formatMoney((cents * count) / 100)}). ${later}`
  }
  if (cents < count) return 'Valor pequeno demais para tantas parcelas.'
  const part = Math.floor(cents / count)
  const first = part + (cents % count)
  return `${count}× de ${formatMoney(part / 100)}${first !== part ? ` (a 1ª de ${formatMoney(first / 100)})` : ''}. ${later}`
})

// The category list follows the type; a category that doesn't accept the new type must be re-picked.
const onTypeChange = () => {
  form.value.category_id = ''
}

// The list keeps its filters in its URL: going back restores them; opened directly, push the plain list.
const backToList = () => {
  if (/^\/finance\/transactions(\?|$)/.test(window.history.state?.back ?? '')) router.back()
  else router.push('/finance/transactions')
}

const fetchTransaction = async () => {
  const id = transactionId.value || duplicateId.value
  if (!id) return
  loading.value = true
  try {
    const response = await apiFetch(`/finance/transactions/${id}`)
    if (!response.ok) throw new Error('Não foi possível carregar esta transação.')
    const data = await response.json()
    Object.keys(form.value).forEach((key) => {
      form.value[key] = data[key] ?? form.value[key]
    })
    if (isEdit.value) source.value = data.source
    else form.value.date = toDateString(new Date())
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

const submitForm = async () => {
  const stay = another
  another = false
  error.value = ''
  saving.value = true
  const series = !isEdit.value && repeat.value.mode !== 'none'
  try {
    const response = await apiFetch(isEdit.value ? `/finance/transactions/${transactionId.value}` : '/finance/transactions', {
      method: isEdit.value ? 'PUT' : 'POST',
      body: JSON.stringify(series ? { ...form.value, repeat: repeat.value.mode, repeat_count: Number(repeat.value.count) } : form.value),
    })
    const data = await response.json()
    if (!response.ok) throw new Error(data.message || 'Erro ao salvar a transação.')
    if (series) swal.toastSuccess(`${repeat.value.count} lançamentos criados!`)
    if (!stay) return backToList()

    if (!series) swal.toastSuccess('Transação lançada!')
    // Type, date, category and status stay: the next entry is usually alike.
    form.value = { ...form.value, amount: '', description: '' }
    repeat.value = { mode: 'none', count: 2 }
    await nextTick()
    document.getElementById('tx-amount')?.focus()
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

/* Sits on the field row, aligned with the inputs (not their labels). */
.repeat-hint {
  display: flex;
  align-items: center;
  gap: 8px;
  align-self: end;
  min-height: 42px;
  margin: 0;
  color: var(--color-text-muted);
  font-size: 0.85rem;
}
</style>
