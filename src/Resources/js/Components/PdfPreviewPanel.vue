<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { loadPdfDocument } from '../composables/usePdfJs'

const props = defineProps({
  url: {
    type: String,
    default: '',
  },
  title: {
    type: String,
    default: 'Pré-visualização',
  },
  emptyMessage: {
    type: String,
    default: 'Seleccione um PDF para pré-visualizar.',
  },
  showSaveMerged: {
    type: Boolean,
    default: false,
  },
  showExtractPages: {
    type: Boolean,
    default: false,
  },
  extracting: {
    type: Boolean,
    default: false,
  },
  printing: {
    type: Boolean,
    default: false,
  },
  showToolbar: {
    type: Boolean,
    default: false,
  },
  showPrint: {
    type: Boolean,
    default: true,
  },
  showDelete: {
    type: Boolean,
    default: false,
  },
  continuousPages: {
    type: Boolean,
    default: true,
  },
  showDocumentNav: {
    type: Boolean,
    default: false,
  },
  documentIndex: {
    type: Number,
    default: -1,
  },
  documentCount: {
    type: Number,
    default: 0,
  },
  canPrevDocument: {
    type: Boolean,
    default: false,
  },
  canNextDocument: {
    type: Boolean,
    default: false,
  },
})

const emit = defineEmits([
  'print',
  'download',
  'save-merged',
  'extract-pages',
  'delete',
  'prev-document',
  'next-document',
])

const canvasRef = ref(null)
const pageNumber = ref(1)
const pageCount = ref(0)
const scale = ref(1.1)
const loading = ref(false)
const error = ref('')
const extractFrom = ref(1)
const extractTo = ref(1)
const extractRangeManual = ref(false)
const pageCanvasElements = ref([])
const scrollContainerRef = ref(null)

let renderToken = 0

const canPrev = computed(() => !props.continuousPages && pageNumber.value > 1)
const canNext = computed(() => !props.continuousPages && pageNumber.value < pageCount.value)

const canExtract = computed(() => {
  if (!props.showExtractPages || pageCount.value < 1) {
    return false
  }

  const from = Math.min(extractFrom.value, extractTo.value)
  const to = Math.max(extractFrom.value, extractTo.value)

  return from >= 1 && to <= pageCount.value && from <= to
})

const pageSlots = computed(() =>
  Array.from({ length: Math.max(pageCount.value, 0) }, (_, index) => index + 1),
)

const registerPageCanvas = (element, index) => {
  if (element) {
    pageCanvasElements.value[index] = element
  }
}

const renderPaginatedPage = async (token) => {
  if (!props.url || !canvasRef.value) {
    pageCount.value = 0
    return
  }

  const pdf = await loadPdfDocument(props.url)
  pageCount.value = pdf.numPages

  if (token !== renderToken) {
    return
  }

  const safePage = Math.min(Math.max(pageNumber.value, 1), pdf.numPages || 1)
  pageNumber.value = safePage

  const page = await pdf.getPage(safePage)
  const viewport = page.getViewport({ scale: scale.value })
  const canvas = canvasRef.value
  const context = canvas.getContext('2d')

  canvas.width = viewport.width
  canvas.height = viewport.height

  await page.render({
    canvasContext: context,
    viewport,
  }).promise
}

const renderContinuousPages = async (token) => {
  if (!props.url) {
    pageCount.value = 0
    pageCanvasElements.value = []
    return
  }

  const pdf = await loadPdfDocument(props.url)

  if (token !== renderToken) {
    return
  }

  pageCount.value = pdf.numPages
  pageCanvasElements.value = []

  await nextTick()

  if (token !== renderToken) {
    return
  }

  for (let pageNum = 1; pageNum <= pdf.numPages; pageNum += 1) {
    if (token !== renderToken) {
      return
    }

    const canvas = pageCanvasElements.value[pageNum - 1]

    if (!canvas) {
      continue
    }

    const page = await pdf.getPage(pageNum)
    const viewport = page.getViewport({ scale: scale.value })
    const context = canvas.getContext('2d')

    canvas.width = viewport.width
    canvas.height = viewport.height

    await page.render({
      canvasContext: context,
      viewport,
    }).promise
  }
}

const renderPreview = async () => {
  if (!props.url) {
    pageCount.value = 0
    pageCanvasElements.value = []
    return
  }

  const token = ++renderToken
  loading.value = true
  error.value = ''

  try {
    if (props.continuousPages) {
      await renderContinuousPages(token)
    } else {
      await renderPaginatedPage(token)
    }
  } catch (e) {
    if (token === renderToken) {
      error.value = e?.message || 'Não foi possível renderizar o PDF.'
    }
  } finally {
    if (token === renderToken) {
      loading.value = false
    }
  }
}

