<template>
  <div class="portal-link">
    <code>{{ link }}</code>
    <div class="portal-link__actions">
      <Button
        variant="secondary"
        :icon="copied ? 'fa-solid fa-check' : 'fa-regular fa-copy'"
        :label="copied ? 'Copiado!' : 'Copiar link'"
        @click="copy"
      />
      <Button type="link" :href="whatsapp" target="_blank" rel="noopener" variant="ghost" icon="fa-brands fa-whatsapp" label="WhatsApp" />
      <Button type="link" :href="email" variant="ghost" icon="fa-regular fa-envelope" label="E-mail" />
      <Button type="link" :href="link" target="_blank" rel="noopener" variant="ghost" icon="fa-solid fa-arrow-up-right-from-square" label="Abrir" />
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import Button from '@/components/utils/Button.vue'

// Address of the company's portal (/portal/<slug>/entrar) with ways to hand it to a customer.
const props = defineProps({
  slug: { type: String, required: true },
  companyName: { type: String, default: '' },
})

const router = useRouter()
const copied = ref(false)

// router.resolve adds the build's base (/financial-bot/ behind Nginx), so the link opens the app.
const link = computed(() => window.location.origin + router.resolve({ name: 'PortalLogin', params: { slug: props.slug } }).href)
const message = computed(
  () =>
    `Olá! Acompanhe seus projetos, contas e avisos${props.companyName ? ` da ${props.companyName}` : ''} pelo portal do cliente: ${link.value}\n` +
    'Entre com o seu e-mail de cadastro; você recebe um código de acesso por e-mail.',
)
const whatsapp = computed(() => `https://wa.me/?text=${encodeURIComponent(message.value)}`)
const email = computed(
  () => `mailto:?subject=${encodeURIComponent('Acesso ao portal do cliente')}&body=${encodeURIComponent(message.value)}`,
)

const copy = async () => {
  await navigator.clipboard.writeText(link.value)
  copied.value = true
  setTimeout(() => (copied.value = false), 1600)
}
</script>

<style scoped>
.portal-link {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
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

.portal-link__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}
</style>
