<script setup>
import { editorTooltip } from '../composables/editorTooltip.js'
</script>

<template>
  <Teleport to="body">
    <div
      v-show="editorTooltip.visible"
      class="ie-editor-tooltip"
      role="tooltip"
      :style="{
        left: `${editorTooltip.left}px`,
        top: `${editorTooltip.top}px`,
      }"
    >
      {{ editorTooltip.text }}
    </div>
  </Teleport>
</template>

<style>
.ie-editor-tooltip {
  position: fixed;
  z-index: 100000;
  max-width: min(18rem, calc(100vw - 1.5rem));
  padding: 0.5rem 0.875rem;
  border-radius: 0.625rem;
  background: #000;
  color: #fff;
  font-family: system-ui, -apple-system, 'Segoe UI', sans-serif;
  font-size: 0.8125rem;
  font-weight: 600;
  line-height: 1.35;
  text-align: center;
  pointer-events: none;
  white-space: normal;
  box-shadow: 0 6px 20px rgba(0, 0, 0, 0.35);
  transform: translate(-50%, calc(-100% - 0.625rem));
  animation: ie-editor-tooltip-in 0.1s ease-out;
}

.ie-editor-tooltip::after {
  content: '';
  position: absolute;
  left: 50%;
  bottom: -0.375rem;
  transform: translateX(-50%);
  border-width: 0.375rem 0.375rem 0;
  border-style: solid;
  border-color: #000 transparent transparent transparent;
}

@keyframes ie-editor-tooltip-in {
  from {
    opacity: 0;
    transform: translate(-50%, calc(-100% - 0.35rem));
  }
  to {
    opacity: 1;
    transform: translate(-50%, calc(-100% - 0.625rem));
  }
}
</style>
