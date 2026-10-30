<template>
  <div class="page fade-in">
    <PageHeader
      title="Categorias"
      subtitle="Classifique receitas e despesas. As ativas aparecem no sistema e no bot do Telegram."
      icon="fa-solid fa-tags"
    />

    <div class="list-toolbar">
      <div class="list-toolbar__filters">
        <Input class="search-input" type="search" v-model="search" placeholder="Buscar categoria...">
          <template #prefix>
            <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
          </template>
        </Input>
        <Select class="filter" v-model="typeFilter" :options="typeOptions" placeholder="Todos os tipos" aria-label="Filtrar por tipo" />
        <Select class="filter" v-model="activeFilter" :options="ACTIVE_OPTIONS" placeholder="Ativas e inativas" aria-label="Filtrar por status" />
      </div>

      <div class="list-toolbar__actions">
        <Button
          v-can="'finance.category.create'"
          variant="create"
          icon="fa-solid fa-plus"
          label="Incluir"
          @click="$router.push('/finance/categories/form')"
        />
      </div>
    </div>

    <div v-if="loading" class="empty-state">
      <i class="fa-solid fa-spinner fa-spin" aria-hidden="true" />
      <span>Carregando categorias...</span>
    </div>

    <div v-else-if="!visible.length" class="empty-state">
      <i class="fa-solid fa-tags" aria-hidden="true" />
      <span>{{ categories.length ? 'Nenhuma categoria encontrada para o filtro.' : 'Nenhuma categoria cadastrada.' }}</span>
    </div>

    <ul v-else class="category-grid">
      <li
        v-for="category in visible"
        :key="category.id"
        class="category-card"
        :class="{ 'is-inactive': !category.active }"
        :style="{ '--category-color': category.color }"
      >
        <div class="category-card__main">
          <span class="category-card__icon" aria-hidden="true">{{ category.icon || '🏷️' }}</span>
          <div class="category-card__body">
            <strong class="category-card__name" :title="category.name">{{ category.name }}</strong>
            <div class="category-card__meta">
              <StatusBadge v-bind="typeBadge(category.type)" small />
              <StatusBadge v-if="category.goal" label="Meta" color="info" icon="fa-solid fa-bullseye" small />
              <StatusBadge
                v-if="category.integration_key"
                label="Integração · Mercado Pago"
                color="info"
                icon="fa-solid fa-plug"
                small
                title="Criada pela integração com o Mercado Pago. Dá para mudar nome, ícone e cor; ela sai junto com a integração."
              />
              <span>{{ category.transactions_count }} {{ category.transactions_count === 1 ? 'transação' : 'transações' }}</span>
            </div>
          </div>
        </div>

        <div class="category-card__actions">
          <Switch
            class="category-card__switch"
            :model-value="category.active"
            :text-label="category.active ? 'Ativa' : 'Inativa'"
            :disabled="!auth.can('finance.category.update') || !!category.integration_key"
            :aria-label="`${category.active ? 'Desativar' : 'Ativar'} ${category.name}`"
            @change="toggleActive(category, $event)"
          />
          <Button
            v-can="'finance.category.update'"
            variant="ghost"
            size="sm"
            icon="fa-solid fa-pen-to-square"
            icon-only
            :aria-label="`Alterar ${category.name}`"
            title="Alterar"
            @click="$router.push(`/finance/categories/form/${category.id}`)"
          />
          <Button
            variant="ghost"
            size="sm"
            icon="fa-solid fa-clock-rotate-left"
            icon-only
            :aria-label="`Histórico de ${category.name}`"
            title="Histórico"
            @click="historyId = category.id"
          />
          <!-- A meta's category is deleted with the meta (Metas screen); an integration's, with the integration. -->
          <Button
            v-if="!category.goal && !category.integration_key"
            v-can="'finance.category.delete'"
            variant="ghost"
            size="sm"
            icon="fa-solid fa-trash-can"
            icon-only
            class="is-danger"
            :aria-label="`Excluir ${category.name}`"
            title="Excluir"
            @click="remove(category)"
          />
        </div>
      </li>
    </ul>

    <ActivityLogModal
      v-if="historyId"
      :model-value="!!historyId"
      subject-type="category"
      :subject-id="historyId"
      @update:model-value="historyId = null"
    />
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import Button from '@/components/utils/Button.vue'
import Input from '@/components/utils/Input.vue'
import Select from '@/components/utils/Select.vue'
import Switch from '@/components/utils/Switch.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import ActivityLogModal from '@/components/ui/ActivityLogModal.vue'
import { apiFetch } from '@/services/api'
import { swal } from '@/plugins/swal'
import { useAuthStore } from '@/stores/auth'
import { normalize } from '@/permissions'

