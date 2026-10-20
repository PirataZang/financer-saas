<template>
  <div class="fade-in">
    <h1 class="p-title">Olá, {{ firstName }}</h1>
    <p class="p-sub">Acompanhe seus projetos, contas e avisos.</p>

    <div v-if="loading" class="p-state" role="status">
      <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
      <span>Carregando...</span>
    </div>

    <p v-else-if="error" class="p-state" role="alert">
      <i class="fa-solid fa-triangle-exclamation" aria-hidden="true" />
      {{ error }}
    </p>

    <template v-else>
      <router-link :to="{ name: 'PortalBills', params: { slug } }" class="p-card bills-summary" :class="{ 'is-clear': !bills.pending.length }">
        <span class="bills-summary__icon" aria-hidden="true">
          <i :class="bills.pending.length ? 'fa-solid fa-receipt' : 'fa-solid fa-circle-check'" />
        </span>
        <span class="bills-summary__body">
          <template v-if="bills.pending.length">
            <small>Em aberto</small>
            <strong>{{ formatMoney(bills.total_pending) }}</strong>
            <span>
              {{ bills.pending.length }} {{ bills.pending.length === 1 ? 'conta' : 'contas' }}
              <template v-if="overdue"> · <em>{{ overdue }} {{ overdue === 1 ? 'vencida' : 'vencidas' }}</em></template>
              <template v-else> · próxima em {{ formatDate(bills.pending[0].date) }}</template>
            </span>
          </template>
          <template v-else>
            <small>Contas</small>
            <strong>Tudo em dia</strong>
            <span>Nenhuma conta em aberto.</span>
          </template>
        </span>
        <i class="fa-solid fa-chevron-right bills-summary__go" aria-hidden="true" />
      </router-link>

      <section class="p-section" aria-labelledby="projects-title">
        <div class="p-section__head">
          <h2 id="projects-title">Seus projetos</h2>
        </div>
        <div v-if="projects.length" class="p-list">
          <router-link
            v-for="project in projects"
            :key="project.id"
            :to="{ name: 'PortalProject', params: { slug, id: project.id } }"
            class="p-card project"
          >
            <span class="project__head">
              <strong>{{ project.name }}</strong>
              <StatusBadge v-bind="project.badge" small />
            </span>
            <span class="project__progress">
              <span class="p-progress" role="progressbar" :aria-valuenow="project.progress" aria-valuemin="0" aria-valuemax="100" :aria-label="`Progresso de ${project.name}`">
                <span :style="{ width: `${project.progress}%` }" />
              </span>
              <b>{{ project.progress }}%</b>
            </span>
            <span class="project__foot">
              <span v-if="currentStep(project)"><i class="fa-solid fa-location-dot" aria-hidden="true" /> {{ currentStep(project) }}</span>
              <span v-if="project.due_date"><i class="fa-regular fa-calendar" aria-hidden="true" /> Previsão {{ formatDate(project.due_date) }}</span>
            </span>
          </router-link>
        </div>
        <p v-else class="p-card p-state">
          <i class="fa-solid fa-folder-open" aria-hidden="true" />
          Nenhum projeto por aqui ainda.
        </p>
      </section>

      <section class="p-section" aria-labelledby="news-title">
        <div class="p-section__head">
          <h2 id="news-title">Avisos recentes</h2>
          <router-link v-if="announcements.length > LATEST" :to="{ name: 'PortalAnnouncements', params: { slug } }">Ver todos</router-link>
        </div>
        <div v-if="announcements.length" class="p-list">
          <AnnouncementCard v-for="item in announcements.slice(0, LATEST)" :key="item.id" :announcement="item" />
        </div>
        <p v-else class="p-card p-state">
          <i class="fa-solid fa-bell-slash" aria-hidden="true" />
          Nenhum aviso no momento.
        </p>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { portalFetch } from '@/services/api'
import { usePortalStore } from '@/stores/portal'
import { formatMoney } from '@/utils/money'
import { formatDate } from '@/utils/date'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AnnouncementCard from '@/components/portal/AnnouncementCard.vue'

const LATEST = 3

const route = useRoute()
const portal = usePortalStore()
const slug = route.params.slug

const loading = ref(true)
const error = ref('')
const projects = ref([])
const bills = ref({ pending: [], paid: [], total_pending: '0.00' })
const announcements = ref([])

const firstName = computed(() => portal.me?.customer?.name?.split(' ')[0] || '')
const overdue = computed(() => bills.value.pending.filter((bill) => bill.overdue).length)

// Where the project is now: the first step that isn't done.
const currentStep = (project) => project.steps.find((step) => step.status !== 'done')?.name

const get = async (url) => {
  const response = await portalFetch(url)
  if (!response.ok) throw new Error('Não foi possível carregar suas informações. Tente novamente.')
  return response.json()
}

onMounted(async () => {
  try {
    ;[projects.value, bills.value, announcements.value] = await Promise.all([get('/portal/projects'), get('/portal/bills'), get('/portal/announcements')])
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
})
</script>

<style scoped lang="scss">
.bills-summary {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-top: 20px;
  border-color: color-mix(in srgb, var(--color-warning) 30%, var(--color-border));
  background: linear-gradient(135deg, var(--color-warning-soft), var(--color-bg-card) 70%);

  &.is-clear {
    border-color: color-mix(in srgb, var(--color-success) 25%, var(--color-border));
    background: linear-gradient(135deg, var(--color-success-soft), var(--color-bg-card) 70%);

    .bills-summary__icon {
      color: var(--color-success);
    }
  }
}

.bills-summary__icon {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 12px;
  background: var(--color-bg-card);
  color: var(--color-warning-text);
  font-size: 1.15rem;
  box-shadow: var(--shadow-sm);
}

.bills-summary__body {
  display: flex;
  flex: 1;
  flex-direction: column;
  min-width: 0;

  small {
    color: var(--color-text-muted);
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }

  strong {
    color: var(--color-text);
    font-size: 1.4rem;
    font-weight: 700;
    line-height: 1.25;
    font-variant-numeric: tabular-nums;
  }

  span {
    color: var(--color-text-secondary);
    font-size: 0.85rem;
  }

  em {
    color: var(--color-danger);
    font-style: normal;
    font-weight: 600;
  }
}

.bills-summary__go {
  color: var(--color-text-faint);
}

.project {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.project__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;

  strong {
    color: var(--color-text);
    font-size: 1rem;
    line-height: 1.3;
  }
}

.project__progress {
  display: flex;
  align-items: center;
  gap: 10px;

  .p-progress {
    flex: 1;
  }

  b {
    min-width: 40px;
    color: var(--color-text);
    font-size: 0.85rem;
    text-align: right;
    font-variant-numeric: tabular-nums;
  }
}

.project__foot {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 16px;
  color: var(--color-text-muted);
  font-size: 0.8rem;

  i {
    margin-right: 4px;
  }
}
</style>
