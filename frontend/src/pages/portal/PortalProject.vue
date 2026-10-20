<template>
  <div class="fade-in">
    <router-link :to="{ name: 'PortalHome', params: { slug } }" class="p-back">
      <i class="fa-solid fa-arrow-left" aria-hidden="true" /> Início
    </router-link>

    <div v-if="loading" class="p-state" role="status">
      <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
      <span>Carregando projeto...</span>
    </div>

    <p v-else-if="error" class="p-state" role="alert">
      <i class="fa-solid fa-folder-minus" aria-hidden="true" />
      {{ error }}
    </p>

    <template v-else>
      <section class="p-card overview">
        <div class="overview__head">
          <h1 class="p-title">{{ project.name }}</h1>
          <StatusBadge v-bind="project.badge" />
        </div>
        <p v-if="project.description" class="overview__description">{{ project.description }}</p>

        <div class="overview__progress">
          <div class="overview__progress-label">
            <span>Progresso</span>
            <b>{{ project.progress }}%</b>
          </div>
          <div class="p-progress" role="progressbar" :aria-valuenow="project.progress" aria-valuemin="0" aria-valuemax="100" aria-label="Progresso do projeto">
            <span :style="{ width: `${project.progress}%` }" />
          </div>
          <small v-if="project.steps.length">{{ doneSteps }} de {{ project.steps.length }} etapas concluídas</small>
        </div>

        <dl v-if="project.start_date || project.due_date" class="overview__dates">
          <div v-if="project.start_date">
            <dt>Início</dt>
            <dd>{{ formatDate(project.start_date) }}</dd>
          </div>
          <div v-if="project.due_date">
            <dt>Previsão de entrega</dt>
            <dd>{{ formatDate(project.due_date) }}</dd>
          </div>
        </dl>
      </section>

      <section v-if="project.steps.length" class="p-section" aria-labelledby="steps-title">
        <div class="p-section__head">
          <h2 id="steps-title">Etapas</h2>
        </div>
        <ol class="p-card timeline">
          <li v-for="(step, index) in project.steps" :key="index" class="timeline__step" :class="`is-${step.status}`">
            <span class="timeline__dot" aria-hidden="true"><i :class="step.badge.icon" /></span>
            <div class="timeline__body">
              <strong>{{ step.name }}</strong>
              <span>
                {{ step.badge.label }}<template v-if="step.due_date"> · até {{ formatDate(step.due_date) }}</template>
              </span>
            </div>
          </li>
        </ol>
      </section>

      <section class="p-section" aria-labelledby="project-news-title">
        <div class="p-section__head">
          <h2 id="project-news-title">Avisos do projeto</h2>
        </div>
        <div v-if="project.announcements.length" class="p-list">
          <AnnouncementCard v-for="item in project.announcements" :key="item.id" :announcement="item" :show-project="false" />
        </div>
        <p v-else class="p-card p-state">
          <i class="fa-solid fa-bell-slash" aria-hidden="true" />
          Nenhum aviso sobre este projeto ainda.
        </p>
      </section>
    </template>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { portalFetch } from '@/services/api'
import { formatDate } from '@/utils/date'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AnnouncementCard from '@/components/portal/AnnouncementCard.vue'

const props = defineProps({
  slug: { type: String, required: true },
  id: { type: String, required: true },
})

const loading = ref(true)
const error = ref('')
const project = ref(null)

const doneSteps = computed(() => project.value.steps.filter((step) => step.status === 'done').length)

onMounted(async () => {
  try {
    const response = await portalFetch(`/portal/projects/${props.id}`)
    if (!response.ok) throw new Error(response.status === 404 ? 'Projeto não encontrado.' : 'Não foi possível carregar o projeto.')
    project.value = await response.json()
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
})
</script>

<style scoped lang="scss">
.overview {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.overview__head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 8px 12px;
}

.overview__description {
  color: var(--color-text-secondary);
  font-size: 0.9rem;
  white-space: pre-line;
  overflow-wrap: anywhere;
}

.overview__progress {
  display: flex;
  flex-direction: column;
  gap: 8px;

  small {
    color: var(--color-text-muted);
    font-size: 0.8rem;
  }
}

.overview__progress-label {
  display: flex;
  justify-content: space-between;
  color: var(--color-text-secondary);
  font-size: 0.85rem;
  font-weight: 600;

  b {
    color: var(--color-text);
    font-variant-numeric: tabular-nums;
  }
}

.overview__dates {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin: 0;
  padding-top: 14px;
  border-top: 1px solid var(--color-border);

  dt {
    color: var(--color-text-muted);
    font-size: 0.75rem;
    font-weight: 600;
  }

  dd {
    margin: 2px 0 0;
    color: var(--color-text);
    font-weight: 600;
  }
}

.timeline {
  margin: 0;
  padding: 16px;
  list-style: none;
}

.timeline__step {
  position: relative;
  display: flex;
  gap: 12px;
  padding-bottom: 18px;

  &:last-child {
    padding-bottom: 0;
  }

  // Connector to the next step.
  &:not(:last-child)::before {
    content: '';
    position: absolute;
    top: 30px;
    bottom: 2px;
    left: 13px;
    width: 2px;
    border-radius: 2px;
    background: var(--color-border);
  }

  &.is-done:not(:last-child)::before {
    background: color-mix(in srgb, var(--color-success) 45%, transparent);
  }
}

.timeline__dot {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 999px;
  background: var(--color-bg-muted);
  color: var(--color-text-faint);
  font-size: 0.8rem;

  .is-done & {
    background: var(--color-success-soft);
    color: var(--color-success);
  }

  .is-in_progress & {
    background: var(--color-primary-soft);
    color: var(--color-primary);
    box-shadow: 0 0 0 4px var(--color-primary-glow);
  }
}

.timeline__body {
  display: flex;
  flex-direction: column;
  min-width: 0;
  padding-top: 3px;

  strong {
    color: var(--color-text);
    font-size: 0.92rem;
    line-height: 1.3;
    overflow-wrap: anywhere;

    .is-pending & {
      color: var(--color-text-secondary);
      font-weight: 600;
    }
  }

  span {
    color: var(--color-text-muted);
    font-size: 0.78rem;
  }
}
</style>
