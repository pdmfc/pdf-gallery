export function isInertiaDocument() {
  if (typeof globalThis !== 'undefined' && globalThis.Nova) {
    return false
  }

  if (typeof document === 'undefined') {
    return false
  }

  const app = document.getElementById('app')

  return Boolean(app?.hasAttribute('data-page'))
}