const ACTIVE_OPTIONS = [
  { value: 'active', label: 'Somente ativas' },
  { value: 'inactive', label: 'Somente inativas' },
]

const auth = useAuthStore()
const categories = ref([])
const typeOptions = ref([])
const loading = ref(true)
const search = ref('')
const typeFilter = ref('')
const activeFilter = ref('')
const historyId = ref(null)

// Categories are a short list: loaded once, filtered here.
const visible = computed(() =>
  categories.value.filter(
    (category) =>
      normalize(category.name).includes(normalize(search.value)) &&
      (!typeFilter.value || category.type === typeFilter.value) &&
      (!activeFilter.value || category.active === (activeFilter.value === 'active')),
  ),
)

const typeBadge = (type) => {
  const option = typeOptions.value.find((o) => o.value === type)
  return { label: option?.label ?? type, color: option?.color, icon: option?.icon }
}

const fetchCategories = async () => {
  try {
    const response = await apiFetch('/finance/categories')
    if (response.ok) categories.value = await response.json()
  } finally {
    loading.value = false
  }
}

const toggleActive = async (category, active) => {
  const response = await apiFetch(`/finance/categories/${category.id}`, {
    method: 'PUT',
    body: JSON.stringify({ active }),
  })
  if (!response.ok) {
    swal.toastError((await response.json()).message || 'Não foi possível alterar a categoria.')
    return
  }
  category.active = active
  swal.toastSuccess(active ? 'Categoria ativada.' : 'Categoria desativada.')
}

const remove = async (category) => {
  const ok = await swal.confirmDelete({
    entity: 'categoria',
    feminine: true,
    text: category.transactions_count
      ? `"${category.name}" tem ${category.transactions_count} transação(ões). Elas continuam no histórico com esta categoria, mas ela deixa de aparecer para novos lançamentos. Excluir?`
      : undefined,
  })
  if (!ok) return

  const response = await apiFetch(`/finance/categories/${category.id}`, { method: 'DELETE' })
  if (!response.ok) {
    swal.toastError((await response.json()).message || 'Falha ao excluir a categoria.')
    return
  }
  categories.value = categories.value.filter((c) => c.id !== category.id)
  swal.toastSuccess('Categoria excluída com sucesso!')
}

onMounted(async () => {
  const response = await apiFetch('/finance/categories/types')
  if (response.ok) typeOptions.value = await response.json()
  await fetchCategories()
})
</script>

<style scoped>
.list-toolbar {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 12px;
}

.list-toolbar__filters,
.list-toolbar__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.list-toolbar__filters {
  flex: 1;
}

.list-toolbar .search-input {
  width: 320px;
  max-width: 100%;
}

.list-toolbar .filter {
  width: 200px;
}

.category-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.category-card {
  display: flex;
  flex-direction: column;
  gap: 14px;
  padding: 16px 16px 10px;
  background: var(--color-bg-card);
  border: 1px solid var(--color-border);
  border-left: 4px solid var(--category-color);
  border-radius: var(--radius-xl);
  box-shadow: var(--shadow-sm);
  transition: box-shadow 0.15s ease, opacity 0.15s ease;
}

.category-card:hover {
  box-shadow: var(--shadow-md);
}

.category-card.is-inactive {
  opacity: 0.6;
}

.category-card__main {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;
}

.category-card__icon {
  flex: 0 0 48px;
  height: 48px;
  display: grid;
  place-items: center;
  border-radius: var(--radius-lg);
  background: color-mix(in srgb, var(--category-color) 14%, white);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--category-color) 25%, transparent);
  font-size: 1.5rem;
}

.category-card__body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.category-card__name {
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
  color: var(--color-text);
  font-size: 0.95rem;
}

.category-card__meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

.category-card__actions {
  display: flex;
  align-items: center;
  gap: 2px;
  padding-top: 8px;
  border-top: 1px solid var(--color-border);
}

.category-card__switch {
  margin-right: auto;
}

.category-card__actions .is-danger:hover {
  color: var(--color-danger);
}

.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  padding: 48px 24px;
  background: var(--color-bg-card);
  border: 1px dashed var(--color-border-strong);
  border-radius: var(--radius-xl);
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.empty-state i {
  font-size: 1.4rem;
}
</style>
