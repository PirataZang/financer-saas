<template>
  <Select v-model="model" :options="options" :loading="loading" searchable />
</template>

<script setup>
import { onMounted, ref } from 'vue'
import Select from '@/components/utils/Select.vue'
import { apiFetch } from '@/services/api'

// Seletor de clientes ativos (mesmo padrão do CategorySelect): só carrega as opções; o resto
// (label, placeholder, clearable, disabled, class…) passa direto para o Select.
//   <CustomerSelect v-model="form.customer_id" label="Cliente" clearable />
const model = defineModel({ default: '' })
const options = ref([])
const loading = ref(false)

onMounted(async () => {
  loading.value = true
  try {
    const response = await apiFetch('/customers/options')
    if (response.ok) options.value = await response.json()
  } finally {
    loading.value = false
  }
})
</script>
