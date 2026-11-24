import { ensurePdfWorker, loadPdfDocument } from './usePdfJs'

const PRINT_SCALE = 2
const PRINT_IMAGE_QUALITY = 0.92
const PRINT_CLEANUP_FALLBACK_MS = 60000

/**
 * Load a PDF for printing. Blob URLs are fetched as ArrayBuffer so pdf.js
 * does not depend on opaque blob CORS quirks.
 *
 * @param {string} url
 * @returns {Promise<import('pdfjs-dist').PDFDocumentProxy>}
 */
async function loadPdfForPrint(url) {
  if (url.startsWith('blob:')) {
    const response = await fetch(url)

    if (!response.ok) {
      throw new Error(`Não foi possível carregar o PDF (HTTP ${response.status}).`)
    }

    const data = await response.arrayBuffer()

    if (data.byteLength === 0) {
      throw new Error('O PDF recebido está vazio ou é inválido.')
    }

    return ensurePdfWorker().getDocument({ data }).promise
  }

  return loadPdfDocument(url)
}

/**
 * Rasterise every page so the print iframe is plain HTML (works in Edge,
 * where contentWindow.print() on a PDF iframe is a no-op).
 *
 * @param {import('pdfjs-dist').PDFDocumentProxy} pdf
 * @returns {Promise<string[]>}
 */
async function renderPagesAsImages(pdf) {
  const images = []

  for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
    const page = await pdf.getPage(pageNumber)
    const viewport = page.getViewport({ scale: PRINT_SCALE })
    const canvas = document.createElement('canvas')
    const context = canvas.getContext('2d')

    canvas.width = viewport.width
    canvas.height = viewport.height

    await page.render({
      canvasContext: context,
      viewport,
      background: '#ffffff',
    }).promise

    images.push(canvas.toDataURL('image/jpeg', PRINT_IMAGE_QUALITY))
  }

  return images
}

/**
 * @param {string[]} images
 * @returns {string}
 */
function buildPrintIframeHtml(images) {
  const body = images.map((src) => `<img src="${src}">`).join('')

  return [
    '<!doctype html><html><head><meta charset="utf-8"><style>',
    'html, body { margin: 0; padding: 0; }',
    'img { display: block; width: 100%; page-break-after: always; break-after: page; }',
    'img:last-child { page-break-after: auto; }',
    '</style></head><body>',
    body,
    '</body></html>',
  ].join('')
}

/**
 * @param {string} html
 * @returns {Promise<void>}
 */
function printIframeHtml(html) {
  return new Promise((resolve, reject) => {
    const iframe = document.createElement('iframe')

    iframe.setAttribute('aria-hidden', 'true')
    iframe.style.position = 'fixed'
    iframe.style.right = '0'
    iframe.style.bottom = '0'
    iframe.style.width = '0'
    iframe.style.height = '0'
    iframe.style.border = '0'

    let settled = false

    const settle = (error) => {
      if (settled) {
        return
      }

      settled = true
      iframe.remove()

      if (error) {
        reject(error)
      } else {
        resolve()
      }
    }

    iframe.onload = () => {
      try {
        const win = iframe.contentWindow
        const doc = win.document

        doc.open()
        doc.write(html)
        doc.close()

        Promise.all([...doc.images].map((img) => img.decode().catch(() => {}))).then(() => {
          if (settled) {
            return
          }

          win.addEventListener('afterprint', () => settle(), { once: true })
          win.focus()
          win.print()

          // Safety net for browsers where `afterprint` is unreliable.
          setTimeout(() => settle(), PRINT_CLEANUP_FALLBACK_MS)
        })
      } catch (error) {
        settle(error)
      }
    }

    iframe.onerror = () => settle(new Error('Não foi possível preparar a impressão.'))

    document.body.appendChild(iframe)
  })
}

/**
 * Print a PDF URL via pdf.js rasterisation (Edge-safe).
 *
 * @param {string} url
 * @returns {Promise<void>}
 */
export async function printPdfDocument(url) {
  const pdf = await loadPdfForPrint(url)
  const images = await renderPagesAsImages(pdf)

  if (images.length === 0) {
    throw new Error('O PDF não tem páginas para imprimir.')
  }

  await printIframeHtml(buildPrintIframeHtml(images))
}
