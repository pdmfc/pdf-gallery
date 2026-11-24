const DOCUMENT_KIND_EXTENSIONS = { pdf: 'pdf', image: 'jpg' }

/**
 * Build a download filename that always carries a usable extension.
 *
 * Labels for autos are often human titles without ".pdf"; browsers then save
 * extensionless files. Prefer an explicit extension on the label, else the
 * stored filename, else the document kind, else pdf.
 *
 * @param {{label?: string, filename?: string, kind?: string}|null|undefined} document
 * @param {{merged?: boolean}} [options]
 * @returns {string}
 */
export function resolveDocumentDownloadName(document, { merged = false } = {}) {
  if (merged) {
    return 'documentos-unidos.pdf'
  }

  const base = String(document?.label || document?.filename || '').trim() || 'documento'
  const filename = String(document?.filename || '')

  if (/\.[a-z0-9]{1,8}$/i.test(base)) {
    return base
  }

  const match = filename.match(/\.([a-z0-9]{1,8})$/i)
  const extension = (match?.[1] || DOCUMENT_KIND_EXTENSIONS[document?.kind] || 'pdf').toLowerCase()

  return `${base}.${extension}`
}
