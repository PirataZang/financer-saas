<template>
  <div v-if="loading" class="loading-state">
    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
    <span>Carregando integrações...</span>
  </div>

  <div v-else>
    <p class="intro">
      Conecte serviços externos ao financial-bot para acompanhar o dinheiro em um só lugar. As integrações são opcionais e
      só leem dados: nada é pago, cobrado ou transferido por aqui.
    </p>

    <div v-if="error" class="form-error" role="alert">{{ error }}</div>

    <div class="integrations">
      <IntegrationCard
        v-for="integration in cards"
        :key="integration.provider"
        :integration="integration"
        :meta="INTEGRATIONS[integration.provider]"
        :busy="busy === integration.provider"
        :syncing="syncing === integration.provider"
        @connect="openConnect(integration)"
        @toggle="toggle(integration, $event)"
        @sync="sync(integration)"
        @edit="openEdit(integration)"
        @remove="remove(integration)"
      />
    </div>

    <Modal v-model="connecting.open" :title="`Conectar ${connecting.meta?.name ?? ''}`" size="sm">
      <form id="connect-form" class="edit-form" @submit.prevent="connect">
        <Input
          v-model="connecting.token"
          type="password"
          autocomplete="off"
          :label="connecting.meta?.credential.label"
          :tip="connecting.meta?.credential.tip"
          :placeholder="connecting.meta?.credential.placeholder"
        />
        <p class="field-hint">
          Pegue em <a :href="connecting.meta?.credential.link" target="_blank" rel="noopener">{{ connecting.meta?.name }} Developers</a>.
          Cada empresa usa a credencial da própria conta; ela fica criptografada e nunca é exibida de novo.
        </p>
        <div v-if="connecting.error" class="form-error">{{ connecting.error }}</div>
      </form>
      <template #footer>
        <Button variant="secondary" label="Cancelar" @click="connecting.open = false" />
        <Button native-type="submit" form="connect-form" :icon="busy ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-link'" label="Conectar" :disabled="!!busy || !connecting.token" />
      </template>
    </Modal>

    <Modal v-model="editing.open" :title="`Editar ${editing.meta?.name ?? ''}`" size="sm">
      <form id="integration-form" class="edit-form" @submit.prevent="save">
        <template v-for="field in editing.meta?.fields ?? []" :key="field.key">
          <Input v-if="field.type === 'date'" v-model="editing.form[field.key]" type="date" :label="field.label" :tip="field.tip" :max="today" />
          <Switch
            v-else-if="field.type === 'switch'"
            v-model="editing.form[field.key]"
            :label="field.label"
            :tip="field.tip"
            :text-label="editing.form[field.key] ? field.onText : field.offText"
          />
        </template>
        <p class="field-hint">Mudanças valem a partir da próxima sincronização.</p>
        <div v-if="editing.error" class="form-error">{{ editing.error }}</div>
      </form>
      <template #footer>
        <Button variant="secondary" label="Cancelar" @click="editing.open = false" />
        <Button native-type="submit" form="integration-form" :icon="busy ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-check'" label="Salvar" :disabled="!!busy" />
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { INTEGRATIONS } from '@/integrations/catalog'
import IntegrationCard from '@/components/integrations/IntegrationCard.vue'
import Modal from '@/components/utils/Modal.vue'
import Input from '@/components/utils/Input.vue'
import Switch from '@/components/utils/Switch.vue'
import Button from '@/components/utils/Button.vue'

const list = ref([])
const loading = ref(true)
const error = ref('')
// Provider whose request is running (one card at a time).
const busy = ref('')
const syncing = ref('')
const connecting = reactive({ open: false, integration: null, meta: null, token: '', error: '' })
const editing = reactive({ open: false, integration: null, meta: null, form: {}, error: '' })

const today = new Date().toLocaleDateString('sv-SE')

// Only providers this frontend knows how to present.
const cards = computed(() => list.value.filter((i) => INTEGRATIONS[i.provider]))

const replace = (card) => {
  list.value = list.value.map((i) => (i.provider === card.provider ? card : i))
}

