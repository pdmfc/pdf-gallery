<script setup>
import { computed, ref, watch, onUnmounted, toRef } from 'vue'
import PdfGallery from './PdfGallery.vue'
import { usePdfGalleryUi } from '../composables/usePdfGalleryUi.js'

const props = defineProps({
  open: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    default: null,
  },
  subtitle: {
    type: String,
    default: null,
  },
  zIndex: {
    type: [Number, String],
    default: 200,
  },
  userId: {
    type: [String, Number],
    default: null,
  },
  maxFiles: {
    type: Number,
    default: null,
  },
  maxUploadMb: {
    type: Number,
    default: null,
  },
  mergeMaxFiles: {
    type: Number,
    default: null,
  },
  mode: {
    type: String,
    default: 'full',
    validator: (value) => ['full', 'view'].includes(value),
  },
  documentSingular: {
    type: String,
    default: null,
  },
  documentPlural: {
    type: String,
    default: null,
  },
  protectedFilenames: {
    type: Array,
    default: null,
  },
  documentLayout: {
    type: String,
    default: 'auto',
    validator: (value) => ['auto', 'flat', 'grouped'].includes(value),
  },
  primaryActionLabel: {
    type: String,
    default: null,
  },
  primaryActionRequiresDocument: {
    type: Boolean,
    default: false,
  },
  /** Emit the current multi-selection ({ filenames }) instead of a single document. */
  primaryActionRequiresSelection: {
    type: Boolean,
    default: false,
  },
  selectAllOnLoad: {
    type: Boolean,
    default: false,
  },
  mutationsEnabled: {
    type: Boolean,
    default: true,
  },
  /** Show the optional right-hand panel (slot `right-panel`) beside the gallery. */
  rightPanelOpen: {
    type: Boolean,
    default: false,
  },
  /** Extra Tailwind classes for the right panel (width is controlled separately). */
  rightPanelClass: {
    type: String,
    default: '',
  },
  /** Allow dragging the left edge to resize the right panel. */
  rightPanelResizable: {
    type: Boolean,
    default: true,
  },
  /** Initial width in pixels. */
  rightPanelDefaultWidth: {
    type: Number,
    default: 448,
  },
  rightPanelMinWidth: {
    type: Number,
    default: 320,
  },
  /** Max width in px; when null, caps at 70% of the viewport. */
  rightPanelMaxWidth: {
    type: Number,
    default: null,
  },
})

const emit = defineEmits(['update:open', 'close', 'primary-action'])

const galleryRef = ref(null)
const panelWidth = ref(props.rightPanelDefaultWidth)
const isResizing = ref(false)

let resizeStartX = 0
let resizeStartWidth = 0

const ui = usePdfGalleryUi(
  toRef(props, 'title'),
  toRef(props, 'documentSingular'),
  toRef(props, 'documentPlural')
)

const modalTitle = computed(() => props.title || ui.value.title)

const resolvedMaxWidth = () => {
  if (props.rightPanelMaxWidth != null) {
    return props.rightPanelMaxWidth
  }

  return Math.floor(window.innerWidth * 0.7)
}

const clampPanelWidth = (width) =>
  Math.min(resolvedMaxWidth(), Math.max(props.rightPanelMinWidth, Math.round(width)))

const onResizePointerMove = (event) => {
  if (!isResizing.value) {
    return
  }

  // Dragging the left edge: move left → wider panel.
  const delta = resizeStartX - event.clientX
  panelWidth.value = clampPanelWidth(resizeStartWidth + delta)
}

const stopResize = () => {
  if (!isResizing.value) {
    return
  }

  isResizing.value = false
  document.body.style.cursor = ''
  document.body.style.userSelect = ''
  window.removeEventListener('pointermove', onResizePointerMove)
  window.removeEventListener('pointerup', stopResize)
  window.removeEventListener('pointercancel', stopResize)
}

const startResize = (event) => {
  if (!props.rightPanelResizable || (event.button != null && event.button !== 0)) {
    return
  }

  event.preventDefault()
  isResizing.value = true
  resizeStartX = event.clientX
  resizeStartWidth = panelWidth.value
  document.body.style.cursor = 'col-resize'
  document.body.style.userSelect = 'none'
  window.addEventListener('pointermove', onResizePointerMove)
  window.addEventListener('pointerup', stopResize)
  window.addEventListener('pointercancel', stopResize)
}

const closeModal = () => {
  emit('update:open', false)
  emit('close')
}

