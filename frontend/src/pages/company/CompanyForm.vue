<template>
    <div class="page fade-in">
        <PageHeader :title="isEdit ? 'Editar Empresa' : 'Nova Empresa'" :subtitle="isEdit ? 'Dados cadastrais e licença de acesso da empresa.' : 'Cadastre uma empresa cliente da plataforma.'" :icon="isEdit ? 'fa-solid fa-pen-to-square' : 'fa-solid fa-building-circle-check'" />

        <div class="form-card">
            <div v-if="loading" class="loading-state">
                <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
                <span>Carregando dados da empresa...</span>
            </div>

            <form v-else @submit.prevent="submitForm">
                <h3 class="form-section">Dados da empresa</h3>
                <div class="form-grid">
                    <Input class="col-6" v-model="form.name" label="Razão social / Nome" placeholder="Nome registrado da empresa..." required minlength="2" />

                    <Input class="col-6" v-model="form.trade_name" label="Nome fantasia" placeholder="Como a empresa é conhecida" />

                    <Input class="col-4" v-model="form.document" label="CPF / CNPJ" mask="cpf-cnpj" placeholder="000.000.000-00" />

                    <TextArea class="col-12" v-model="form.description" label="Descrição" placeholder="Ramo de atuação, observações do contrato..." :rows="3" />
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

                    <Input class="col-4" v-model="form.neighborhood" label="Bairro" />

                    <Input class="col-4" v-model="form.city" label="Cidade" />

                    <Input class="col-2" v-model="form.state" label="Estado" placeholder="UF" />

                    <Input class="col-2" v-model="form.country" label="País" />
                </div>

                <div class="license-heading">
                    <h3 class="form-section">Licença de acesso</h3>
                    <StatusBadge v-if="isEdit" v-bind="license" />
                </div>
                <div class="form-grid">
                    <div class="col-3">
                        <Input v-model="form.expires_at" type="date" label="Expira em" />
                        <p class="field-hint">Vazio = sem expiração. O dia informado ainda tem acesso. Com data, gerar token renova para hoje + 30 dias.</p>
                    </div>

                    <div v-if="isEdit" class="col-9">
                        <Input :model-value="token || ''" label="Token" readonly placeholder="Sem token: acesso bloqueado" class="token-field">
                            <template v-if="token" #suffix>
                                <button type="button" class="token-copy" :aria-label="copied ? 'Token copiado' : 'Copiar token'" @click="copyToken">
                                    <i :class="copied ? 'fa-solid fa-check' : 'fa-regular fa-copy'" aria-hidden="true" />
                                </button>
                            </template>
                        </Input>
                        <div class="token-actions">
                            <Button size="sm" variant="secondary" icon="fa-solid fa-rotate" :label="token ? 'Gerar novo token' : 'Gerar token'" :disabled="tokenBusy" @click="changeToken('POST')" />
                            <Button v-if="token" size="sm" variant="danger" icon="fa-solid fa-ban" label="Revogar acesso" :disabled="tokenBusy" @click="changeToken('DELETE')" />
                        </div>
                        <p class="field-hint">Sem token a empresa fica bloqueada na hora. O token também identifica a empresa no formulário público de clientes; um novo token invalida o anterior.</p>
                    </div>

                    <p v-else class="col-9 field-hint license-note">O token de acesso é gerado ao salvar e a licença já começa ativa.</p>
                </div>

                <div v-if="error" class="form-error" style="margin-top: 16px">
                    {{ error }}
                </div>

                <FormActions :saving="saving" @cancel="$router.push('/restricted/company')" />
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { apiFetch } from '@/services/api'
import { useZipCodeLookup } from '@/services/zipCode'
import { swal } from '@/plugins/swal'
import Input from '@/components/utils/Input.vue'
import TextArea from '@/components/utils/TextArea.vue'
import Button from '@/components/utils/Button.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { LICENSE_OPTIONS } from './license'

const router = useRouter()
const route = useRoute()

const companyId = computed(() => route.params.id)
const isEdit = computed(() => !!companyId.value)

const loading = ref(false)
const saving = ref(false)
const error = ref('')
const numberInput = ref(null)

const token = ref(null)
const active = ref(false)
const tokenBusy = ref(false)
const copied = ref(false)

