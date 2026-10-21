<template>
  <div class="fade-in">
    <h1 class="p-title">Avisos</h1>
    <p class="p-sub">Comunicados da empresa e novidades dos seus projetos.</p>

    <div v-if="loading" class="p-state" role="status">
      <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
      <span>Carregando avisos...</span>
    </div>

    <p v-else-if="error" class="p-state" role="alert">
      <i class="fa-solid fa-triangle-exclamation" aria-hidden="true" />
      {{ error }}
    </p>

    <div v-else-if="announcements.length" class="p-list p-section">
      <AnnouncementCard v-for="item in announcements" :key="item.id" :announcement="item" />
    </div>

    <p v-else class="p-card p-state p-section">
      <i class="fa-solid fa-bell-slash" aria-hidden="true" />
      Nenhum aviso no momento.
    </p>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { portalFetch } from '@/services/api'
import { usePortalStore } from '@/stores/portal'
import AnnouncementCard from '@/components/portal/AnnouncementCard.vue'

const portal = usePortalStore()
const loading = ref(true)
const error = ref('')
const announcements = ref([])

onMounted(async () => {
  try {
    const response = await portalFetch('/portal/announcements')
    if (!response.ok) throw new Error('Não foi possível carregar os avisos. Tente novamente.')
    announcements.value = await response.json()
    portal.markAnnouncementsSeen(announcements.value)
  } catch (err) {
    error.value = err.message
  } finally {
    loading.value = false
  }
})
</script>
