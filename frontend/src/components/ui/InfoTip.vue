<template>
  <span class="info-tip">
    <button
      ref="trigger"
      type="button"
      class="info-tip__trigger"
      :class="{ 'is-open': open }"
      :aria-label="`Sobre ${title || 'este campo'}`"
      :aria-describedby="open ? id : undefined"
      :aria-expanded="open"
      @mouseenter="show"
      @mouseleave="hideSoon"
      @focus="show"
      @blur="hide"
      @click.prevent="open ? hide() : show()"
      @keydown.esc="hide"
    >
      <i class="fa-solid fa-circle-info" aria-hidden="true" />
    </button>

    <Teleport to="body">
      <Transition name="info-tip">
        <div
          v-if="open"
          :id="id"
          role="tooltip"
          class="info-tip__bubble"
          :class="`is-${placement}`"
          :style="position"
          @mouseenter="cancelHide"
          @mouseleave="hideSoon"
        >
          <strong v-if="title">{{ title }}</strong>
          <span>{{ text }}</span>
        </div>
      </Transition>
    </Teleport>
  </span>
</template>

<script setup>
import { onBeforeUnmount, ref, useId } from 'vue'

// Field help ("tipy"): an ⓘ next to a label. Hover, focus or tap shows what the field is and what
// it does; Esc / leaving closes it. Teleported + fixed so modals and cards never clip it.
const props = defineProps({
  title: { type: String, default: '' },
  text: { type: String, required: true },
})

const id = `tip-${useId()}`
const trigger = ref(null)
const open = ref(false)
const placement = ref('top')
const position = ref({})
let timer

const WIDTH = 280
const GAP = 8

const show = () => {
  clearTimeout(timer)
  const rect = trigger.value.getBoundingClientRect()
  // Above when there is room (~110px for a few lines), otherwise below.
  placement.value = rect.top > 120 ? 'top' : 'bottom'
  const left = Math.min(Math.max(rect.left + rect.width / 2 - WIDTH / 2, 12), window.innerWidth - WIDTH - 12)
  position.value = {
    left: `${left}px`,
    width: `${Math.min(WIDTH, window.innerWidth - 24)}px`,
    '--arrow-x': `${rect.left + rect.width / 2 - left}px`,
    ...(placement.value === 'top'
      ? { bottom: `${window.innerHeight - rect.top + GAP}px` }
      : { top: `${rect.bottom + GAP}px` }),
  }
  open.value = true
}

const hide = () => {
  clearTimeout(timer)
  open.value = false
}

// A short grace period lets the pointer travel from the icon to the bubble (to select its text).
const hideSoon = () => {
  clearTimeout(timer)
  timer = setTimeout(hide, 120)
}

const cancelHide = () => clearTimeout(timer)

onBeforeUnmount(() => clearTimeout(timer))
</script>

<style scoped>
.info-tip {
  display: inline-flex;
  vertical-align: middle;
}

.info-tip__trigger {
  display: inline-grid;
  place-items: center;
  width: 18px;
  height: 18px;
  padding: 0;
  border: 0;
  border-radius: var(--radius-full);
  background: transparent;
  color: var(--color-text-faint);
  font-size: 0.8rem;
  cursor: help;
  transition: color 0.15s ease, background 0.15s ease;
}

.info-tip__trigger:hover,
.info-tip__trigger.is-open {
  color: var(--color-primary);
  background: var(--color-primary-soft);
}

.info-tip__trigger:focus-visible {
  outline: 2px solid var(--color-primary);
  outline-offset: 2px;
}
</style>

<style>
/* Teleported to <body>: unscoped, namespaced. */
.info-tip__bubble {
  position: fixed;
  z-index: 3000;
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 12px;
  border-radius: var(--radius-md);
  background: var(--color-text);
  box-shadow: var(--shadow-lg);
  color: #e2e8f0;
  font-family: var(--font-sans);
  font-size: 0.8rem;
  font-weight: 400;
  line-height: 1.45;
  text-align: left;
  white-space: normal;
}

.info-tip__bubble strong {
  color: #ffffff;
  font-size: 0.8rem;
  font-weight: 600;
}

.info-tip__bubble::after {
  content: '';
  position: absolute;
  left: calc(var(--arrow-x) - 5px);
  width: 10px;
  height: 10px;
  background: inherit;
  transform: rotate(45deg);
}

.info-tip__bubble.is-top::after {
  bottom: -4px;
}

.info-tip__bubble.is-bottom::after {
  top: -4px;
}

.info-tip-enter-active,
.info-tip-leave-active {
  transition: opacity 0.14s ease, transform 0.18s cubic-bezier(0.16, 1, 0.3, 1);
}

.info-tip-enter-from,
.info-tip-leave-to {
  opacity: 0;
}

.info-tip-enter-from.is-top {
  transform: translateY(4px);
}

.info-tip-enter-from.is-bottom {
  transform: translateY(-4px);
}

@media (prefers-reduced-motion: reduce) {
  .info-tip-enter-active,
  .info-tip-leave-active {
    transition: opacity 0.1s linear;
  }

  .info-tip-enter-from {
    transform: none;
  }
}
</style>