watch(
  () => [props.url, scale.value, props.continuousPages],
  () => {
    renderPreview()
  },
  { immediate: true, flush: 'post' },
)

watch(
  () => pageNumber.value,
  () => {
    if (!props.continuousPages) {
      renderPreview()
    }
  },
)

watch(
  () => props.url,
  () => {
    pageNumber.value = 1
    pageCount.value = 0
    pageCanvasElements.value = []
    extractFrom.value = 1
    extractTo.value = 1
    extractRangeManual.value = false

    if (scrollContainerRef.value) {
      scrollContainerRef.value.scrollTop = 0
    }
  },
)

watch(pageNumber, (page) => {
  if (!extractRangeManual.value) {
    extractFrom.value = page
    extractTo.value = page
  }
})

const markExtractRangeManual = () => {
  extractRangeManual.value = true
}

const clampExtractRange = () => {
  if (pageCount.value < 1) {
    return
  }

  extractFrom.value = Math.min(Math.max(1, extractFrom.value || 1), pageCount.value)
  extractTo.value = Math.min(Math.max(1, extractTo.value || 1), pageCount.value)
}

const emitExtractPages = () => {
  if (!canExtract.value || props.extracting) {
    return
  }

  clampExtractRange()

  const from = Math.min(extractFrom.value, extractTo.value)
  const to = Math.max(extractFrom.value, extractTo.value)

  emit('extract-pages', { from, to })
}

const goPrev = () => {
  if (canPrev.value) {
    pageNumber.value -= 1
  }
}

const goNext = () => {
  if (canNext.value) {
    pageNumber.value += 1
  }
}

const goPrevDocument = () => {
  if (props.canPrevDocument) {
    emit('prev-document')
  }
}

const goNextDocument = () => {
  if (props.canNextDocument) {
    emit('next-document')
  }
}

const zoomIn = () => {
  scale.value = Math.min(scale.value + 0.15, 3)
}

const zoomOut = () => {
  scale.value = Math.max(scale.value - 0.15, 0.5)
}

onBeforeUnmount(() => {
  renderToken += 1
})
</script>

