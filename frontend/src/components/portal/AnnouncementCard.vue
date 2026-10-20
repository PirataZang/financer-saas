<template>
  <article class="p-card announcement">
    <header class="announcement__head">
      <span class="announcement__icon" aria-hidden="true"><i class="fa-solid fa-bullhorn" /></span>
      <div class="announcement__meta">
        <h3>{{ announcement.title }}</h3>
        <p>
          <time :datetime="announcement.created_at">{{ timeAgo(announcement.created_at) }}</time>
          <template v-if="announcement.project && showProject">
            ·
            <router-link :to="{ name: 'PortalProject', params: { slug: $route.params.slug, id: announcement.project.id } }">
              {{ announcement.project.name }}
            </router-link>
          </template>
        </p>
      </div>
    </header>
    <p class="announcement__body">{{ announcement.body }}</p>
  </article>
</template>

<script setup>
import { timeAgo } from '@/utils/date'

defineProps({
  announcement: { type: Object, required: true },
  // Off inside the project's own page (it already says which project).
  showProject: { type: Boolean, default: true },
})
</script>

<style scoped lang="scss">
.announcement__head {
  display: flex;
  gap: 12px;
}

.announcement__icon {
  display: grid;
  flex-shrink: 0;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 10px;
  background: var(--color-primary-soft);
  color: var(--color-primary);
  font-size: 0.9rem;
}

.announcement__meta {
  min-width: 0;

  h3 {
    color: var(--color-text);
    font-size: 0.95rem;
    font-weight: 700;
    line-height: 1.3;
  }

  p {
    color: var(--color-text-muted);
    font-size: 0.78rem;
  }

  a {
    color: var(--color-primary);
    font-weight: 600;
  }
}

.announcement__body {
  margin-top: 10px;
  color: var(--color-text-secondary);
  font-size: 0.9rem;
  white-space: pre-line;
  overflow-wrap: anywhere;
}
</style>
