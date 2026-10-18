<template>
  <div v-if="loading" class="loading-state">
    <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
    <span>Carregando o bot...</span>
  </div>

  <div v-else-if="error && !data" class="form-error">{{ error }}</div>

  <div v-else>
    <h3 class="form-section">Vínculo com o bot</h3>
    <p class="intro">
      Quem abrir o link (ou enviar o código ao bot) passa a lançar receitas e despesas desta empresa pelo Telegram.
      Envie só para pessoas de confiança.
    </p>

    <div v-if="data.code" class="link-box">
      <div class="form-grid">
        <div class="col-4">
          <Input :model-value="data.code" label="Código" readonly class="mono">
            <template #suffix>
              <button type="button" class="copy" :aria-label="copied === 'code' ? 'Código copiado' : 'Copiar código'" @click="copy('code', data.code)">
                <i :class="copied === 'code' ? 'fa-solid fa-check' : 'fa-regular fa-copy'" aria-hidden="true" />
              </button>
            </template>
          </Input>
        </div>

        <div v-if="data.link" class="col-8">
          <Input :model-value="data.link" label="Link de vínculo" readonly class="mono">
            <template #suffix>
              <button type="button" class="copy" :aria-label="copied === 'link' ? 'Link copiado' : 'Copiar link'" @click="copy('link', data.link)">
                <i :class="copied === 'link' ? 'fa-solid fa-check' : 'fa-regular fa-copy'" aria-hidden="true" />
              </button>
            </template>
          </Input>
        </div>
      </div>
      <p v-if="!data.bot_username" class="field-hint">
        Para mostrar o link direto, informe o usuário do bot em <code>TELEGRAM_BOT_USERNAME</code> no <code>.env</code>.
      </p>

      <div class="actions">
        <Button v-if="data.link" type="link" :href="data.link" target="_blank" rel="noopener" size="sm" variant="secondary" icon="fa-brands fa-telegram" label="Abrir no Telegram" />
        <Button size="sm" variant="secondary" icon="fa-solid fa-rotate" label="Gerar novo código" :disabled="busy" @click="regenerate" />
        <Button size="sm" variant="danger" icon="fa-solid fa-ban" label="Revogar código" :disabled="busy" @click="revoke" />
      </div>
      <p class="field-hint">Um código novo invalida o anterior só para novos vínculos: quem já está vinculado continua usando.</p>
    </div>

    <div v-else class="empty">
      <i class="fa-brands fa-telegram empty__icon" aria-hidden="true" />
      <div>
        <strong>Nenhum código ativo</strong>
        <p>Ninguém novo consegue se vincular. Gere um código para liberar o bot para a sua equipe.</p>
      </div>
      <Button icon="fa-solid fa-plus" label="Gerar código" :disabled="busy" @click="regenerate" />
    </div>

    <h3 class="form-section section-gap">Contas vinculadas</h3>
    <ul v-if="data.accounts.length" class="accounts">
      <li v-for="account in data.accounts" :key="account.id" class="account" :class="{ 'is-revoked': account.revoked_at }">
        <span class="account__avatar" aria-hidden="true"><i class="fa-brands fa-telegram" /></span>
        <span class="account__text">
          <strong>{{ accountName(account) }}</strong>
          <span>Vinculada em {{ formatDate(account.activated_at) }}</span>
        </span>
        <StatusBadge v-bind="account.revoked_at ? REVOKED : ACTIVE" small />
        <span class="account__actions">
          <Button
            size="sm"
            variant="ghost"
            :icon="account.revoked_at ? 'fa-solid fa-unlock' : 'fa-solid fa-lock'"
            :label="account.revoked_at ? 'Reativar' : 'Revogar'"
            :disabled="busy"
            @click="setRevoked(account, !account.revoked_at)"
          />
          <Button size="sm" variant="ghost" icon="fa-solid fa-trash" label="Remover" :disabled="busy" @click="remove(account)" />
        </span>
      </li>
    </ul>
    <p v-else class="field-hint">Nenhuma conta vinculada ainda.</p>

    <div v-if="error" class="form-error" style="margin-top: 16px">{{ error }}</div>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import Input from '@/components/utils/Input.vue'
import Button from '@/components/utils/Button.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'

const ACTIVE = { label: 'Ativa', color: 'success', icon: 'fa-solid fa-circle-check' }
const REVOKED = { label: 'Revogada', color: 'neutral', icon: 'fa-solid fa-lock' }

const data = ref(null)
const loading = ref(true)
const busy = ref(false)
const error = ref('')
const copied = ref('')

const accountName = (account) => (account.username ? `@${account.username}` : account.first_name || `Telegram ${account.telegram_user_id}`)
const formatDate = (value) => new Date(value).toLocaleDateString('pt-BR')

// Every action answers with JSON; overview actions return the whole tab state.
const request = async (url, options = {}) => {
  busy.value = true
  error.value = ''
  try {
    const response = await apiFetch(url, options)
    const body = await response.json()
    if (!response.ok) throw new Error(body.message || 'Não foi possível concluir a ação.')
    return body
  } catch (err) {
    error.value = err.message
    return null
  } finally {
    busy.value = false
  }
}

