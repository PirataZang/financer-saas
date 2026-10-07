<template>
    <div class="page fade-in">
        <PageHeader :title="isEdit ? 'Editar Cliente' : 'Novo Cliente'" :subtitle="isEdit ? 'Atualize as informações do cliente selecionado.' : 'Cadastre um novo cliente ou lead.'" :icon="isEdit ? 'fa-solid fa-user-pen' : 'fa-solid fa-user-plus'" />

        <div class="form-card">
            <div v-if="loading" class="loading-state">
                <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
                <span>Carregando dados do cliente...</span>
            </div>

            <form v-else @submit.prevent="submitForm">
                <h3 class="form-section">Informações principais</h3>
                <div class="form-grid">
                    <Input class="col-6" v-model="form.name" label="Nome" placeholder="Nome do cliente..." required minlength="2" />

                    <Input class="col-6" v-model="form.email" label="E-mail" type="email" placeholder="cliente@empresa.com" />

                    <Input class="col-4" v-model="form.phone" label="Telefone" mask="phone" placeholder="(00) 00000-0000" />

                    <Select class="col-4" v-model="form.status" label="Status" :options="statusOptions" />

                    <Switch class="col-4" v-model="form.active" label="Ativo" :text-label="form.active ? 'Ativo' : 'Inativo'" />

                    <p class="col-12 field-hint">Informe pelo menos um contato: e-mail ou telefone.</p>
                </div>

                <h3 class="form-section">Endereço</h3>
                <div class="form-grid">
                    <div class="col-3">
                        <Input v-model="form.zip_code" label="CEP" mask="cep" placeholder="00000-000" @blur="searchZipCode">
                            <template v-if="zipLoading" #suffix>
                                <i class="fa-solid fa-spinner fa-spin" aria-label="Buscando CEP" />
                            </template>
                        </Input>
                        <p v-if="zipMessage" class="field-hint" :class="{ 'is-error': zipError }" role="status">{{ zipMessage }}</p>
                    </div>

                    <Input class="col-7" v-model="form.street" label="Rua" placeholder="Rua, avenida..." />

                    <Input ref="numberInput" class="col-2" v-model="form.number" label="Número" placeholder="Ex.: 120" maxlength="20" />

                    <Input class="col-4" v-model="form.complement" label="Complemento" placeholder="Apto, sala, bloco..." />

                    <Input class="col-4" v-model="form.neighborhood" label="Bairro" />

                    <Input class="col-4" v-model="form.reference" label="Referência" placeholder="Ponto de referência" />

                    <Input class="col-6" v-model="form.city" label="Cidade" />

                    <Input class="col-3" v-model="form.state" label="Estado" placeholder="UF" />

                    <Input class="col-3" v-model="form.country" label="País" />
                </div>

                <div v-if="error" class="form-error" style="margin-top: 16px">
                    {{ error }}
                </div>

                <FormActions :saving="saving" @cancel="$router.push('/customer')" />
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { apiFetch } from '@/services/api'
import { lookupZipCode } from '@/services/zipCode'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import Switch from '@/components/utils/Switch.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'

const router = useRouter()
const route = useRoute()

const customerId = computed(() => route.params.id)
const isEdit = computed(() => !!customerId.value)

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const statusOptions = ref([])

const numberInput = ref(null)
const zipLoading = ref(false)
const zipMessage = ref('')
const zipError = ref(false)
let lastZipLookup = ''

const form = ref({
    name: '',
    email: '',
    phone: '',
    status: 'lead',
    active: true,
    zip_code: '',
    street: '',
    number: '',
    complement: '',
    neighborhood: '',
    reference: '',
    city: '',
    state: '',
    country: 'Brasil',
})

const fetchStatuses = async () => {
    const response = await apiFetch('/customers/statuses')
    if (response.ok) statusOptions.value = await response.json()
}

const fetchCustomer = async () => {
    if (!isEdit.value) return

    loading.value = true
    error.value = ''
    try {
        const response = await apiFetch(`/customers/${customerId.value}`)
        if (!response.ok) {
            throw new Error('Não foi possível carregar os dados deste cliente.')
        }
        const data = await response.json()
        Object.keys(form.value).forEach((key) => {
            form.value[key] = data[key] ?? form.value[key]
        })
        // Loaded CEP is already resolved: don't overwrite a hand-edited address.
        lastZipLookup = form.value.zip_code
    } catch (err) {
        error.value = err.message
    } finally {
        loading.value = false
    }
}

// Fills the address from the CEP; on any failure the fields stay editable for manual entry.
const searchZipCode = async () => {
    const zipCode = form.value.zip_code || ''
    zipError.value = false
    zipMessage.value = ''

    if (!zipCode || zipCode === lastZipLookup) return
    if (zipCode.length !== 8) {
        zipError.value = true
        zipMessage.value = 'CEP inválido. Informe 8 dígitos.'
        return
    }

    lastZipLookup = zipCode
    zipLoading.value = true
    const { address, error: lookupError } = await lookupZipCode(zipCode)
    zipLoading.value = false

    if (form.value.zip_code !== zipCode) return // CEP changed while loading

    if (lookupError) {
        zipError.value = true
        zipMessage.value = lookupError
        lastZipLookup = ''
        return
    }

    form.value.street = address.street || form.value.street
    form.value.neighborhood = address.neighborhood || form.value.neighborhood
    form.value.city = address.city
    form.value.state = address.state
    form.value.country = address.country
    zipMessage.value = 'Endereço preenchido pelo CEP. Confira e informe o número.'
    // The CEP never brings the number: hand focus to it.
    numberInput.value?.$el.querySelector('input')?.focus()
}

// Search as soon as the 8th digit is typed (blur also triggers it).
watch(
    () => form.value.zip_code,
    (zipCode) => {
        if (zipCode?.length === 8) searchZipCode()
    },
)

const submitForm = async () => {
    error.value = ''

    if (!form.value.email && !form.value.phone) {
        error.value = 'Informe pelo menos um contato: e-mail ou telefone.'
        return
    }

    saving.value = true

    const url = isEdit.value ? `/customers/${customerId.value}` : '/customers'
    const method = isEdit.value ? 'PUT' : 'POST'

    try {
        const response = await apiFetch(url, {
            method,
            body: JSON.stringify(form.value),
        })

        const data = await response.json()

        if (!response.ok) {
            throw new Error(data.message || 'Erro ao processar requisição.')
        }

        router.push('/customer')
    } catch (err) {
        error.value = err.message
    } finally {
        saving.value = false
    }
}

onMounted(() => Promise.all([fetchCustomer(), fetchStatuses()]))
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

.form-section {
    margin: 0 0 14px;
    font-size: 0.9rem;
    font-weight: 700;
    color: var(--color-text);
}

.form-grid + .form-section {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--color-border);
}

.field-hint {
    margin: 6px 0 0;
    font-size: 0.75rem;
    color: var(--color-text-muted);
}

.field-hint.is-error {
    color: var(--color-danger);
}
</style>
