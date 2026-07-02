import { computed, ref, unref } from 'vue'
import { isInertiaDocument } from './isInertiaDocument.js'

function parseMaxUploadMb(value) {
  const n = Number(value)

  return Number.isFinite(n) && n > 0 ? Math.round(n) : 25
}

function getNovaMaxUploadMb() {
  const Nova = typeof globalThis !== 'undefined' ? globalThis.Nova : null

  if (!Nova || typeof Nova.config !== 'function') {
    return null
  }

  const value = Nova.config('pdfGallery')?.maxUploadMb

  return value == null ? null : parseMaxUploadMb(value)
}

function getInertiaMaxUploadMb(inertiaModule) {
  if (!inertiaModule?.usePage) {
    return null
  }

  try {
    const value = inertiaModule.usePage()?.props?.pdfGallery?.maxUploadMb

    return value == null ? null : parseMaxUploadMb(value)
  } catch {
    return null
  }
}

function readMaxUploadMbFromDataPage() {
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
    const value = page?.props?.pdfGallery?.maxUploadMb

    return value == null ? null : parseMaxUploadMb(value)
  } catch {
    return null
  }
}

function resolveMaxUploadMb(explicit, inertiaModule) {
  if (explicit != null && explicit !== '') {
    return parseMaxUploadMb(explicit)
  }

  const fromNova = getNovaMaxUploadMb()

  if (fromNova !== null) {
    return fromNova
  }

  const fromDataPage = readMaxUploadMbFromDataPage()

  if (fromDataPage !== null) {
    return fromDataPage
  }

  const fromInertia = getInertiaMaxUploadMb(inertiaModule)

  if (fromInertia !== null) {
    return fromInertia
  }

  return 25
}

/**
 * Tamanho máximo de upload por PDF (PDF_GALLERY_MAX_UPLOAD_MB).
 */
export function usePdfGalleryMaxUploadMb(maxUploadMbProp) {
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

    return resolveMaxUploadMb(unref(maxUploadMbProp), inertiaModule.value)
  })
}
