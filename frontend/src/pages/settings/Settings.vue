<template>
  <div class="page fade-in">
    <PageHeader title="Configurações" :subtitle="subtitle" icon="fa-solid fa-gear" />

    <Tabs v-model="activeTab" :tabs="tabs" label="Seções de configurações" />

    <section
      :id="`tabpanel-${activeTab}`"
      class="form-card"
      role="tabpanel"
      :aria-labelledby="`tab-${activeTab}`"
    >
      <component :is="current.component" />
    </section>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import PageHeader from '@/components/ui/PageHeader.vue'
import Tabs from '@/components/ui/Tabs.vue'
import CompanyTab from './CompanyTab.vue'
import AppearanceTab from './AppearanceTab.vue'
import FinanceTab from './FinanceTab.vue'
import TelegramTab from './TelegramTab.vue'
import PortalTab from './PortalTab.vue'
import PreferencesTab from './PreferencesTab.vue'

// Company tabs (current company) each need their settings.* permission; Preferências is the user's own.
// The open tab lives in the URL (?tab=telegram), so a link goes straight to it.
const ALL_TABS = [
  { value: 'company', label: 'Empresa', icon: 'fa-solid fa-building', permission: 'settings.company', component: CompanyTab },
  { value: 'appearance', label: 'Aparência', icon: 'fa-solid fa-palette', permission: 'settings.appearance', component: AppearanceTab },
  { value: 'finance', label: 'Financeiro', icon: 'fa-solid fa-wallet', permission: 'settings.finance', component: FinanceTab },
  { value: 'portal', label: 'Portal do cliente', icon: 'fa-solid fa-mobile-screen', permission: 'settings.portal', component: PortalTab },
  { value: 'telegram', label: 'Telegram', icon: 'fa-brands fa-telegram', permission: 'settings.telegram', component: TelegramTab },
  { value: 'preferences', label: 'Preferências', icon: 'fa-solid fa-sliders', component: PreferencesTab },
]

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const tabs = computed(() => ALL_TABS.filter((tab) => auth.can(tab.permission)))

const activeTab = computed({
  get: () => (tabs.value.some((tab) => tab.value === route.query.tab) ? route.query.tab : tabs.value[0].value),
  set: (tab) => router.replace({ query: { ...route.query, tab } }),
})

const current = computed(() => tabs.value.find((tab) => tab.value === activeTab.value))

const subtitle = computed(() =>
  tabs.value.length > 1
    ? `Ajustes de ${auth.user?.company?.trade_name || auth.user?.company?.name || 'sua empresa'} e as suas preferências.`
    : 'As suas preferências de uso do sistema.',
)
</script>
