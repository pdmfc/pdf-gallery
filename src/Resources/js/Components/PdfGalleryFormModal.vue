<script setup>
import { computed, watch, onUnmounted, toRef } from 'vue'
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
})

const emit = defineEmits(['update:open', 'close', 'useInForm'])

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

const onUseInForm = (payload) => {
  emit('useInForm', payload)
  closeModal()
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
        <button
          type="button"
          title="Fechar"
          class="shrink-0 rounded-lg bg-white/10 px-4 py-2 text-sm font-medium text-white transition hover:bg-white/20"
          @click="closeModal"
        >
          Fechar
        </button>
      </header>

      <div class="min-h-0 flex-1 overflow-hidden pdf-gallery-root">
        <PdfGallery
          v-if="userId"
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
          @use-in-form="onUseInForm"
        />
      </div>
    </div>
  </Teleport>
</template>
