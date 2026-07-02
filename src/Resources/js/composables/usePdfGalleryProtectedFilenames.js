import { computed, ref, unref } from 'vue'
import { isInertiaDocument } from './isInertiaDocument.js'

function parseProtectedFilenames(value) {
  if (!Array.isArray(value)) {
    return []
  }

  return value
    .map((item) => String(item ?? '').trim())
    .filter(Boolean)
}

export function basenameOfGalleryFilename(filename) {
  const parts = String(filename ?? '').split(/[/\\]/)

  return parts[parts.length - 1] || ''
}

function getNovaProtectedFilenames() {
  const Nova = typeof globalThis !== 'undefined' ? globalThis.Nova : null

  if (!Nova || typeof Nova.config !== 'function') {
    return null
  }

  const value = Nova.config('pdfGallery')?.protectedFilenames

  return value == null ? null : parseProtectedFilenames(value)
}

function getInertiaProtectedFilenames(inertiaModule) {
  if (!inertiaModule?.usePage) {
    return null
  }

  try {
    const value = inertiaModule.usePage()?.props?.pdfGallery?.protectedFilenames

    return value == null ? null : parseProtectedFilenames(value)
  } catch {
    return null
  }
}

function readProtectedFilenamesFromDataPage() {
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
    const value = page?.props?.pdfGallery?.protectedFilenames

    return value == null ? null : parseProtectedFilenames(value)
  } catch {
    return null
  }
}

function resolveProtectedFilenames(explicit, inertiaModule) {
  const names = new Set()

  if (explicit != null) {
    parseProtectedFilenames(explicit).forEach((name) => names.add(name))
  }

  const fromNova = getNovaProtectedFilenames()

  if (fromNova !== null) {
    fromNova.forEach((name) => names.add(name))
  }

  const fromDataPage = readProtectedFilenamesFromDataPage()

  if (fromDataPage !== null) {
    fromDataPage.forEach((name) => names.add(name))
  }

  const fromInertia = getInertiaProtectedFilenames(inertiaModule)

  if (fromInertia !== null) {
    fromInertia.forEach((name) => names.add(name))
  }

  return [...names]
}

export function isGalleryFilenameProtected(filename, protectedFilenames) {
  const basename = basenameOfGalleryFilename(filename)

  if (!basename) {
    return false
  }

  const names = protectedFilenames instanceof Set
    ? [...protectedFilenames]
    : protectedFilenames

  return names.some((name) => basenameOfGalleryFilename(name) === basename)
}

/**
 * Ficheiros da galeria que não podem ser eliminados (config PDF_GALLERY / Nova pdfGallery).
 */
export function usePdfGalleryProtectedFilenames(protectedFilenamesProp) {
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

    return new Set(
      resolveProtectedFilenames(unref(protectedFilenamesProp), inertiaModule.value).map(
        (name) => basenameOfGalleryFilename(name),
      ),
    )
  })
}