// JSON answer or a thrown Error with the API's first message.
const call = async (provider, url, options = {}) => {
  busy.value = provider
  try {
    const response = await apiFetch(url, options)
    const body = await response.json().catch(() => ({}))
    if (!response.ok) throw new Error(Object.values(body.errors || {})[0]?.[0] || body.message || 'Não foi possível concluir a ação.')
    return body
  } finally {
    busy.value = ''
  }
}

const load = async () => {
  try {
    list.value = await call('', '/integrations')
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

const openConnect = (integration) => {
  Object.assign(connecting, { open: true, integration, meta: INTEGRATIONS[integration.provider], token: '', error: '' })
}

// Sends the company's own credential; the API validates it with the provider, stores it encrypted and syncs.
const connect = async () => {
  connecting.error = ''
  try {
    const card = await call(connecting.integration.provider, `/integrations/${connecting.integration.provider}/connect`, {
      method: 'POST',
      body: JSON.stringify({ access_token: connecting.token.trim() }),
    })
    replace(card)
    connecting.open = false
    connecting.token = ''
    swal.toastSuccess(card.last_error ? `${connecting.meta.name} conectado, mas a primeira sincronização falhou.` : `${connecting.meta.name} conectado e sincronizado.`)
  } catch (err) {
    connecting.error = err.message
  }
}

const toggle = async (integration, active) => {
  try {
    replace(await call(integration.provider, `/integrations/${integration.provider}`, { method: 'PUT', body: JSON.stringify({ active }) }))
    swal.toastSuccess(active ? 'Integração ativada.' : 'Integração desativada: os dados ficam, mas nada novo é importado.')
  } catch (err) {
    swal.toastError(err.message)
  }
}

const sync = async (integration) => {
  syncing.value = integration.provider
  try {
    const card = await call(integration.provider, `/integrations/${integration.provider}/sync`, { method: 'POST' })
    replace(card)
    swal.toastSuccess(card.synced ? `${card.synced} pagamento(s) atualizado(s).` : 'Tudo em dia: nenhum pagamento novo.')
  } catch (err) {
    swal.toastError(err.message)
    load()
  } finally {
    syncing.value = ''
  }
}

const openEdit = (integration) => {
  const meta = INTEGRATIONS[integration.provider]
  Object.assign(editing, {
    open: true,
    integration,
    meta,
    error: '',
    form: Object.fromEntries(meta.fields.map((f) => [f.key, integration.settings[f.key] ?? (f.type === 'switch' ? false : '')])),
  })
}

const save = async () => {
  editing.error = ''
  const settings = { ...editing.form }
  editing.meta.fields.filter((f) => f.type === 'date').forEach((f) => (settings[f.key] = settings[f.key] || null))
  try {
    replace(await call(editing.integration.provider, `/integrations/${editing.integration.provider}`, { method: 'PUT', body: JSON.stringify({ settings }) }))
    editing.open = false
    swal.toastSuccess('Integração salva.')
  } catch (err) {
    editing.error = err.message
  }
}

const remove = async (integration) => {
  const { name } = INTEGRATIONS[integration.provider]
  const stats = integration.stats
  const ok = await swal.confirmDelete({
    title: `Remover a integração com o ${name}?`,
    text:
      `Apaga a conexão, ${stats?.transactions ?? 0} lançamento(s) importado(s), o histórico de pagamentos e as categorias da integração. ` +
      `Não dá para desfazer. Sua conta no ${name} não é afetada.`,
    confirmButtonText: 'Sim, remover tudo',
  })
  if (!ok) return
  try {
    const { message } = await call(integration.provider, `/integrations/${integration.provider}`, { method: 'DELETE' })
    swal.toastSuccess(message)
    await load()
  } catch (err) {
    swal.toastError(err.message)
  }
}

onMounted(load)
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.intro {
  margin: 0 0 18px;
  max-width: 72ch;
  color: $color-text-secondary;
  font-size: $font-md;
  line-height: 1.5;
}

.integrations {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 460px), 1fr));
  gap: 16px;
}

.edit-form {
  display: flex;
  flex-direction: column;
  gap: 18px;
}
</style>
