import { computed, ref, unref } from 'vue'
import { isInertiaDocument } from './isInertiaDocument.js'

function parseMergeMaxFiles(value) {
  const n = Number(value)

  return Number.isFinite(n) && n >= 2 ? Math.round(n) : 50
}

function getNovaMergeMaxFiles() {
  const Nova = typeof globalThis !== 'undefined' ? globalThis.Nova : null

  if (!Nova || typeof Nova.config !== 'function') {
    return null
  }

  const value = Nova.config('pdfGallery')?.mergeMaxFiles

  return value == null ? null : parseMergeMaxFiles(value)
}

function getInertiaMergeMaxFiles(inertiaModule) {
  if (!inertiaModule?.usePage) {
    return null
  }

  try {
    const value = inertiaModule.usePage()?.props?.pdfGallery?.mergeMaxFiles

    return value == null ? null : parseMergeMaxFiles(value)
  } catch {
    return null
  }
}

function readMergeMaxFilesFromDataPage() {
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
    const value = page?.props?.pdfGallery?.mergeMaxFiles

    return value == null ? null : parseMergeMaxFiles(value)
  } catch {
    return null
  }
}

function resolveMergeMaxFiles(explicit, inertiaModule) {
  if (explicit != null && explicit !== '') {
    return parseMergeMaxFiles(explicit)
  }

  const fromNova = getNovaMergeMaxFiles()

  if (fromNova !== null) {
    return fromNova
  }

  const fromDataPage = readMergeMaxFilesFromDataPage()

  if (fromDataPage !== null) {
    return fromDataPage
  }

  const fromInertia = getInertiaMergeMaxFiles(inertiaModule)

  if (fromInertia !== null) {
    return fromInertia
  }

  return 50
}

/**
 * Máximo de PDFs por junção (PDF_GALLERY_MERGE_MAX_FILES).
 */
export function usePdfGalleryMergeMaxFiles(mergeMaxFilesProp) {
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

    return resolveMergeMaxFiles(unref(mergeMaxFilesProp), inertiaModule.value)
  })
}
