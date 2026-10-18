<template>
  <div class="company-fields">
    <h3 class="form-section">Dados da empresa</h3>
    <div class="form-grid">
      <Input class="col-6" v-model="form.name" label="Razão social / Nome" placeholder="Nome registrado da empresa..." required minlength="2" />

      <Input class="col-6" v-model="form.trade_name" label="Nome fantasia" placeholder="Como a empresa é conhecida" />

      <Input class="col-4" v-model="form.document" label="CPF / CNPJ" mask="cpf-cnpj" placeholder="000.000.000-00" />

      <TextArea class="col-12" v-model="form.description" label="Descrição" placeholder="Ramo de atuação, observações do contrato..." :rows="3" />
    </div>

    <h3 class="form-section">Endereço</h3>
    <div class="form-grid">
      <div class="col-3">
        <Input v-model="form.zip_code" label="CEP" mask="cep" placeholder="00000-000" @blur="searchZipCode">
          <template v-if="zipLoading" #suffix>
            <i class="fa-solid fa-spinner fa-spin" aria-label="Buscando CEP" />
          </template>
        </Input>
        <p v-if="zipMessage" class="field-hint" :class="{ 'is-error': zipError }" role="status">{{ zipMessage }}</p>
      </div>

      <Input class="col-7" v-model="form.street" label="Rua" placeholder="Rua, avenida..." />

      <Input ref="numberInput" class="col-2" v-model="form.number" label="Número" placeholder="Ex.: 120" maxlength="20" />

      <Input class="col-4" v-model="form.neighborhood" label="Bairro" />

      <Input class="col-4" v-model="form.city" label="Cidade" />

      <Input class="col-2" v-model="form.state" label="Estado" placeholder="UF" />

      <Input class="col-2" v-model="form.country" label="País" />
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useZipCodeLookup } from '@/services/zipCode'
import Input from '@/components/utils/Input.vue'
import TextArea from '@/components/utils/TextArea.vue'

// Company registration fields (data + address with CEP lookup), shared by Restrita › Empresas and
// Configurações › Empresa. v-model = the form object; call markZipLoaded() after loading a record.
const form = defineModel({ type: Object, required: true })
const numberInput = ref(null)

const { zipLoading, zipMessage, zipError, searchZipCode, markZipLoaded } = useZipCodeLookup(form, numberInput)

defineExpose({ markZipLoaded })
</script>
