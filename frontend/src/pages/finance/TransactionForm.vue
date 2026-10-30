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
          <Select class="col-3" v-model="form.type" label="Tipo" :options="options.types" :disabled="readOnly || locked" @change="onTypeChange" />
          <Input id="tx-amount" class="col-3" v-model="form.amount" label="Valor" mask="money" placeholder="0,00" required :disabled="locked">
            <template #prefix>R$</template>
          </Input>
          <Input class="col-3" v-model="form.date" label="Data" type="date" required :disabled="locked" />
          <Select class="col-3" v-model="form.status" label="Status" :options="options.statuses" :disabled="readOnly || locked" />

          <Input class="col-8" v-model="form.description" label="Descrição" placeholder="Ex.: Compra do supermercado" required maxlength="255" />
          <CategorySelect class="col-4" v-model="form.category_id" label="Categoria" :type="form.type" placeholder="Selecione..." :disabled="readOnly" />

          <div v-if="form.type === 'income' && auth.can('customers.view')" class="col-8">
            <CustomerSelect v-model="form.customer_id" label="Cliente" placeholder="Nenhum" clearable :disabled="readOnly" />
            <p class="field-hint">Com um cliente, a receita aparece no portal dele (pendente = conta a pagar).</p>
          </div>

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

        <!-- Facts an integration brought: shown as plain text, nothing in here can be edited. -->
        <section v-if="integration" class="locked" aria-labelledby="locked-title">
          <header class="locked__head">
            <img v-if="integrationMeta" :src="integrationMeta.logo" alt="" width="22" height="22" />
            <h3 id="locked-title">{{ integration.title }}</h3>
            <span class="locked__badge"><i class="fa-solid fa-lock" aria-hidden="true" /> Somente leitura</span>
          </header>
          <p class="locked__hint">
            Estes dados vêm do {{ integrationMeta?.name ?? 'provedor' }} e são atualizados a cada sincronização. Tipo, valor, data e
            status não podem ser editados aqui; descrição, categoria e cliente continuam seus.
          </p>
          <dl class="locked__facts">
            <div v-for="fact in integration.fields" :key="fact.label">
              <dt>{{ fact.label }}</dt>
              <dd>{{ showFact(fact) }}</dd>
            </div>
          </dl>
        </section>

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
import CustomerSelect from '@/components/customer/CustomerSelect.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'
import { toDateString } from '@/utils/period'
import { formatMoney } from '@/utils/money'
import { formatDate } from '@/utils/date'
import { INTEGRATIONS } from '@/integrations/catalog'

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
// Facts of the provider an entry came from (Mercado Pago...): null for manual entries.
const integration = ref(null)
const locked = computed(() => !!integration.value)
const integrationMeta = computed(() => INTEGRATIONS[integration.value?.provider])
const options = ref({ types: [], statuses: [], sources: [] })
// New entries start with the company's default status (Configurações › Financeiro); Metas' "Aportar"/"Resgatar"
// open it with ?type=&category_id= already picked.
const form = ref({
  type: route.query.type === 'income' ? 'income' : 'expense',
  amount: '',
  date: toDateString(new Date()),
  status: auth.settings.finance.default_status || 'paid',
  description: '',
  category_id: Number(route.query.category_id) || '',
  customer_id: '',
})
const repeat = ref({ mode: 'none', count: 2 })
// Set by "Salvar e lançar outra" right before the form submits (Enter / "Salvar" leave it false).
let another = false

const sourceBadge = computed(() => {
  const option = options.value.sources.find((o) => o.value === source.value)
  return option && { label: `Origem: ${option.label}`, color: option.color, icon: option.icon }
})

const showFact = ({ value, format }) => {
  if (format === 'money') return formatMoney(value)
  if (format === 'date') return formatDate(value)
  if (format === 'datetime') return new Date(value).toLocaleString('pt-BR')
  return value
}

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
// Only incomes are bills, so an expense drops the customer.
const onTypeChange = () => {
  form.value.category_id = ''
  if (form.value.type !== 'income') form.value.customer_id = ''
}

// The list keeps its filters in its URL: going back restores them (Metas too); opened directly, push the plain list.
const backToList = () => {
  if (/^\/finance\/(transactions|goals)(\?|$)/.test(window.history.state?.back ?? '')) router.back()
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
    if (isEdit.value) {
      source.value = data.source
      integration.value = data.integration ?? null
    }
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

/* Locked section: the provider's facts, set apart from the editable form. */
.locked {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-top: 24px;
  padding: 16px 18px;
  border: 1px dashed var(--color-border);
  border-radius: 10px;
  background: var(--color-bg-muted);
}

.locked__head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.locked__head h3 {
  margin: 0;
  color: var(--color-text);
  font-size: 1rem;
  font-weight: 700;
}

.locked__badge {
  margin-left: auto;
  color: var(--color-text-muted);
  font-size: 0.8rem;
  font-weight: 600;
}

.locked__hint {
  margin: 0;
  max-width: 70ch;
  color: var(--color-text-secondary);
  font-size: 0.85rem;
  line-height: 1.5;
}

.locked__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
  gap: 12px 20px;
  margin: 0;
}

.locked__facts dt {
  color: var(--color-text-muted);
  font-size: 0.75rem;
  font-weight: 600;
}

.locked__facts dd {
  margin: 2px 0 0;
  color: var(--color-text);
  font-size: 0.95rem;
  font-weight: 600;
  overflow-wrap: anywhere;
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
