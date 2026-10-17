<template>
  <Modal v-model="isOpen" title="Histórico de Alterações" size="lg">
    <div class="activity-log">
      <Input type="search" v-model="search" placeholder="Buscar por usuário, campo alterado ou valor...">
        <template #prefix>
          <i class="fa-solid fa-magnifying-glass" aria-hidden="true" />
        </template>
      </Input>

      <div class="filter-row">
        <button
          v-for="f in FILTERS"
          :key="f.value"
          type="button"
          class="filter-chip"
          :class="{ 'is-active': filter === f.value }"
          @click="filter = f.value"
        >
          <i :class="f.icon" aria-hidden="true" />
          {{ f.label }}
        </button>
      </div>

      <div v-if="loading" class="tab-loading"><i class="fa-solid fa-spinner fa-spin" /> Carregando histórico...</div>
      <p v-else-if="!filtered.length" class="empty-note">Nenhum registro de alteração encontrado.</p>

      <div v-else class="entry-list">
        <div v-for="entry in filtered" :key="entry.id" class="entry-card">
          <div class="entry-avatar">{{ initials(entry.causer_name) }}</div>

          <div class="entry-body">
            <div class="entry-header">
              <span><strong>{{ entry.causer_name }}</strong> {{ ACTION_LABEL[entry.event] || entry.event }}</span>
              <span class="change-count">{{ entry.changes.length }} alteraç{{ entry.changes.length === 1 ? 'ão' : 'ões' }}</span>
            </div>
            <span class="entry-time">{{ formatDate(entry.created_at) }}</span>

            <div class="field-list">
              <div v-for="c in entry.changes" :key="c.field" class="field-item">
                <span class="field-name">{{ humanizeField(c.field) }}</span>
                <div class="field-values">
                  <div v-if="entry.event !== 'created'" class="value-box value-box--before">
                    <span class="value-label">ANTES</span>
                    <span class="value-text">{{ humanizeValue(c.before) }}</span>
                  </div>
                  <div v-if="entry.event !== 'deleted'" class="value-box value-box--after">
                    <span class="value-label">DEPOIS</span>
                    <span class="value-text">{{ humanizeValue(c.after) }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import Modal from '@/components/utils/Modal.vue'
import Input from '@/components/utils/Input.vue'
import { apiFetch } from '@/services/api'

const props = defineProps({
  modelValue: { type: Boolean, required: true },
  subjectType: { type: String, required: true },
  subjectId: { type: [String, Number], required: true },
})
const emit = defineEmits(['update:modelValue'])

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})

const FILTERS = [
  { value: 'all', label: 'Todos', icon: 'fa-solid fa-list' },
  { value: 'created', label: 'Criações', icon: 'fa-solid fa-plus' },
  { value: 'updated', label: 'Edições', icon: 'fa-solid fa-pen' },
  { value: 'deleted', label: 'Exclusões', icon: 'fa-solid fa-trash-can' },
]

const ACTION_LABEL = {
  created: 'criou este registro',
  updated: 'atualizou este registro',
  deleted: 'excluiu este registro',
}

const loading = ref(false)
const activities = ref([])
const search = ref('')
const filter = ref('all')

const fetchActivities = async () => {
  loading.value = true
  try {
    const url = `/activity-log?subject_type=${props.subjectType}&subject_id=${props.subjectId}`
    activities.value = await apiFetch(url).then((r) => r.json())
  } catch (err) {
    activities.value = []
  } finally {
    loading.value = false
  }
}

watch(isOpen, (open) => {
  if (open) fetchActivities()
})

const filtered = computed(() => {
  let list = activities.value
  if (filter.value !== 'all') list = list.filter((a) => a.event === filter.value)

  const term = search.value.trim().toLowerCase()
  if (!term) return list

  return list.filter((a) => {
    if (a.causer_name.toLowerCase().includes(term)) return true
    return a.changes.some((c) =>
      c.field.toLowerCase().includes(term) ||
      String(c.before ?? '').toLowerCase().includes(term) ||
      String(c.after ?? '').toLowerCase().includes(term),
    )
  })
})

const initials = (name) => (name || '?').trim().charAt(0).toUpperCase()

const humanizeField = (field) => field.replace(/_/g, ' ').toUpperCase()

const humanizeValue = (value) => {
  if (value === null || value === undefined || value === '') return '—'
  if (typeof value === 'boolean') return value ? 'Sim' : 'Não'
  return String(value)
}

const formatDate = (value) => {
  if (!value) return '—'
  return new Date(value).toLocaleString('pt-BR')
}
</script>

<style scoped lang="scss">
.activity-log {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.filter-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.tab-loading,
.empty-note {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 32px;
  color: var(--color-text-muted);
  font-size: 0.875rem;
}

.entry-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.entry-card {
  display: flex;
  gap: 12px;
  padding: 16px;
  border: 1px solid var(--color-border);
  border-radius: 14px;
  background: #ffffff;
}

.entry-avatar {
  flex: 0 0 36px;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: var(--color-primary);
  color: #fff;
  font-weight: 700;
  font-size: 0.8125rem;
}

.entry-body {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.entry-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 0.875rem;
  color: var(--color-text-secondary);
}

.change-count {
  flex-shrink: 0;
  padding: 2px 10px;
  border-radius: 999px;
  background: var(--color-info-soft);
  color: var(--color-info);
  font-size: 0.75rem;
  font-weight: 600;
}

.entry-time {
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.field-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 10px;
}

.field-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field-name {
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.03em;
  color: var(--color-text-muted);
}

.field-values {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
  gap: 8px;
}

.value-box {
  padding: 8px 12px;
  border-radius: 10px;
  overflow-wrap: anywhere;
}

.value-box--before {
  background: var(--color-danger-soft);
}

.value-box--after {
  background: var(--color-success-soft);
}

.value-label {
  display: block;
  font-size: 0.65rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  margin-bottom: 2px;
}

.value-box--before .value-label {
  color: var(--color-danger);
}

.value-box--after .value-label {
  color: var(--color-success);
}

.value-text {
  font-size: 0.8125rem;
  color: var(--color-text);
}
</style>
