import { computed, unref } from 'vue'

/**
 * Build ordered groups from a flat document list.
 * Documents must appear as contiguous blocks per group_id in the flat list.
 *
 * @param {import('vue').Ref<Array>|Array} documentsRef
 * @returns {Array<{ id: string, label: string, documents: Array }>|null}
 */
export function buildDocumentGroups(documentsRef) {
  const documents = unref(documentsRef)

  if (!Array.isArray(documents) || documents.length === 0) {
    return null
  }

  const groups = []
  const indexByGroupId = new Map()

  for (const document of documents) {
    const groupId = document?.group_id

    if (!groupId) {
      return null
    }

    if (!indexByGroupId.has(groupId)) {
      indexByGroupId.set(groupId, groups.length)
      groups.push({
        id: groupId,
        label: document.group_label || groupId,
        documents: [],
      })
    }

    groups[indexByGroupId.get(groupId)].documents.push(document)
  }

  return groups.length > 0 ? groups : null
}

export function flattenDocumentGroups(groups) {
  return (groups || []).flatMap((group) => group.documents)
}

export function countDistinctGroupIds(documents) {
  if (!Array.isArray(documents)) {
    return 0
  }

  return new Set(
    documents.map((document) => document?.group_id).filter(Boolean),
  ).size
}

export function groupBounds(documents, groupId) {
  let start = -1
  let end = -1

  documents.forEach((document, index) => {
    if (document?.group_id !== groupId) {
      return
    }

    if (start === -1) {
      start = index
    }

    end = index
  })

  return { start, end }
}

export function clampInsertAtToGroup(documents, groupId, insertAt) {
  const bounds = groupBounds(documents, groupId)

  if (bounds.start === -1) {
    return insertAt
  }

  const min = bounds.start
  const max = bounds.end + 1

  return Math.min(Math.max(insertAt, min), max)
}

/**
 * @param {Array} documents
 * @param {number} fromGroupIndex
 * @param {number} insertAtGroupIndex
 */
export function reorderDocumentGroups(documents, fromGroupIndex, insertAtGroupIndex) {
  const groups = buildDocumentGroups(documents)

  if (!groups || fromGroupIndex === null || insertAtGroupIndex === null) {
    return documents
  }

  if (
    fromGroupIndex < 0
    || fromGroupIndex >= groups.length
    || insertAtGroupIndex < 0
    || insertAtGroupIndex > groups.length
  ) {
    return documents
  }

  if (insertAtGroupIndex === fromGroupIndex || insertAtGroupIndex === fromGroupIndex + 1) {
    return documents
  }

  const updatedGroups = groups.map((group) => ({
    ...group,
    documents: [...group.documents],
  }))

  const [moved] = updatedGroups.splice(fromGroupIndex, 1)
  let target = insertAtGroupIndex

  if (fromGroupIndex < insertAtGroupIndex) {
    target -= 1
  }

  updatedGroups.splice(target, 0, moved)

  return flattenDocumentGroups(updatedGroups)
}

export function flatIndexForGroupItem(groups, groupIndex, itemIndex) {
  let index = 0

  for (let i = 0; i < groupIndex; i++) {
    index += groups[i]?.documents?.length || 0
  }

  return index + itemIndex
}

export function resolveGroupInsertAt(clientY, groupHeaderElements) {
  const items = groupHeaderElements

  if (!items?.length) {
    return 0
  }

  for (let i = 0; i < items.length; i++) {
    const rect = items[i].getBoundingClientRect()
    const midpoint = rect.top + rect.height / 2

    if (clientY < midpoint) {
      return i
    }
  }

  return items.length
}

/**
 * @param {import('vue').Ref<Array>|Array} documentsRef
 * @param {import('vue').Ref<string>|string} layoutRef - 'auto' | 'flat' | 'grouped'
 */
export function usePdfGalleryGroups(documentsRef, layoutRef = 'auto') {
  const documentGroups = computed(() => buildDocumentGroups(documentsRef))

  const isGroupedLayout = computed(() => {
    const layout = unref(layoutRef)

    if (layout === 'flat') {
      return false
    }

    const groups = documentGroups.value

    if (!groups) {
      return false
    }

    if (layout === 'grouped') {
      return groups.length >= 1
    }

    return countDistinctGroupIds(unref(documentsRef)) > 1
  })

  return {
    documentGroups,
    isGroupedLayout,
    flattenDocumentGroups,
    reorderDocumentGroups,
    clampInsertAtToGroup,
    flatIndexForGroupItem,
    resolveGroupInsertAt,
  }
}
