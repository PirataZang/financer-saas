<template>
  <article class="integration" :class="{ 'is-off': integration.connected && !integration.active }" :aria-labelledby="`integration-${integration.provider}`">
    <header class="integration__head">
      <span class="integration__logo">
        <img :src="meta.logo" alt="" width="40" height="40" />
      </span>
      <div class="integration__title">
        <h4 :id="`integration-${integration.provider}`">{{ meta.name }}</h4>
        <StatusBadge v-bind="status" small />
      </div>
      <Switch
        v-if="integration.connected"
        class="integration__switch"
        :model-value="integration.active"
        :aria-label="integration.active ? `Desativar ${meta.name}` : `Ativar ${meta.name}`"
        :disabled="busy"
        @change="$emit('toggle', $event)"
      />
    </header>

    <div class="integration__summary">
      {{ meta.summary }}
      <InfoTip :title="`O que o ${meta.name} faz e não faz`">
        <p class="info-tip__heading">O que faz</p>
        <ul>
          <li v-for="item in meta.capabilities" :key="item.text">{{ item.text }}</li>
        </ul>
        <p class="info-tip__heading">O que não faz</p>
        <p>{{ meta.limits }}</p>
      </InfoTip>
    </div>

    <dl v-if="integration.connected" class="integration__facts">
      <div>
        <dt>Conta</dt>
        <dd>{{ integration.account?.nickname || '—' }}<small v-if="integration.account?.email">{{ integration.account.email }}</small></dd>
      </div>
      <div>
        <dt>Última sincronização</dt>
        <dd>
          <template v-if="integration.last_synced_at">{{ sinceTime(integration.last_synced_at) }}</template>
          <template v-else>Ainda não sincronizou</template>
          <small v-if="integration.stats">{{ plural(integration.stats.transactions, 'lançamento', 'lançamentos') }} · {{ plural(integration.stats.payments, 'pagamento', 'pagamentos') }}</small>
        </dd>
      </div>
    </dl>

    <p v-if="integration.last_error" class="integration__notice is-error" role="alert">
      <i class="fa-solid fa-triangle-exclamation" aria-hidden="true" />
      <span><strong>A última sincronização falhou.</strong> {{ integration.last_error }}</span>
    </p>

    <footer class="integration__actions">
      <template v-if="integration.connected">
        <Button
          size="sm"
          variant="secondary"
          :icon="syncing ? 'fa-solid fa-arrows-rotate fa-spin' : 'fa-solid fa-arrows-rotate'"
          :label="syncing ? 'Sincronizando...' : 'Sincronizar agora'"
          :disabled="busy || !integration.active"
          :title="integration.active ? '' : 'Ative a integração para sincronizar'"
          @click="$emit('sync')"
        />
        <Button size="sm" variant="edit" icon="fa-solid fa-pen" label="Editar" :disabled="busy" @click="$emit('edit')" />
        <Button size="sm" variant="danger" icon="fa-solid fa-trash" label="Remover" :disabled="busy" class="integration__remove" @click="$emit('remove')" />
      </template>
      <Button
        v-else
        :icon="busy ? 'fa-solid fa-spinner fa-spin' : 'fa-solid fa-link'"
        :label="`Conectar com ${meta.name}`"
        :disabled="busy"
        @click="$emit('connect')"
      />
    </footer>
  </article>
</template>

<script setup>
import { computed } from 'vue'
import Button from '@/components/utils/Button.vue'
import Switch from '@/components/utils/Switch.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import InfoTip from '@/components/ui/InfoTip.vue'
import { sinceTime } from '@/utils/date'

// One provider of Configurações › Integrações. `integration` = GET /integrations row,
// `meta` = its entry in integrations/catalog.js. Actions are emitted; the tab talks to the API.
const props = defineProps({
  integration: { type: Object, required: true },
  meta: { type: Object, required: true },
  busy: { type: Boolean, default: false },
  syncing: { type: Boolean, default: false },
})

defineEmits(['connect', 'toggle', 'sync', 'edit', 'remove'])

const status = computed(() => {
  const i = props.integration
  if (!i.connected) return { label: 'Não conectado', color: 'neutral', icon: 'fa-solid fa-plug' }
  if (!i.active) return { label: 'Inativo', color: 'neutral', icon: 'fa-solid fa-pause' }
  if (i.last_error) return { label: 'Com erro', color: 'warning', icon: 'fa-solid fa-triangle-exclamation' }
  return { label: 'Conectado', color: 'success', icon: 'fa-solid fa-circle-check' }
})

const plural = (n, one, many) => `${n.toLocaleString('pt-BR')} ${n === 1 ? one : many}`
</script>

<style scoped lang="scss">
@use '@/styles/colors' as *;

.integration {
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 16px;
  border: 1px solid $color-border;
  border-radius: $radius-lg;
  background: $color-bg-card;
  transition: border-color $transition-fast;

  &:hover {
    border-color: $color-border-strong;
  }
}

.integration__head {
  display: flex;
  align-items: center;
  gap: 14px;
}

// The logo is the card's anchor: big, on its own tile.
.integration__logo {
  display: grid;
  flex: 0 0 64px;
  place-items: center;
  width: 64px;
  height: 64px;
  border: 1px solid $color-border;
  border-radius: $radius-lg;
  background: $color-white;
  transition: filter 0.2s ease, opacity 0.2s ease;
}

.integration__title {
  display: flex;
  flex: 1;
  flex-direction: column;
  align-items: flex-start;
  gap: 6px;
  min-width: 0;

  h4 {
    margin: 0;
    color: $color-text;
    font-size: 1.15rem;
    font-weight: 700;
  }
}

.integration__switch {
  flex-shrink: 0;
}

// Switched off: the logo goes quiet so the state reads at a glance.
.is-off .integration__logo {
  filter: grayscale(1);
  opacity: 0.6;
}

.integration__summary {
  margin: 0;
  color: $color-text-secondary;
  font-size: $font-sm;
  line-height: 1.5;
}

.integration__facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 10px 16px;
  margin: 0;
  padding: 12px 0 0;
  border-top: 1px solid $color-border;

  dt {
    color: $color-text-muted;
    font-size: 0.72rem;
    font-weight: 600;
  }

  dd {
    display: flex;
    flex-direction: column;
    gap: 2px;
    margin: 2px 0 0;
    color: $color-text;
    font-size: $font-sm;
    font-weight: 600;
    overflow-wrap: anywhere;
  }

  small {
    color: $color-text-muted;
    font-size: 0.75rem;
    font-weight: 400;
  }
}

.integration__notice {
  display: flex;
  align-items: baseline;
  gap: 10px;
  margin: 0;
  padding: 8px 10px;
  border-radius: $radius-md;
  background: $color-info-soft;
  color: $color-text-secondary;
  font-size: $font-sm;
  line-height: 1.45;

  i {
    color: $color-info;
  }

  &.is-error {
    background: $color-warning-soft;

    i {
      color: $color-warning-text;
    }
  }
}

.integration__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: auto;
}

// Destructive action set apart from the everyday ones.
.integration__remove {
  margin-left: auto;
}
</style>