const handlePrimaryAction = () => {
  if (props.primaryActionRequiresSelection) {
    const selection = galleryRef.value?.resolvePrimaryActionSelection?.()

    if (!selection || selection.error) {
      emit('primary-action', {
        error: selection?.error ?? 'Seleccione pelo menos um documento para continuar.',
      })
      return
    }

    emit('primary-action', selection)
    return
  }

  // When the host does not require a selected document (e.g. coordinator review),
  // continue without treating "no selection" as a blocking error.
  if (!props.primaryActionRequiresDocument) {
    emit('primary-action', {})
    return
  }

  const target = galleryRef.value?.resolvePrimaryActionTarget?.()

  if (!target || target.error) {
    emit('primary-action', { error: target?.error ?? 'Seleccione o documento a enviar.' })
    return
  }

  emit('primary-action', target)
}

defineExpose({
  resolvePrimaryActionTarget: () => galleryRef.value?.resolvePrimaryActionTarget?.(),
  resolvePrimaryActionSelection: () => galleryRef.value?.resolvePrimaryActionSelection?.(),
  reloadAndOpen: (filename) => galleryRef.value?.reloadAndOpen?.(filename),
  openDocument: (filename) => galleryRef.value?.openDocument?.(filename),
  loadDocuments: () => galleryRef.value?.loadDocuments?.(),
})

watch(
  () => props.rightPanelDefaultWidth,
  (width) => {
    if (!isResizing.value) {
      panelWidth.value = clampPanelWidth(width)
    }
  }
)

watch(
  () => props.open,
  (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : ''
  },
  { immediate: true }
)

onUnmounted(() => {
  stopResize()
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 flex flex-col bg-gray-900"
      :class="{ 'select-none': isResizing }"
      :style="{ zIndex }"
      role="dialog"
      aria-modal="true"
      :aria-label="modalTitle"
    >
      <header
        class="flex shrink-0 items-center justify-between gap-3 border-b border-white/10 bg-gray-900 px-4 py-2.5"
      >
        <p class="min-w-0 shrink truncate text-sm font-medium text-white">
          {{ modalTitle }}
          <span v-if="subtitle" class="text-white/50"> — {{ subtitle }}</span>
        </p>
        <div class="flex min-w-0 flex-1 items-center justify-end gap-2">
          <div class="min-w-0 flex-1 overflow-hidden text-right">
            <slot name="header-actions" />
          </div>
          <button
            v-if="primaryActionLabel"
            type="button"
            class="shrink-0 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white transition hover:bg-blue-500"
            @click="handlePrimaryAction"
          >
            {{ primaryActionLabel }}
          </button>
          <button
            type="button"
            title="Fechar"
            class="shrink-0 rounded-lg bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/20"
            @click="closeModal"
          >
            Fechar
          </button>
        </div>
      </header>

      <div class="flex min-h-0 flex-1 overflow-hidden">
        <div class="min-h-0 min-w-0 flex-1 overflow-hidden pdf-gallery-root">
          <PdfGallery
            v-if="userId"
            ref="galleryRef"
            as-modal
            :mode="mode"
            :user-id="userId"
            :max-files="maxFiles"
            :max-upload-mb="maxUploadMb"
            :merge-max-files="mergeMaxFiles"
            :title="modalTitle"
            :document-singular="ui.documentSingular"
            :document-plural="ui.documentPlural"
            :protected-filenames="protectedFilenames"
            :document-layout="documentLayout"
            :select-all-on-load="selectAllOnLoad"
            :mutations-enabled="mutationsEnabled"
          />
        </div>

        <aside
          v-if="rightPanelOpen"
          class="relative flex min-h-0 shrink-0 flex-col overflow-hidden bg-white shadow-[-6px_0_16px_rgba(0,0,0,0.18)]"
          :class="rightPanelClass"
          :style="{ width: `${panelWidth}px` }"
          role="complementary"
        >
          <div
            v-if="rightPanelResizable"
            class="absolute inset-y-0 left-0 z-20 flex w-4 -translate-x-1/2 cursor-col-resize items-center justify-center"
            title="Redimensionar painel"
            aria-hidden="true"
            @pointerdown="startResize"
          >
            <span
              class="flex h-11 w-3.5 items-center justify-center gap-[2px] rounded-full border border-gray-200 bg-white shadow-md transition"
              :class="isResizing ? 'border-blue-400 bg-blue-50 shadow-lg ring-2 ring-blue-400/30' : 'hover:border-gray-300 hover:shadow-lg'"
            >
              <span
                v-for="n in 3"
                :key="n"
                class="h-3.5 w-0.5 rounded-full"
                :class="isResizing ? 'bg-blue-500' : 'bg-gray-400'"
              />
            </span>
          </div>
          <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
            <slot name="right-panel" />
          </div>
        </aside>
      </div>
    </div>
  </Teleport>
</template>
