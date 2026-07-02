import { loadPdfDocument } from './usePdfJs'

const thumbnailCache = new Map()

const cacheKey = (url, maxWidth, maxHeight) => `${url}@${maxWidth}x${maxHeight}`

/**
 * Gera data URL da 1.ª página (canvas off-screen — fiável em miniaturas).
 */
export async function renderPdfThumbnailDataUrl(
  url,
  { maxWidth = 140, maxHeight = 112 } = {}
) {
  if (!url) {
    return null
  }

  const key = cacheKey(url, maxWidth, maxHeight)

  if (thumbnailCache.has(key)) {
    return thumbnailCache.get(key)
  }

  const promise = (async () => {
    const pdf = await loadPdfDocument(url)
    const page = await pdf.getPage(1)
    const baseViewport = page.getViewport({ scale: 1 })
    const width = Math.max(maxWidth, 80)
    const height = Math.max(maxHeight, 64)
    const scale = Math.min(width / baseViewport.width, height / baseViewport.height, 2.5)
    const viewport = page.getViewport({ scale })

    const canvas = document.createElement('canvas')
    canvas.width = Math.max(1, Math.floor(viewport.width))
    canvas.height = Math.max(1, Math.floor(viewport.height))

    const context = canvas.getContext('2d', { alpha: false })

    if (!context) {
      return null
    }

    context.fillStyle = '#ffffff'
    context.fillRect(0, 0, canvas.width, canvas.height)

    await page.render({
      canvasContext: context,
      viewport,
    }).promise

    return canvas.toDataURL('image/jpeg', 0.88)
  })()

  thumbnailCache.set(key, promise)

  try {
    return await promise
  } catch (error) {
    thumbnailCache.delete(key)
    throw error
  }
}

export function clearPdfThumbnailCache(url) {
  if (!url) {
    thumbnailCache.clear()
    return
  }

  for (const key of thumbnailCache.keys()) {
    if (key.startsWith(`${url}@`)) {
      thumbnailCache.delete(key)
    }
  }
}