const form = ref({
    name: '',
    trade_name: '',
    document: '',
    description: '',
    zip_code: '',
    street: '',
    number: '',
    neighborhood: '',
    city: '',
    state: '',
    country: 'Brasil',
    expires_at: '',
})

const { zipLoading, zipMessage, zipError, searchZipCode, markZipLoaded } = useZipCodeLookup(form, numberInput)

const license = computed(() => LICENSE_OPTIONS.find((option) => option.value === active.value))

const applyCompany = (data) => {
    Object.keys(form.value).forEach((key) => {
        form.value[key] = data[key] ?? ''
    })
    token.value = data.token
    active.value = data.active
}

const fetchCompany = async () => {
    if (!isEdit.value) return

    loading.value = true
    error.value = ''
    try {
        const response = await apiFetch(`/companies/${companyId.value}`)
        if (!response.ok) throw new Error('Não foi possível carregar os dados desta empresa.')
        applyCompany(await response.json())
        markZipLoaded()
    } catch (err) {
        error.value = err.message
    } finally {
        loading.value = false
    }
}

// POST = new token (license back on), DELETE = revoke (blocked immediately).
const changeToken = async (method) => {
    const revoking = method === 'DELETE'
    const ok = await swal.confirm({
        title: revoking ? 'Revogar acesso' : 'Gerar novo token',
        text: revoking
            ? 'Todos os usuários desta empresa perdem o acesso agora, até um novo token ser gerado.'
            : 'O token atual deixa de valer. Formulários públicos com o token antigo param de funcionar.'
              + (form.value.expires_at ? ' A expiração passa para daqui a 30 dias.' : ''),
        confirmButtonText: revoking ? 'Sim, revogar' : 'Gerar token',
    })
    if (!ok) return

    tokenBusy.value = true
    try {
        const response = await apiFetch(`/companies/${companyId.value}/token`, { method })
        const data = await response.json()
        if (!response.ok) throw new Error(data.message || 'Não foi possível alterar o token.')
        token.value = data.token
        active.value = data.active
        form.value.expires_at = data.expires_at ?? ''
        swal.toastSuccess(revoking ? 'Acesso revogado.' : 'Novo token gerado.')
    } catch (err) {
        swal.toastError(err.message)
    } finally {
        tokenBusy.value = false
    }
}

const copyToken = async () => {
    await navigator.clipboard.writeText(token.value)
    copied.value = true
    setTimeout(() => (copied.value = false), 1600)
}

const submitForm = async () => {
    error.value = ''
    saving.value = true

    try {
        const response = await apiFetch(isEdit.value ? `/companies/${companyId.value}` : '/companies', {
            method: isEdit.value ? 'PUT' : 'POST',
            body: JSON.stringify({ ...form.value, expires_at: form.value.expires_at || null }),
        })
        const data = await response.json()

        if (!response.ok) {
            throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || 'Erro ao processar requisição.')
        }

        if (isEdit.value) {
            router.push('/restricted/company')
            return
        }
        // New company: open it so the generated token is right there.
        swal.toastSuccess('Empresa cadastrada. Token de acesso gerado.')
        router.replace(`/restricted/company/form/${data.id}`)
        applyCompany(data)
    } catch (err) {
        error.value = err.message
    } finally {
        saving.value = false
    }
}

onMounted(fetchCompany)
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

.form-grid + .form-section,
.form-grid + .license-heading {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--color-border);
}

.license-heading {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
}

.license-heading .form-section {
    margin: 0;
}

.field-hint {
    margin: 6px 0 0;
    font-size: 0.75rem;
    line-height: 1.45;
    color: var(--color-text-muted);
    max-width: 72ch;
}

.field-hint.is-error {
    color: var(--color-danger);
}

.license-note {
    align-self: center;
}

.token-field :deep(.field-input) {
    font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
    font-size: 0.82rem;
    letter-spacing: 0.02em;
}

.token-copy {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border: 0;
    border-radius: var(--radius-md);
    background: transparent;
    color: var(--color-text-muted);
    cursor: pointer;
    transition: background 0.15s, color 0.15s;
}

.token-copy:hover {
    background: var(--color-bg-muted);
    color: var(--color-primary);
}

.token-copy:focus-visible {
    outline: 2px solid var(--color-primary);
    outline-offset: 2px;
}

.token-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
}
</style>
