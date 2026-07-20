import * as pdfjsLib from 'pdfjs-dist'
import axios from '../http/client'

let workerConfigured = false

function resolveWorkerSrc() {
  // Servido pelo package via rota Laravel (não depende de ficheiro em public/).
  // Evita o worker .mjs gerado pelo Vite em /build/assets.
  return '/pdf-gallery/assets/pdf.worker.min.js'
}

export function ensurePdfWorker() {
  if (workerConfigured || typeof window === 'undefined') {
    return pdfjsLib
  }

  pdfjsLib.GlobalWorkerOptions.workerSrc = resolveWorkerSrc()
  workerConfigured = true

  return pdfjsLib
}

const documentCache = new Map()

export async function loadPdfDocument(url) {
  ensurePdfWorker()

  if (documentCache.has(url)) {
    return documentCache.get(url)
  }

  const promise = (async () => {
    const response = await axios.get(url, {
      responseType: 'arraybuffer',
      headers: {
        Accept: 'application/pdf',
      },
    })

    if (response.status < 200 || response.status >= 300) {
      throw new Error(`Não foi possível carregar o PDF (HTTP ${response.status}).`)
    }

    const data = response.data

    if (!(data instanceof ArrayBuffer) || data.byteLength === 0) {
      throw new Error('O PDF recebido está vazio ou é inválido.')
    }

    const contentType = String(response.headers?.['content-type'] || '')

    if (contentType.includes('application/json')) {
      const json = JSON.parse(new TextDecoder().decode(data))
      throw new Error(json?.error || 'Não foi possível aceder ao PDF.')
    }

    const loadingTask = pdfjsLib.getDocument({ data })
    return loadingTask.promise
  })()

  documentCache.set(url, promise)

  try {
    return await promise
  } catch (error) {
    documentCache.delete(url)
    throw error
  }
}

export function clearPdfDocumentCache(url) {
  if (url) {
    documentCache.delete(url)
    return
  }

  documentCache.clear()
}
