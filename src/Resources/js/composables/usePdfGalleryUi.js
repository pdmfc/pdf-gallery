import { computed, ref, unref } from 'vue'
import { isInertiaDocument } from './isInertiaDocument.js'

const DEFAULTS = {
  title: 'Galeria de PDF',
  documentSingular: 'documento',
  documentPlural: 'documentos',
}

function readPdfGalleryConfig(reader) {
  try {
    return reader()
  } catch {
    return null
  }
}

function readFromNova() {
  const Nova = typeof globalThis !== 'undefined' ? globalThis.Nova : null

  if (!Nova || typeof Nova.config !== 'function') {
    return null
  }

  return Nova.config('pdfGallery')
}

function readFromInertia(inertiaModule) {
  if (!inertiaModule?.usePage) {
    return null
  }

  return readPdfGalleryConfig(() => inertiaModule.usePage()?.props?.pdfGallery)
}

function readFromDataPage() {
  if (typeof document === 'undefined') {
    return null
  }

  const raw = document.getElementById('app')?.dataset?.page

  if (!raw) {
    return null
  }

  return readPdfGalleryConfig(() => JSON.parse(raw)?.props?.pdfGallery)
}

function resolveUiValue(explicit, ...sources) {
  if (typeof explicit === 'string' && explicit.trim() !== '') {
    return explicit.trim()
  }

  for (const source of sources) {
    if (source == null) {
      continue
    }

    const value = typeof source === 'function' ? source() : source

    if (typeof value === 'string' && value.trim() !== '') {
      return value.trim()
    }
  }

  return null
}

export function usePdfGalleryUi(titleProp, documentSingularProp, documentPluralProp) {
  const shouldLoadInertia = isInertiaDocument()
  const inertiaModule = ref(null)
  const inertiaReady = ref(!shouldLoadInertia)

  if (shouldLoadInertia) {
    const loadInertia = new Function("return import('@inertiajs/vue3')")

    loadInertia()
      .then((module) => {
        inertiaModule.value = module
      })
      .catch(() => {
        inertiaModule.value = null
      })
      .finally(() => {
        inertiaReady.value = true
      })
  }

  return computed(() => {
    if (shouldLoadInertia) {
      void inertiaReady.value
    }

    const fromNova = readFromNova()
    const fromDataPage = readFromDataPage()
    const fromInertia = readFromInertia(inertiaModule.value)

    const title = resolveUiValue(
      unref(titleProp),
      fromNova?.title,
      fromDataPage?.title,
      fromInertia?.title
    ) ?? DEFAULTS.title

    const documentSingular = resolveUiValue(
      unref(documentSingularProp),
      fromNova?.documentSingular,
      fromDataPage?.documentSingular,
      fromInertia?.documentSingular
    ) ?? DEFAULTS.documentSingular

    const documentPlural = resolveUiValue(
      unref(documentPluralProp),
      fromNova?.documentPlural,
      fromDataPage?.documentPlural,
      fromInertia?.documentPlural
    ) ?? DEFAULTS.documentPlural

    const listLabel = documentPlural.charAt(0).toUpperCase() + documentPlural.slice(1)

    return {
      title,
      documentSingular,
      documentPlural,
      listLabel,
    }
  })
}

export function formatDocumentCount(count, { documentSingular, documentPlural }) {
  const total = Number(count)

  if (!Number.isFinite(total) || total < 0) {
    return documentPlural
  }

  return `${total} ${total === 1 ? documentSingular : documentPlural}`
}
