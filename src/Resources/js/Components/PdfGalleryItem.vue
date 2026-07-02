<script setup>
import { computed } from 'vue'

const props = defineProps({
  url: {
    type: String,
    required: true,
  },
  thumbUrl: {
    type: String,
    default: '',
  },
  filename: {
    type: String,
    default: '',
  },
  label: {
    type: String,
    default: '',
  },
  kind: {
    type: String,
    default: 'pdf',
  },
  selected: {
    type: Boolean,
    default: false,
  },
  active: {
    type: Boolean,
    default: false,
  },
  orderIndex: {
    type: Number,
    default: 0,
  },
  pageCount: {
    type: Number,
    default: null,
  },
  sizeBytes: {
    type: Number,
    default: 0,
  },
  isDragSource: {
    type: Boolean,
    default: false,
  },
  canReorder: {
    type: Boolean,
    default: true,
  },
  showSelect: {
    type: Boolean,
    default: true,
  },
  showRemove: {
    type: Boolean,
    default: false,
  },
  showOrderBadge: {
    type: Boolean,
    default: true,
  },
})

const emit = defineEmits(['toggle-select', 'open', 'reorder-pointer-down', 'remove'])

const displayName = computed(() => props.label || props.filename)

const formatSize = (bytes) => {
  if (!bytes) {
    return ''
  }

  if (bytes < 1024) {
    return `${bytes} B`
  }

  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} KB`
  }

  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
</script>

<template>
  <article
    class="pdf-gallery-item group flex h-12 max-w-full items-center gap-1 overflow-visible rounded-lg border bg-white px-1 shadow-sm transition sm:h-[52px]"
    :class="[
      active ? 'border-blue-500 ring-1 ring-blue-200' : 'border-gray-200 hover:border-gray-300',
      selected ? 'border-blue-400 ring-1 ring-blue-100' : '',
      isDragSource ? 'opacity-45' : 'hover:shadow',
    ]"
  >
    <button
      v-if="canReorder"
      type="button"
      class="pdf-gallery-item__btn pdf-gallery-item__btn--reorder"
      aria-label="Arrastar para reordenar"
      @click.stop
      @pointerdown.stop="emit('reorder-pointer-down', $event)"
    >
      <svg class="h-4 w-2.5" viewBox="0 0 8 14" fill="currentColor" aria-hidden="true">
        <circle cx="2" cy="2" r="1.25" />
        <circle cx="6" cy="2" r="1.25" />
        <circle cx="2" cy="7" r="1.25" />
        <circle cx="6" cy="7" r="1.25" />
        <circle cx="2" cy="12" r="1.25" />
        <circle cx="6" cy="12" r="1.25" />
      </svg>
    </button>

    <button
      v-if="showSelect"
      type="button"
      class="pdf-gallery-item__btn pdf-gallery-item__btn--select"
      :class="selected ? 'pdf-gallery-item__btn--select-active' : ''"
      aria-label="Seleccionar"
      @click.stop="emit('toggle-select')"
    >
      <span v-if="selected">✓</span>
    </button>

    <button
      type="button"
      class="pdf-gallery-item__btn pdf-gallery-item__btn--open"
      :aria-label="displayName"
      @click="emit('open')"
    >
      <div
        class="pdf-gallery-item__thumb-wrap"
        :class="{
          'pdf-gallery-item__thumb-wrap--office': kind === 'office' && !thumbUrl,
          'pdf-gallery-item__thumb-wrap--image': kind === 'image' && !thumbUrl,
        }"
      >
        <img
          v-if="thumbUrl"
          :src="thumbUrl"
          :alt="displayName"
          class="pointer-events-none h-full w-full object-cover object-top bg-white"
          loading="lazy"
          draggable="false"
        />
        <svg
          v-else-if="kind === 'office'"
          class="pdf-gallery-item__office-icon"
          viewBox="0 0 24 32"
          aria-hidden="true"
        >
          <rect x="1" y="1" width="22" height="30" rx="2" fill="#ffffff" stroke="#dbeafe" stroke-width="1" />
          <path d="M1 4a2 2 0 0 1 2-2h18a2 2 0 0 1 2 2v7H1V4Z" fill="#5b9bd5" />
          <text
            x="12"
            y="9.5"
            text-anchor="middle"
            fill="#ffffff"
            font-size="7"
            font-weight="700"
            font-family="system-ui, -apple-system, sans-serif"
          >
            W
          </text>
          <rect x="4" y="14" width="16" height="1.25" rx="0.6" fill="#e8edf3" />
          <rect x="4" y="17.5" width="11" height="1.25" rx="0.6" fill="#e8edf3" />
          <rect x="4" y="21" width="13" height="1.25" rx="0.6" fill="#e8edf3" />
          <rect x="4" y="24.5" width="9" height="1.25" rx="0.6" fill="#e8edf3" />
        </svg>
        <div
          v-else-if="kind === 'image'"
          class="pdf-gallery-item__thumb-label pdf-gallery-item__thumb-label--image"
        >
          <span>IMG</span>
        </div>
        <div
          v-else
          class="pdf-gallery-item__thumb-label pdf-gallery-item__thumb-label--other"
        >
          …
        </div>
      </div>

      <div class="min-w-0 flex-1">
        <p class="truncate text-[11px] font-medium leading-tight text-gray-900">
          {{ displayName }}
        </p>
        <p class="truncate text-[10px] leading-tight text-gray-500">
          <span v-if="sizeBytes">{{ formatSize(sizeBytes) }}</span>
          <span v-if="pageCount && sizeBytes"> · </span>
          <span v-if="pageCount">{{ pageCount }} págs</span>
        </p>
      </div>
    </button>

    <div class="pdf-gallery-item__actions">
      <button
        v-if="showRemove"
        type="button"
        class="pdf-gallery-item__btn pdf-gallery-item__btn--remove"
        aria-label="Remover"
        @click.stop="emit('remove')"
      >
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
          />
        </svg>
      </button>

      <span
        v-if="showOrderBadge"
        class="pdf-gallery-item__order"
        aria-hidden="true"
      >
        {{ orderIndex + 1 }}
      </span>
    </div>
  </article>
</template>

<style scoped>
.pdf-gallery-item__btn {
  all: unset;
  box-sizing: border-box;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font: inherit;
  line-height: 1;
  -webkit-appearance: none;
  appearance: none;
  background: transparent;
  border: none;
  box-shadow: none;
  outline: none;
}

.pdf-gallery-item__btn:focus-visible {
  outline: 2px solid #3b82f6;
  outline-offset: 2px;
}

.pdf-gallery-item__btn--reorder {
  height: 2.5rem;
  width: 1.25rem;
  flex-shrink: 0;
  color: #9ca3af;
  border-radius: 0.25rem;
}

.pdf-gallery-item__btn--reorder:hover {
  background: #f9fafb;
  color: #4b5563;
}

.pdf-gallery-item__btn--reorder:active {
  cursor: grabbing;
}

.pdf-gallery-item__btn--select {
  height: 1.25rem;
  width: 1.25rem;
  flex-shrink: 0;
  border-radius: 0.25rem;
  border: 1px solid #d1d5db;
  background: #fff;
  color: #9ca3af;
  font-size: 9px;
  box-shadow: 0 1px 2px rgb(0 0 0 / 0.05);
}

.pdf-gallery-item__btn--select-active {
  border-color: #3b82f6;
  color: #2563eb;
}

.pdf-gallery-item__btn--open {
  min-width: 0;
  flex: 1 1 0%;
  gap: 0.5rem;
  text-align: left;
}

.pdf-gallery-item__actions {
  display: inline-flex;
  flex-shrink: 0;
  align-items: center;
  gap: 0.125rem;
  margin-left: auto;
}

.pdf-gallery-item__btn--remove {
  height: 1.75rem;
  width: 1.75rem;
  flex-shrink: 0;
  border-radius: 0.375rem;
  color: #ef4444;
  background: #fff;
  border: 1px solid #fecaca;
}

.pdf-gallery-item__btn--remove:hover {
  background: #fef2f2;
}

.pdf-gallery-item__btn--remove svg {
  width: 1rem;
  height: 1rem;
}

.pdf-gallery-item__order {
  display: inline-flex;
  height: 1.25rem;
  min-width: 1.25rem;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border-radius: 9999px;
  background: #2563eb;
  padding: 0 0.25rem;
  font-size: 10px;
  font-weight: 700;
  color: #fff;
}

.pdf-gallery-item__thumb-wrap {
  height: 2.25rem;
  width: 1.75rem;
  flex-shrink: 0;
  overflow: hidden;
  border-radius: 0.25rem;
  border: 1px solid #e5e7eb;
  background: #f9fafb;
}

@media (min-width: 640px) {
  .pdf-gallery-item__thumb-wrap {
    height: 2.5rem;
    width: 2rem;
  }
}

.pdf-gallery-item__thumb-wrap--office {
  background: #ffffff;
  border-color: #dbeafe;
}

.pdf-gallery-item__thumb-wrap--image {
  background: #ecfdf5;
  border-color: #bbf7d0;
}

.pdf-gallery-item__office-icon {
  display: block;
  height: 100%;
  width: 100%;
}

.pdf-gallery-item__thumb-label {
  display: flex;
  height: 100%;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  font-size: 7px;
  font-weight: 600;
  line-height: 1;
  text-transform: uppercase;
}

.pdf-gallery-item__thumb-label--image {
  background: #ecfdf5;
  color: #34a36b;
}

.pdf-gallery-item__thumb-label--other {
  color: #9ca3af;
  font-size: 8px;
  text-transform: none;
}
</style>
