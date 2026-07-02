import { computed, ref, unref } from 'vue'
import { isInertiaDocument } from './isInertiaDocument.js'

function parseEnabled(value) {
  if (value === false || value === 'false' || value === 0 || value === '0') {
    return false
  }

  if (value === true || value === 'true' || value === 1 || value === '1') {
    return true
  }

  return value == null ? true : Boolean(value)
}

function getNovaQrCodeEnabled() {
  const Nova = typeof globalThis !== 'undefined' ? globalThis.Nova : null

  if (!Nova || typeof Nova.config !== 'function') {
    return null
  }

  const value = Nova.config('pdfGallery')?.qrCodeEnabled

  return value == null ? null : parseEnabled(value)
}

function getInertiaQrCodeEnabled(inertiaModule) {
  if (!inertiaModule?.usePage) {
    return null
  }

  try {
    const value = inertiaModule.usePage()?.props?.pdfGallery?.qrCodeEnabled

    return value == null ? null : parseEnabled(value)
  } catch {
    return null
  }
}

function readQrCodeEnabledFromDataPage() {
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
    const value = page?.props?.pdfGallery?.qrCodeEnabled

    return value == null ? null : parseEnabled(value)
  } catch {
    return null
  }
}

function resolveQrCodeEnabled(explicit, inertiaModule) {
  if (explicit != null && explicit !== '') {
    return parseEnabled(explicit)
  }

  const fromNova = getNovaQrCodeEnabled()

  if (fromNova !== null) {
    return fromNova
  }

  const fromDataPage = readQrCodeEnabledFromDataPage()

  if (fromDataPage !== null) {
    return fromDataPage
  }

  const fromInertia = getInertiaQrCodeEnabled(inertiaModule)

  if (fromInertia !== null) {
    return fromInertia
  }

  return true
}

export function usePdfGalleryQrCodeEnabled(qrCodeEnabledProp) {
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

    return resolveQrCodeEnabled(unref(qrCodeEnabledProp), inertiaModule.value)
  })
}