<template>
  <section class="flex h-full min-h-0 flex-col overflow-hidden bg-gray-900">
    <div
      v-if="url || showToolbar"
      class="flex shrink-0 items-center justify-between gap-3 border-b border-white/10 px-4 py-2"
    >
      <div class="flex flex-wrap items-center gap-2">
        <button
          v-if="showPrint"
          type="button"
          :title="printing ? 'A preparar impressão…' : 'Imprimir'"
          class="preview-icon-btn"
          :disabled="printing"
          @click="emit('print')"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"
            />
          </svg>
        </button>
        <button
          type="button"
          title="Descarregar"
          class="preview-icon-btn"
          @click="emit('download')"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
            />
          </svg>
        </button>
        <button
          v-if="showDelete"
          type="button"
          title="Remover"
          class="preview-icon-btn"
          @click="emit('delete')"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
            />
          </svg>
        </button>
        <button
          v-if="showSaveMerged"
          type="button"
          title="Gravar na galeria"
          class="preview-icon-btn"
          @click="emit('save-merged')"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"
            />
          </svg>
        </button>
      </div>

      <p class="min-w-0 truncate text-right text-xs text-white/80">
        <span class="font-medium text-white/95">{{ title }}</span>
        <span v-if="pageCount && !continuousPages" class="text-white/50"> · {{ pageNumber }}/{{ pageCount }}</span>
        <span v-else-if="pageCount && continuousPages" class="text-white/50"> · {{ pageCount }} págs</span>
        <span
          v-if="showDocumentNav && documentCount > 1"
          class="text-white/50"
        >
          · doc. {{ documentIndex + 1 }}/{{ documentCount }}
        </span>
      </p>
    </div>

    <div ref="scrollContainerRef" class="relative min-h-0 flex-1 overflow-auto p-4">
      <div
        v-if="!url"
        class="flex h-full min-h-[360px] flex-col items-center justify-center px-6 text-center text-gray-400"
      >
        <svg class="mb-4 h-16 w-16 opacity-40" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"
          />
        </svg>
        <p class="text-lg font-medium text-gray-300">
          {{ showToolbar && !url ? title : 'Nenhum PDF seleccionado' }}
        </p>
        <p class="mt-1 max-w-sm text-sm">{{ emptyMessage }}</p>
      </div>
      <div v-else class="flex min-h-[280px] items-start justify-center">
        <div
          v-if="loading"
          class="absolute inset-0 z-10 flex items-center justify-center bg-gray-900/70 text-sm text-gray-200"
        >
          A carregar pré-visualização…
        </div>
        <p v-if="error" class="text-sm text-red-300">{{ error }}</p>
        <div
          v-if="continuousPages && !error"
          class="flex w-full max-w-full flex-col items-center gap-4"
        >
          <canvas
            v-for="page in pageSlots"
            :key="`${url}-${page}`"
            :ref="(element) => registerPageCanvas(element, page - 1)"
            class="max-w-full rounded-lg bg-white shadow-lg"
          />
        </div>
        <canvas
          v-else-if="!error && !loading"
          ref="canvasRef"
          class="max-w-full rounded-lg bg-white shadow-lg"
        />
      </div>
    </div>

    <footer
      v-if="url"
      class="shrink-0 border-t border-white/10 bg-gray-950/95 px-4 py-3"
    >
      <div class="flex flex-wrap items-center justify-center gap-2">
        <template v-if="showDocumentNav">
          <button
            type="button"
            title="Documento anterior"
            class="preview-icon-btn"
            :disabled="!canPrevDocument"
            @click="goPrevDocument"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <button
            type="button"
            title="Documento seguinte"
            class="preview-icon-btn"
            :disabled="!canNextDocument"
            @click="goNextDocument"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </button>

          <span
            v-if="documentCount > 1"
            class="min-w-[4.5rem] px-2 text-center text-xs font-medium text-white/70"
          >
            {{ documentIndex + 1 }} / {{ documentCount }}
          </span>
        </template>

        <template v-else-if="!continuousPages">
          <button
            type="button"
            title="Página anterior"
            class="preview-icon-btn"
            :disabled="!canPrev"
            @click="goPrev"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </button>
          <button
            type="button"
            title="Página seguinte"
            class="preview-icon-btn"
            :disabled="!canNext"
            @click="goNext"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </button>

          <span
            v-if="pageCount"
            class="min-w-[4.5rem] px-2 text-center text-xs font-medium text-white/70"
          >
            {{ pageNumber }} / {{ pageCount }}
          </span>
        </template>

        <div
          v-if="showExtractPages && pageCount > 0"
          class="flex items-center gap-1.5 border-l border-white/10 pl-2"
        >
          <span class="text-[11px] text-white/55">De</span>
          <input
            v-model.number="extractFrom"
            type="number"
            min="1"
            :max="pageCount"
            class="h-8 w-12 rounded-md border border-white/15 bg-black/40 px-1 text-center text-xs text-white focus:border-white/35 focus:outline-none"
            @input="markExtractRangeManual"
            @change="clampExtractRange"
          />
          <span class="text-[11px] text-white/55">Até</span>
          <input
            v-model.number="extractTo"
            type="number"
            min="1"
            :max="pageCount"
            class="h-8 w-12 rounded-md border border-white/15 bg-black/40 px-1 text-center text-xs text-white focus:border-white/35 focus:outline-none"
            @input="markExtractRangeManual"
            @change="clampExtractRange"
          />
          <button
            type="button"
            title="Extrair páginas e gravar na galeria"
            class="preview-icon-btn"
            :disabled="!canExtract || extracting"
            @click="emitExtractPages"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <circle cx="6" cy="6" r="3" stroke-width="2" />
              <circle cx="6" cy="18" r="3" stroke-width="2" />
              <path stroke-linecap="round" stroke-width="2" d="M8.12 8.12L20 20M8.12 15.88L20 4" />
            </svg>
          </button>
        </div>

        <button
          type="button"
          title="Reduzir zoom"
          class="preview-icon-btn"
          @click="zoomOut"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4" />
          </svg>
        </button>
        <button
          type="button"
          title="Aumentar zoom"
          class="preview-icon-btn"
          @click="zoomIn"
        >
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
        </button>
      </div>
    </footer>
  </section>
</template>

<style scoped>
.preview-icon-btn {
  @apply inline-flex h-10 w-10 items-center justify-center rounded-full bg-black/50 text-white transition hover:bg-black/75 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-2 focus:ring-offset-gray-900 disabled:cursor-not-allowed disabled:opacity-35 disabled:pointer-events-none;
}
</style>
