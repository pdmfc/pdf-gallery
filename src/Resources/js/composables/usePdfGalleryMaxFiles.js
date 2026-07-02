import { computed, ref, unref } from 'vue'
import { isInertiaDocument } from './isInertiaDocument.js'

function parseMaxFiles(value) {
  const n = Number(value)

  return Number.isFinite(n) && n > 0 ? Math.round(n) : 100
}

function getNovaMaxFiles() {
  const Nova = typeof globalThis !== 'undefined' ? globalThis.Nova : null

  if (!Nova || typeof Nova.config !== 'function') {
    return null
  }

  const value = Nova.config('pdfGallery')?.maxFiles

  return value == null ? null : parseMaxFiles(value)
}

function getInertiaMaxFiles(inertiaModule) {
  if (!inertiaModule?.usePage) {
    return null
  }

  try {
    const value = inertiaModule.usePage()?.props?.pdfGallery?.maxFiles

    return value == null ? null : parseMaxFiles(value)
  } catch {
    return null
  }
}

function readMaxFilesFromDataPage() {
  if (typeof document === 'undefined') {
    return null
  }

  const app = document.getElementById('app')
  const raw = app?.dataset?.page

  if (!raw) {
    return null
  }

  try {
    const page = JSON.parse(raw)
    const value = page?.props?.pdfGallery?.maxFiles

    return value == null ? null : parseMaxFiles(value)
  } catch {
    return null
  }
}

function resolveMaxFiles(explicit, inertiaModule) {
  if (explicit != null && explicit !== '') {
    return parseMaxFiles(explicit)
  }

  const fromNova = getNovaMaxFiles()

  if (fromNova !== null) {
    return fromNova
  }

  const fromDataPage = readMaxFilesFromDataPage()

  if (fromDataPage !== null) {
    return fromDataPage
  }

  const fromInertia = getInertiaMaxFiles(inertiaModule)

  if (fromInertia !== null) {
    return fromInertia
  }

  return 100
}

/**
 * Limite máximo de PDFs na galeria (PDF_GALLERY_MAX_FILES).
 */
export function usePdfGalleryMaxFiles(maxFilesProp) {
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

    return resolveMaxFiles(unref(maxFilesProp), inertiaModule.value)
  })
}
