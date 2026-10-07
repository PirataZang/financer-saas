<template>
  <div class="login-page">
    <div class="login-card">
      <div class="login-header">
        <span class="logo" aria-hidden="true"><i class="fa-solid fa-chart-line" /></span>
        <div>
          <h1>Financial Bot</h1>
          <p>Entre com suas credenciais para acessar o painel.</p>
        </div>
      </div>

      <form class="login-form" @submit.prevent="handleSubmit">
        <div class="form-grid">
          <Input
            v-model="form.email"
            class="col-12"
            label="E-mail"
            type="email"
            autocomplete="email"
            placeholder="seu@email.com"
            required
          />

          <Input
            v-model="form.password"
            class="col-12"
            label="Senha"
            type="password"
            autocomplete="current-password"
            placeholder="Digite sua senha"
            required
          />
        </div>

        <p v-if="error" class="form-error">{{ error }}</p>

        <Button
          native-type="submit"
          variant="primary"
          block
          icon="fa-solid fa-right-to-bracket"
          :label="loading ? 'Entrando...' : 'Entrar'"
          :disabled="loading"
        />
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'

const router = useRouter()
const auth = useAuthStore()

const form = ref({
  email: '',
  password: '',
})

const loading = ref(false)
const error = ref('')

const handleSubmit = async () => {
  loading.value = true
  error.value = ''

  try {
    await auth.login(form.value.email, form.value.password)
    await router.push('/')
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  background: var(--color-bg-app);
}

.login-card {
  width: 100%;
  max-width: 420px;
  padding: 32px;
  border-radius: 20px;
  background: #ffffff;
  border: 1px solid var(--color-border);
  box-shadow: var(--shadow-md);
}

.login-header {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 28px;
}

.logo {
  width: 52px;
  height: 52px;
  display: grid;
  place-items: center;
  border-radius: 14px;
  background: var(--color-primary-soft);
  color: var(--color-primary);
  font-size: 1.5rem;
}

.login-header h1 {
  font-size: 1.35rem;
  font-weight: 700;
  margin-bottom: 4px;
  color: var(--color-text);
}

.login-header p {
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.login-form {
  display: flex;
  flex-direction: column;
  gap: 18px;
}
</style>
