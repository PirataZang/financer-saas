<template>
  <div v-if="loading" class="loading-state">
    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
    <span>Carregando dados da empresa...</span>
  </div>

  <form v-else @submit.prevent="save">
    <CompanyFields ref="fields" v-model="form" />

    <h3 class="form-section portal-section">Portal do cliente</h3>
    <p class="field-hint portal-hint">Envie este link aos seus clientes para acompanharem projetos, contas e avisos.</p>
    <PortalLinkField v-if="portalSlug" :slug="portalSlug" :company-name="form.trade_name || form.name" />

    <p class="field-hint license-note">
      <i class="fa-solid fa-lock" aria-hidden="true" />
      Licença e token de acesso são gerenciados pelo suporte.
    </p>

    <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

    <FormActions :saving="saving" cancel-label="Descartar alterações" cancel-icon="fa-solid fa-rotate-left" @cancel="load" />
  </form>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'
import CompanyFields from '@/components/company/CompanyFields.vue'
import FormActions from '@/components/ui/FormActions.vue'
import PortalLinkField from '@/components/portal/PortalLinkField.vue'

const FIELDS = ['name', 'trade_name', 'document', 'description', 'zip_code', 'street', 'number', 'neighborhood', 'city', 'state', 'country']

const auth = useAuthStore()
const form = ref(Object.fromEntries(FIELDS.map((key) => [key, ''])))
const fields = ref(null)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const portalSlug = ref('')

const apply = (data) => {
  FIELDS.forEach((key) => (form.value[key] = data[key] ?? ''))
  portalSlug.value = data.portal_slug || portalSlug.value
}

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const response = await apiFetch('/settings/company')
    if (!response.ok) throw new Error('Não foi possível carregar os dados da empresa.')
    apply(await response.json())
    fields.value?.markZipLoaded()
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
    const response = await apiFetch('/settings/company', { method: 'PUT', body: JSON.stringify(form.value) })
    const data = await response.json()
    if (!response.ok) throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || 'Não foi possível salvar.')
    apply(data)
    // The header pill shows the company name.
    await auth.refreshUser()
    swal.toastSuccess('Dados da empresa atualizados.')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped>
/* Same divider as .form-grid + .form-section: the previous grid lives inside CompanyFields. */
.portal-section {
  margin-top: 24px;
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}

.portal-hint {
  margin: 0 0 12px;
}

.license-note {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 20px;
}
</style>
