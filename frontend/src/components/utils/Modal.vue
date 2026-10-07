<template>
  <Teleport to="body">
    <div v-if="modelValue" class="modal-overlay" @click.self="close">
      <div
        class="modal-content"
        :style="{
          width: typeof effectiveWidth === 'number' ? `${effectiveWidth}px` : effectiveWidth,
          height: typeof effectiveHeight === 'number' ? `${effectiveHeight}px` : effectiveHeight,
        }"
      >
        <div class="modal-header">
          <h3 class="modal-title">{{ title }}</h3>
          <button type="button" class="close-btn" @click="close" aria-label="Fechar">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <line x1="18" y1="6" x2="6" y2="18"></line>
              <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
          </button>
        </div>

        <div class="modal-body">
          <slot />
        </div>

        <div v-if="$slots.footer" class="modal-footer">
          <slot name="footer" />
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue'

/** sm = confirmações/formulários curtos · md = padrão de formulários maiores e listas · lg = telas densas */
const SIZES = {
  sm: { width: 440, height: 'auto' },
  md: { width: 720, height: '80vh' },
  lg: { width: 960, height: '85vh' },
}

const props = defineProps({
  modelValue: {
    type: Boolean,
    required: true,
  },
  title: {
    type: String,
    default: '',
  },
  /** sm | md | lg — usado quando width/height não são informados */
  size: {
    type: String,
    default: 'sm',
  },
  width: {
    type: [Number, String],
    default: null,
  },
  height: {
    type: [Number, String],
    default: null,
  },
})

const emit = defineEmits(['update:modelValue', 'close'])

const activeSize = computed(() => SIZES[props.size] || SIZES.sm)
const effectiveWidth = computed(() => props.width ?? activeSize.value.width)
const effectiveHeight = computed(() => props.height ?? activeSize.value.height)

const close = () => {
  emit('update:modelValue', false)
  emit('close')
}

const handleKeydown = (event) => {
  if (event.key === 'Escape' && props.modelValue) {
    close()
  }
}

onMounted(() => {
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<style scoped lang="scss">
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  width: 100vw;
  height: 100vh;
  background-color: rgba(17, 24, 39, 0.6);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
  padding: 20px;
  animation: fadeIn 0.2s ease-out;
}

.modal-content {
  background: #ffffff;
  border: 1px solid var(--color-border);
  border-radius: 16px;
  box-shadow: var(--shadow-lg);
  display: flex;
  flex-direction: column;
  max-width: 100%;
  max-height: 90vh;
  overflow: hidden;
  animation: slideUp 0.3s cubic-bezier(0.16, 1, 0.3, 1);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px 24px;
  border-bottom: 1px solid var(--color-border);

  .modal-title {
    margin: 0;
    font-size: 1.25rem;
    font-weight: 600;
    color: var(--color-text);
  }

  .close-btn {
    background: transparent;
    border: none;
    color: var(--color-text-muted);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 4px;
    border-radius: 6px;
    transition: all 0.2s;

    &:hover {
      color: var(--color-text);
      background: var(--color-bg-muted);
    }
  }
}

.modal-body {
  padding: 24px;
  flex: 1;
  overflow-y: auto;
  color: var(--color-text-secondary);
}

.modal-footer {
  padding: 16px 24px;
  border-top: 1px solid var(--color-border);
  background: #ffffff;
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes slideUp {
  from {
    opacity: 0;
    transform: translateY(20px) scale(0.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}
</style>