const load = async () => {
  data.value = (await request('/settings/telegram')) ?? data.value
  loading.value = false
}

const regenerate = async () => {
  if (data.value.code && !(await swal.confirm({
    title: 'Gerar novo código',
    text: 'O código e o link atuais deixam de vincular novas contas. Quem já está vinculado continua usando o bot.',
    confirmButtonText: 'Gerar código',
  }))) return
  const result = await request('/settings/telegram/code', { method: 'POST' })
  if (result) {
    data.value = result
    swal.toastSuccess('Código gerado.')
  }
}

const revoke = async () => {
  if (!(await swal.confirm({
    title: 'Revogar código',
    text: 'Ninguém novo consegue se vincular até você gerar outro código. As contas já vinculadas continuam.',
    confirmButtonText: 'Sim, revogar',
  }))) return
  const result = await request('/settings/telegram/code', { method: 'DELETE' })
  if (result) {
    data.value = result
    swal.toastSuccess('Código revogado.')
  }
}

const setRevoked = async (account, revoked) => {
  if (revoked && !(await swal.confirm({
    title: 'Revogar acesso',
    text: `${accountName(account)} deixa de usar o bot agora. Você pode reativar depois.`,
    confirmButtonText: 'Sim, revogar',
  }))) return
  const result = await request(`/settings/telegram/accounts/${account.id}`, { method: 'PUT', body: JSON.stringify({ revoked }) })
  if (result) {
    Object.assign(account, result)
    swal.toastSuccess(revoked ? 'Acesso revogado.' : 'Acesso reativado.')
  }
}

const remove = async (account) => {
  if (!(await swal.confirmDelete({
    title: 'Remover vínculo',
    confirmButtonText: 'Sim, remover',
    text: `${accountName(account)} perde o acesso e fica livre para se vincular de novo (aqui ou em outra empresa). Os lançamentos feitos continuam.`,
  }))) return
  if (await request(`/settings/telegram/accounts/${account.id}`, { method: 'DELETE' })) {
    data.value.accounts = data.value.accounts.filter((item) => item.id !== account.id)
    swal.toastSuccess('Vínculo removido.')
  }
}

const copy = async (key, value) => {
  await navigator.clipboard.writeText(value)
  copied.value = key
  setTimeout(() => (copied.value = ''), 1600)
}

onMounted(load)
</script>

<style lang="scss" scoped>
@use '@/styles/colors' as *;

.intro {
  margin: -6px 0 16px;
  max-width: 72ch;
  font-size: $font-md;
  line-height: 1.5;
  color: $color-text-secondary;
}

.mono :deep(.field-input) {
  font-family: ui-monospace, 'SFMono-Regular', Menlo, monospace;
  font-size: 0.82rem;
  letter-spacing: 0.02em;
}

.copy {
  display: grid;
  place-items: center;
  width: 30px;
  height: 30px;
  border: 0;
  border-radius: $radius-md;
  background: transparent;
  color: $color-text-muted;
  cursor: pointer;
  transition: background $transition-fast, color $transition-fast;

  &:hover {
    background: $color-bg-muted;
    color: $color-primary;
  }

  &:focus-visible {
    outline: 2px solid $color-primary;
    outline-offset: 2px;
  }
}

.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 14px;
}

.empty {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 16px;
  padding: 18px;
  border: 1px dashed $color-border-strong;
  border-radius: $radius-lg;

  > div {
    flex: 1 1 260px;
  }

  strong {
    font-size: $font-md;
    color: $color-text;
  }

  p {
    margin: 4px 0 0;
    font-size: $font-sm;
    color: $color-text-muted;
  }
}

.empty__icon {
  font-size: 1.75rem;
  color: #229ed9; // Telegram's own blue
}

.section-gap {
  margin-top: 28px;
  padding-top: 20px;
  border-top: 1px solid $color-border;
}

.accounts {
  display: flex;
  flex-direction: column;
  margin: 0;
  padding: 0;
  list-style: none;
  border: 1px solid $color-border;
  border-radius: $radius-lg;
}

.account {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;

  & + & {
    border-top: 1px solid $color-border;
  }

  &.is-revoked .account__text strong {
    color: $color-text-muted;
  }
}

.account__avatar {
  flex: 0 0 34px;
  width: 34px;
  height: 34px;
  display: grid;
  place-items: center;
  border-radius: $radius-full;
  background: #e8f4fb;
  color: #229ed9;
}

.account__text {
  flex: 1 1 180px;
  display: flex;
  flex-direction: column;
  min-width: 0;
  line-height: 1.25;

  strong {
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
    font-size: $font-md;
    color: $color-text;
  }

  span {
    font-size: $font-xs;
    color: $color-text-muted;
  }
}

.account__actions {
  display: flex;
  gap: 4px;
  margin-left: auto;
}
</style>
