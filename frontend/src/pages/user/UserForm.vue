<template>
    <div class="page fade-in">
        <PageHeader :title="isEdit ? 'Editar Usuário' : 'Novo Usuário'" :subtitle="isEdit ? 'Atualize as informações do usuário selecionado.' : 'Cadastre um novo usuário administrador no sistema.'" :icon="isEdit ? 'fa-solid fa-user-pen' : 'fa-solid fa-user-plus'" />

        <div class="form-card">
            <div v-if="loading" class="loading-state">
                <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
                <span>Carregando dados do usuário...</span>
            </div>

            <form v-else @submit.prevent="submitForm">
                <Tabs
                    v-if="canManagePermissions"
                    v-model="activeTab"
                    :tabs="[
                        { value: 'general', label: 'Dados', icon: 'fa-solid fa-id-card' },
                        { value: 'permissions', label: 'Permissões', icon: 'fa-solid fa-shield-halved', count: form.permissions.length },
                    ]"
                />

                <div v-show="activeTab === 'general'" class="form-grid">
                    <div class="col-6">
                        <Input v-model="form.name" label="Nome Completo" placeholder="Digite o nome..." required />
                    </div>

                    <div class="col-6">
                        <Input v-model="form.email" label="Endereço de E-mail" type="email" placeholder="exemplo@empresa.com" required />
                    </div>

                    <Input class="col-10" v-model="form.password" :label="passwordLabel" type="password" :required="!isEdit" placeholder="Mínimo 8 caracteres..." />

                    <!-- No master option: master accounts exist only in the database (design.md §8). -->
                    <Switch class="col-2" v-model="form.active" label="Situação" :text-label="form.active ? 'Ativo' : 'Inativo'" />
                </div>

                <UserPermissions
                    v-if="canManagePermissions && activeTab === 'permissions'"
                    v-model="form.permissions"
                    :catalog="catalog"
                />

                <div v-if="error" class="form-error" style="margin-top: 16px">
                    {{ error }}
                </div>

                <FormActions :saving="saving" @cancel="$router.push('/user')" />
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { apiFetch } from '@/services/api'
import Input from '@/components/utils/Input.vue'
import Switch from '@/components/utils/Switch.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import FormActions from '@/components/ui/FormActions.vue'
import Tabs from '@/components/ui/Tabs.vue'
import UserPermissions from './UserPermissions.vue'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const auth = useAuthStore()

const userId = computed(() => route.params.id)
const isEdit = computed(() => !!userId.value)
const passwordLabel = computed(() => (isEdit.value ? 'Senha de Acesso (deixe em branco para não alterar)' : 'Senha de Acesso'))

const loading = ref(false)
const saving = ref(false)
const error = ref('')

const form = ref({
    name: '',
    email: '',
    password: '',
    active: true,
    permissions: [],
})

const activeTab = ref('general')
const catalog = ref([])
const savedPermissions = ref([])
const canManagePermissions = computed(() => auth.can('users.permissions'))

const fetchCatalog = async () => {
    if (!canManagePermissions.value) return
    const response = await apiFetch('/permissions')
    if (response.ok) catalog.value = await response.json()
}

const sameKeys = (a, b) => a.length === b.length && a.every((key) => b.includes(key))

const fetchUser = async () => {
    if (!isEdit.value) return

    loading.value = true
    error.value = ''
    try {
        const response = await apiFetch(`/users/${userId.value}`)
        if (!response.ok) {
            throw new Error('Não foi possível carregar os dados deste usuário.')
        }
        const data = await response.json()
        form.value.name = data.name
        form.value.email = data.email
        form.value.password = ''
        form.value.active = data.active ?? true
        form.value.permissions = data.permissions ?? []
        savedPermissions.value = [...form.value.permissions]
    } catch (err) {
        error.value = err.message
    } finally {
        loading.value = false
    }
}

const submitForm = async () => {
    saving.value = true
    error.value = ''

    const payload = {
        name: form.value.name,
        email: form.value.email,
        active: form.value.active,
    }

    if (form.value.password) {
        payload.password = form.value.password
    }

    const url = isEdit.value ? `/users/${userId.value}` : '/users'
    const method = isEdit.value ? 'PUT' : 'POST'

    try {
        const response = await apiFetch(url, {
            method,
            body: JSON.stringify(payload),
        })

        const data = await response.json()

        if (!response.ok) {
            throw new Error(data.message || 'Erro ao processar requisição.')
        }

        if (canManagePermissions.value && !sameKeys(form.value.permissions, savedPermissions.value)) {
            const permResponse = await apiFetch(`/users/${data.id}/permissions`, {
                method: 'PUT',
                body: JSON.stringify({ permissions: form.value.permissions }),
            })
            if (!permResponse.ok) {
                const permData = await permResponse.json()
                activeTab.value = 'permissions'
                // User may already be saved: switch to edit so a retry doesn't create it twice.
                if (!isEdit.value) router.replace(`/user/form/${data.id}`)
                throw new Error(permData.message || 'Usuário salvo, mas não foi possível salvar as permissões.')
            }
        }

        // Editing yourself: reload your own permissions so menus/guards match right away.
        if (data.id === auth.user?.id) await auth.refreshUser()

        router.push('/user')
    } catch (err) {
        error.value = err.message
    } finally {
        saving.value = false
    }
}

onMounted(() => Promise.all([fetchUser(), fetchCatalog()]))
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
</style>
