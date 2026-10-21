<template>
  <div class="fade-in">
    <h1 class="p-title">Contas</h1>
    <p class="p-sub">Suas contas em aberto e as últimas pagas.</p>

    <div v-if="loading" class="p-state" role="status">
      <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
      <span>Carregando contas...</span>
    </div>

    <p v-else-if="error" class="p-state" role="alert">
      <i class="fa-solid fa-triangle-exclamation" aria-hidden="true" />
      {{ error }}
    </p>

    <template v-else>
      <section v-if="bills.pending.length" class="p-card pay">
        <div class="pay__total">
          <small>Total em aberto</small>
          <strong>{{ formatMoney(bills.total_pending) }}</strong>
        </div>

        <div v-if="payment.pix_key" class="pay__pix">
          <span class="pay__pix-label"><i class="fa-brands fa-pix" aria-hidden="true" /> Chave PIX</span>
          <code>{{ payment.pix_key }}</code>
          <Button
            variant="primary"
            block
            class="pix-copy"
            :class="{ 'is-copied': copied }"
            :icon="copied ? 'fa-solid fa-check' : 'fa-regular fa-copy'"
            :label="copied ? '' : 'Copiar chave PIX'"
            :aria-label="copied ? 'Chave PIX copiada' : undefined"
            @click="copyPix"
          />
          <span class="sr-only" aria-live="polite">{{ copied ? 'Chave PIX copiada' : '' }}</span>
        </div>
        <p v-if="payment.payment_instructions" class="pay__instructions">{{ payment.payment_instructions }}</p>
        <p v-if="!payment.pix_key && !payment.payment_instructions" class="pay__instructions">
          Fale com {{ portal.me.company.name }} para saber como pagar.
        </p>
      </section>

      <section class="p-section" aria-labelledby="open-title">
        <div class="p-section__head">
          <h2 id="open-title">Em aberto</h2>
        </div>
        <ul v-if="bills.pending.length" class="p-card bill-list">
          <li v-for="bill in bills.pending" :key="bill.id" class="bill">
            <div class="bill__body">
              <strong>{{ bill.description }}</strong>
              <span>{{ bill.overdue ? 'Venceu em' : 'Vence em' }} {{ formatDate(bill.date) }}</span>
            </div>
            <div class="bill__side">
              <b>{{ formatMoney(bill.amount) }}</b>
              <StatusBadge v-bind="badge(bill)" small />
            </div>
          </li>
        </ul>
        <p v-else class="p-card p-state">
          <i class="fa-solid fa-circle-check" aria-hidden="true" />
          Nenhuma conta em aberto. Tudo em dia!
        </p>
      </section>

      <section v-if="bills.paid.length" class="p-section" aria-labelledby="paid-title">
        <div class="p-section__head">
          <h2 id="paid-title">Pagas</h2>
        </div>
        <ul class="p-card bill-list is-paid">
          <li v-for="bill in bills.paid" :key="bill.id" class="bill">
            <div class="bill__body">
              <strong>{{ bill.description }}</strong>
              <span>{{ formatDate(bill.date) }}</span>
            </div>
            <div class="bill__side">
              <b>{{ formatMoney(bill.amount) }}</b>
              <StatusBadge v-bind="badge(bill)" small />
            </div>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { portalFetch } from '@/services/api'
import { usePortalStore } from '@/stores/portal'
import { formatMoney } from '@/utils/money'
import { formatDate } from '@/utils/date'
import Button from '@/components/utils/Button.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'

const portal = usePortalStore()
const loading = ref(true)
const error = ref('')
const copied = ref(false)
const bills = ref({ pending: [], paid: [], total_pending: '0.00' })

const payment = computed(() => portal.me?.company?.payment ?? {})

const badge = (bill) => {
  if (bill.status === 'paid') return { label: 'Paga', color: 'success', icon: 'fa-solid fa-check' }
  if (bill.overdue) return { label: 'Vencida', color: 'danger', icon: 'fa-solid fa-circle-exclamation' }
  return { label: 'Em aberto', color: 'warning', icon: 'fa-regular fa-clock' }
}

let copiedTimer
const copyPix = async () => {
  await navigator.clipboard.writeText(payment.value.pix_key)
  copied.value = true
  clearTimeout(copiedTimer)
  copiedTimer = setTimeout(() => (copied.value = false), 5000)
}

onMounted(async () => {
  try {
    const response = await portalFetch('/portal/bills')
    if (!response.ok) throw new Error('Não foi possível carregar suas contas. Tente novamente.')
    bills.value = await response.json()
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
})
</script>

<style scoped lang="scss">
.pay {
  display: flex;
  flex-direction: column;
  gap: 16px;
  margin-top: 20px;
}

.pay__total {
  display: flex;
  flex-direction: column;

  small {
    color: var(--color-text-muted);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }

  strong {
    color: var(--color-text);
    font-size: 1.6rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
  }
}

.pay__pix {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 14px;
  border-radius: var(--radius-lg);
  background: var(--color-bg-muted);

  code {
    color: var(--color-text);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: 0.95rem;
    overflow-wrap: anywhere;
  }
}

// Copied: the sides close in first, then the corners round into a green circle and the check pops;
// after copiedTimer it runs backwards (corners, then width).
.pix-copy {
  margin-inline: auto;
  overflow: hidden;
  transition:
    border-radius 0.2s ease,
    background 0.2s ease,
    width 0.3s ease 0.2s;

  &.is-copied {
    --btn-bg: var(--color-success);
    --btn-bg-hover: var(--color-success);

    width: 40px;
    padding: 0;
    border-radius: 20px;
    pointer-events: none;
    transition:
      width 0.3s ease,
      border-radius 0.2s ease 0.3s,
      background 0.2s ease 0.3s;

    :deep(i) {
      animation: pix-check 0.3s ease 0.45s both;
    }
  }
}

@keyframes pix-check {
  from {
    transform: scale(0);
  }

  70% {
    transform: scale(1.25);
  }

  to {
    transform: scale(1);
  }
}

@media (prefers-reduced-motion: reduce) {
  .pix-copy,
  .pix-copy.is-copied :deep(i) {
    transition: none;
    animation: none;
  }
}

.pay__pix-label {
  color: var(--color-text-secondary);
  font-size: 0.8rem;
  font-weight: 600;
}

.pay__instructions {
  color: var(--color-text-secondary);
  font-size: 0.875rem;
  white-space: pre-line;
}

.bill-list {
  margin: 0;
  padding: 0 16px;
  list-style: none;
}

.bill {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px 0;

  & + & {
    border-top: 1px solid var(--color-border);
  }
}

.bill__body {
  display: flex;
  flex-direction: column;
  min-width: 0;

  strong {
    color: var(--color-text);
    font-size: 0.92rem;
    line-height: 1.3;
    overflow-wrap: anywhere;
  }

  span {
    color: var(--color-text-muted);
    font-size: 0.8rem;
  }
}

.bill__side {
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
  align-items: flex-end;
  gap: 4px;

  b {
    color: var(--color-text);
    font-size: 0.95rem;
    font-variant-numeric: tabular-nums;
  }
}

.is-paid .bill__side b {
  color: var(--color-text-secondary);
  font-weight: 600;
}
</style>
