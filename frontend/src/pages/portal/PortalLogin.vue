<template>
  <div class="portal-login">
    <div class="portal-login__card">
      <div v-if="notFound" class="portal-login__missing" role="alert">
        <i class="fa-solid fa-link-slash" aria-hidden="true" />
        <h1>Portal indisponível</h1>
        <p>Este endereço não existe ou está temporariamente fora do ar. Confira o link com a empresa.</p>
      </div>

      <template v-else>
        <header class="portal-login__header">
          <span class="portal-login__mark" aria-hidden="true">{{ initials }}</span>
          <div>
            <h1>{{ brand?.name || 'Portal do cliente' }}</h1>
            <p>Portal do cliente</p>
          </div>
        </header>

        <!-- Step 1: e-mail (must be a customer of this company) -->
        <form v-if="step === 'email'" class="portal-login__form" @submit.prevent="sendCode">
          <p class="portal-login__intro">Acompanhe seus projetos, contas e avisos. Informe o e-mail do seu cadastro para receber um código de acesso.</p>
          <Input
            v-model="email"
            label="E-mail"
            type="email"
            autocomplete="email"
            inputmode="email"
            placeholder="seu@email.com"
            required
          />
          <p v-if="error" class="form-error" role="alert">{{ error }}</p>
          <Button
            native-type="submit"
            variant="primary"
            size="lg"
            block
            icon="fa-solid fa-paper-plane"
            :label="loading ? 'Enviando...' : 'Receber código'"
            :disabled="loading"
          />
        </form>

        <!-- Step 2: the 6-digit code sent to that e-mail -->
        <form v-else class="portal-login__form" @submit.prevent="login">
          <p class="portal-login__intro">
            Enviamos um código de 6 dígitos para <strong>{{ sentTo }}</strong>. Ele vale por 10 minutos.
          </p>
          <Input
            id="portal-code"
            v-model="code"
            class="portal-login__code"
            label="Código de acesso"
            autocomplete="one-time-code"
            inputmode="numeric"
            maxlength="6"
            placeholder="000000"
            required
          />
          <p v-if="error" class="form-error" role="alert">{{ error }}</p>
          <Button
            native-type="submit"
            variant="primary"
            size="lg"
            block
            icon="fa-solid fa-right-to-bracket"
            :label="loading ? 'Entrando...' : 'Entrar'"
            :disabled="loading || code.length !== 6"
          />
          <div class="portal-login__links">
            <button type="button" :disabled="cooldown > 0 || loading" @click="sendCode">
              {{ cooldown > 0 ? `Reenviar código (${cooldown}s)` : 'Reenviar código' }}
            </button>
            <button type="button" @click="changeEmail">Trocar e-mail</button>
          </div>
        </form>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { API_URL } from '@/services/api'
import { usePortalStore } from '@/stores/portal'
import { applyAppearance, resetAppearance } from '@/utils/theme'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'

const RESEND_SECONDS = 30

const route = useRoute()
const router = useRouter()
const portal = usePortalStore()
const slug = route.params.slug

const brand = ref(null)
const notFound = ref(false)
const step = ref('email')
const email = ref('')
const code = ref('')
const sentTo = ref('')
const error = ref('')
const loading = ref(false)
const cooldown = ref(0)
let timer = null

const initials = computed(() =>
  String(brand.value?.name || 'P')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join(''),
)

const startCooldown = () => {
  cooldown.value = RESEND_SECONDS
  clearInterval(timer)
  timer = setInterval(() => {
    if (--cooldown.value <= 0) clearInterval(timer)
  }, 1000)
}

const sendCode = async () => {
  loading.value = true
  error.value = ''
  try {
    sentTo.value = await portal.requestCode(slug, email.value)
    step.value = 'code'
    code.value = ''
    startCooldown()
    await nextTick()
    document.getElementById('portal-code')?.focus()
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

const login = async () => {
  if (loading.value) return
  loading.value = true
  error.value = ''
  try {
    await portal.login(slug, email.value, code.value)
    await router.replace({ name: 'PortalHome', params: { slug } })
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}

const changeEmail = () => {
  step.value = 'email'
  error.value = ''
}

// Digits only; the 6th digit submits (also when the phone autofills it from the e-mail/SMS).
watch(code, (value) => {
  const digits = value.replace(/\D/g, '').slice(0, 6)
  if (digits !== value) code.value = digits
  else if (digits.length === 6) login()
})

onMounted(async () => {
  const response = await fetch(`${API_URL}/portal/${slug}`, { headers: { Accept: 'application/json' } }).catch(() => null)
  if (!response?.ok) {
    notFound.value = true
    return
  }
  brand.value = await response.json()
  applyAppearance({ primary_color: brand.value.primary_color, brand_name: brand.value.name })
})

onUnmounted(() => {
  clearInterval(timer)
  resetAppearance()
})
</script>

<style scoped lang="scss">
.portal-login {
  display: flex;
  align-items: flex-start;
  justify-content: center;
  min-height: 100dvh;
  padding: 24px 16px;
  background:
    radial-gradient(120% 60% at 50% 0%, var(--color-primary-soft), transparent 70%),
    var(--color-bg-app);
}

.portal-login__card {
  width: 100%;
  max-width: 420px;
  margin-top: 6vh;
  padding: 28px 20px;
  border: 1px solid var(--color-border);
  border-radius: 20px;
  background: var(--color-bg-card);
  box-shadow: var(--shadow-md);
}

.portal-login__header {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 20px;

  h1 {
    color: var(--color-text);
    font-size: 1.25rem;
    font-weight: 700;
    line-height: 1.2;
  }

  p {
    color: var(--color-text-muted);
    font-size: 0.85rem;
  }
}

.portal-login__mark {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 14px;
  background: var(--color-primary);
  color: #ffffff;
  font-size: 1.05rem;
  font-weight: 700;
}

.portal-login__form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.portal-login__intro {
  color: var(--color-text-secondary);
  font-size: 0.9rem;

  strong {
    color: var(--color-text);
  }
}

// Big, spaced digits: easy to read back and to type on a phone.
.portal-login__code :deep(.field-input) {
  font-size: 1.5rem;
  font-weight: 600;
  letter-spacing: 0.4em;
  text-align: center;
  font-variant-numeric: tabular-nums;
}

.portal-login__links {
  display: flex;
  justify-content: space-between;
  gap: 12px;

  button {
    min-height: 40px;
    padding: 0 4px;
    border: 0;
    background: transparent;
    color: var(--color-primary);
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;

    &:disabled {
      color: var(--color-text-faint);
      cursor: default;
    }

    &:focus-visible {
      outline: 2px solid var(--color-primary);
      outline-offset: 2px;
      border-radius: 6px;
    }
  }
}

.portal-login__missing {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 12px 0;
  text-align: center;

  i {
    color: var(--color-text-faint);
    font-size: 2rem;
  }

  h1 {
    color: var(--color-text);
    font-size: 1.2rem;
    font-weight: 700;
  }

  p {
    color: var(--color-text-muted);
    font-size: 0.9rem;
  }
}

@media (min-width: 768px) {
  .portal-login {
    align-items: center;
  }

  .portal-login__card {
    margin-top: 0;
    padding: 32px;
  }
}
</style>
