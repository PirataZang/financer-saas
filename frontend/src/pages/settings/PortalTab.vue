<template>
  <div v-if="loading" class="loading-state">
    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
    <span>Carregando configurações...</span>
  </div>

  <form v-else @submit.prevent="save">
    <h3 class="form-section">Endereço do portal</h3>
    <p class="field-hint portal-hint">
      Envie este link aos seus clientes. Eles entram com o e-mail do cadastro (Clientes) e um código que chega por e-mail, e
      acompanham os projetos, as contas (receitas pendentes vinculadas a eles) e os avisos. Ninguém altera nada pelo portal.
    </p>
    <div class="portal-link">
      <code>{{ link }}</code>
      <Button
        variant="secondary"
        :icon="copied ? 'fa-solid fa-check' : 'fa-regular fa-copy'"
        :label="copied ? 'Copiado!' : 'Copiar link'"
        @click="copy"
      />
      <Button type="link" :href="link" target="_blank" rel="noopener" variant="ghost" icon="fa-solid fa-arrow-up-right-from-square" label="Abrir" />
    </div>

    <h3 class="form-section">Pagamento</h3>
    <div class="form-grid">
      <div class="col-6">
        <Input v-model="form.pix_key" label="Chave PIX" placeholder="CNPJ, e-mail, telefone ou chave aleatória" maxlength="140" />
        <p class="field-hint">Aparece em Contas com o botão "Copiar chave PIX".</p>
      </div>
      <div class="col-12">
        <TextArea v-model="form.payment_instructions" label="Instruções de pagamento" :rows="3" maxlength="1000"
          placeholder="Ex.: Após pagar, envie o comprovante pelo WhatsApp (47) 99999-0000." />
        <p class="field-hint">Opcional. A baixa continua manual em Financeiro › Transações (efetivar).</p>
      </div>
    </div>

    <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>

    <FormActions :saving="saving" cancel-label="Descartar alterações" cancel-icon="fa-solid fa-rotate-left" @cancel="load" />
  </form>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import TextArea from '@/components/utils/TextArea.vue'
import FormActions from '@/components/ui/FormActions.vue'

const auth = useAuthStore()
const router = useRouter()
const form = ref({ pix_key: '', payment_instructions: '' })
const slug = ref('')
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const copied = ref(false)

// router.resolve adds the build's base (/financial-bot/ behind Nginx), so the link opens the app.
const link = computed(() =>
  slug.value ? window.location.origin + router.resolve({ name: 'PortalLogin', params: { slug: slug.value } }).href : '')

const copy = async () => {
  await navigator.clipboard.writeText(link.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 1600)
}

const load = async () => {
  loading.value = true
  error.value = ''
  try {
    const response = await apiFetch('/settings/portal')
    if (!response.ok) throw new Error('Não foi possível carregar as configurações.')
    const data = await response.json()
    form.value = { pix_key: data.values.pix_key ?? '', payment_instructions: data.values.payment_instructions ?? '' }
    slug.value = data.slug
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
    const response = await apiFetch('/settings/portal', { method: 'PUT', body: JSON.stringify(form.value) })
    const data = await response.json()
    if (!response.ok) throw new Error(Object.values(data.errors || {})[0]?.[0] || data.message || 'Não foi possível salvar.')
    await auth.refreshUser()
    swal.toastSuccess('Portal do cliente salvo.')
  } catch (err) {
    error.value = err.message
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<style scoped>
.portal-hint {
  margin: 0 0 12px;
}

.portal-link {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 24px;
}

.portal-link code {
  flex: 1 1 280px;
  min-width: 0;
  padding: 10px 12px;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-bg-muted);
  color: var(--color-text);
  font-size: 0.85rem;
  overflow-wrap: anywhere;
}

.portal-link + .form-section {
  padding-top: 20px;
  border-top: 1px solid var(--color-border);
}
</style>
