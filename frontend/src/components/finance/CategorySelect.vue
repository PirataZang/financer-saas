<template>
  <Select v-model="model" :options="options" :loading="loading" searchable />
</template>

<script setup>
import { ref, watch } from 'vue'
import Select from '@/components/utils/Select.vue'
import { apiFetch } from '@/services/api'

// Seletor de categorias financeiras. Só sabe de onde vêm as opções; tudo da lista base
// (multiple, clearable, placeholder, label, disabled, class…) passa direto para o Select:
//   <CategorySelect v-model="ids" type="expense" multiple clearable placeholder="Todas" />
// `type` (income | expense) limita às categorias ativas que aceitam esse tipo.
const props = defineProps({
  type: { type: String, default: '' },
})

const model = defineModel({ default: '' })
const options = ref([])
const loading = ref(false)

const load = async () => {
  loading.value = true
  try {
    const response = await apiFetch(`/finance/categories/options${props.type ? `?type=${props.type}` : ''}`)
    if (response.ok) options.value = await response.json()
  } finally {
    loading.value = false
  }
}

watch(() => props.type, load, { immediate: true })
</script>
