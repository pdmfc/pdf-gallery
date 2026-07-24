<script setup>
import { computed, ref, watch, onUnmounted, toRef, useSlots } from 'vue'
import PdfGallery from './PdfGallery.vue'
import { usePdfGalleryUi } from '../composables/usePdfGalleryUi.js'

const slots = useSlots()

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
  /** Tailwind width classes for the right panel (e.g. `w-full max-w-md`). */
  rightPanelClass: {
    type: String,
    default: 'w-full max-w-md',
  },
})

const emit = defineEmits(['update:open', 'close', 'primary-action'])

const showRightPanel = computed(
  () => props.rightPanelOpen && typeof slots['right-panel'] === 'function'
)

const galleryRef = ref(null)

const ui = usePdfGalleryUi(
  toRef(props, 'title'),
  toRef(props, 'documentSingular'),
  toRef(props, 'documentPlural')
)

const modalTitle = computed(() => props.title || ui.value.title)

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

watch(
  () => props.open,
  (isOpen) => {
    document.body.style.overflow = isOpen ? 'hidden' : ''
  },
  { immediate: true }
)

onUnmounted(() => {
  document.body.style.overflow = ''
})
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="fixed inset-0 flex flex-col bg-gray-900"
      :style="{ zIndex }"
      role="dialog"
      aria-modal="true"
      :aria-label="modalTitle"
    >
      <header
        class="flex shrink-0 items-center justify-between gap-3 border-b border-white/10 bg-gray-900 px-4 py-2.5"
      >
        <p class="truncate text-sm font-medium text-white">
          {{ modalTitle }}
          <span v-if="subtitle" class="text-white/50"> — {{ subtitle }}</span>
        </p>
        <div class="flex shrink-0 items-center gap-2">
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
          v-if="showRightPanel"
          class="flex min-h-0 shrink-0 flex-col overflow-hidden border-l border-white/10 bg-white"
          :class="rightPanelClass"
          role="complementary"
        >
          <slot name="right-panel" />
        </aside>
      </div>
    </div>
  </Teleport>
</template>
