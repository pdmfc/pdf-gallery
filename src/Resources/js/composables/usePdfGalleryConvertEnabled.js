import { computed, ref, unref } from 'vue'
import { isInertiaDocument } from './isInertiaDocument.js'

function parseEnabled(value) {
  if (value === false || value === 'false' || value === 0 || value === '0') {
    return false
  }

  if (value === true || value === 'true' || value === 1 || value === '1') {
    return true
  }

  return value == null ? false : Boolean(value)
}

function readFromNova() {
  const Nova = typeof globalThis !== 'undefined' ? globalThis.Nova : null

  if (!Nova || typeof Nova.config !== 'function') {
    return null
  }

  const value = Nova.config('pdfGallery')?.convertEnabled

  return value == null ? null : parseEnabled(value)
}

function readFromInertia(inertiaModule) {
  if (!inertiaModule?.usePage) {
    return null
  }

  try {
    const value = inertiaModule.usePage()?.props?.pdfGallery?.convertEnabled

    return value == null ? null : parseEnabled(value)
  } catch {
    return null
  }
}

function readFromDataPage() {
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
    const value = page?.props?.pdfGallery?.convertEnabled

    return value == null ? null : parseEnabled(value)
  } catch {
    return null
  }
}

function resolveConvertEnabled(explicit, inertiaModule) {
  if (explicit != null && explicit !== '') {
    return parseEnabled(explicit)
  }

  const fromNova = readFromNova()

  if (fromNova !== null) {
    return fromNova
  }

  const fromDataPage = readFromDataPage()

  if (fromDataPage !== null) {
    return fromDataPage
  }

  const fromInertia = readFromInertia(inertiaModule)

  if (fromInertia !== null) {
    return fromInertia
  }

  return false
}

export function usePdfGalleryConvertEnabled(convertEnabledProp) {
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

    return resolveConvertEnabled(unref(convertEnabledProp), inertiaModule.value)
  })
}
