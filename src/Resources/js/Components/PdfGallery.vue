<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, toRef } from 'vue'
import PdfGalleryItem from './PdfGalleryItem.vue'
import PdfPreviewPanel from './PdfPreviewPanel.vue'
import EditorTooltipLayer from './EditorTooltipLayer.vue'
import Notification from './Notification.vue'
import QRCodePopup from './QRCodePopup.vue'
import { usePdfGalleryApi } from '../composables/usePdfGalleryApi'
import { editorTooltipRoot } from '../composables/editorTooltip.js'
import { useEditorNotification } from '../composables/useEditorNotification.js'
import { usePdfGalleryMaxFiles } from '../composables/usePdfGalleryMaxFiles.js'
import { usePdfGalleryMaxUploadMb } from '../composables/usePdfGalleryMaxUploadMb.js'
import { usePdfGalleryMergeMaxFiles } from '../composables/usePdfGalleryMergeMaxFiles.js'
import { usePdfGalleryQrCodeEnabled } from '../composables/usePdfGalleryQrCodeEnabled.js'
import { usePdfGalleryConvertEnabled } from '../composables/usePdfGalleryConvertEnabled.js'
import { usePdfGalleryRealtime } from '../composables/usePdfGalleryRealtime.js'
import { usePdfGalleryProtectedFilenames, isGalleryFilenameProtected } from '../composables/usePdfGalleryProtectedFilenames.js'
import { formatDocumentCount, usePdfGalleryUi } from '../composables/usePdfGalleryUi.js'
import {
  usePdfGalleryGroups,
  flatIndexForGroupItem,
  resolveGroupInsertAt,
  clampInsertAtToGroup,
  reorderDocumentGroups,
} from '../composables/usePdfGalleryGroups.js'

const vEditorTooltipRoot = editorTooltipRoot

const props = defineProps({
  userId: {
    type: [String, Number],
    required: true,
  },
  maxFiles: {
    type: Number,
    default: null,
  },
  maxUploadMb: {
    type: Number,
    default: null,
  },
  mergeMaxFiles: {
    type: Number,
    default: null,
  },
  qrCodeEnabled: {
    type: Boolean,
    default: null,
  },
  convertEnabled: {
    type: Boolean,
    default: null,
  },
  asModal: {
    type: Boolean,
    default: false,
  },
  title: {
    type: String,
    default: null,
  },
  documentSingular: {
    type: String,
    default: null,
  },
  documentPlural: {
    type: String,
    default: null,
  },
  mode: {
    type: String,
    default: 'full',
    validator: (value) => ['full', 'view'].includes(value),
  },
  compact: {
    type: Boolean,
    default: false,
  },
  protectedFilenames: {
    type: Array,
    default: null,
  },
  /** auto: grouped when documents carry group_id; flat: never; grouped: always when group_id exists */
  documentLayout: {
    type: String,
    default: 'auto',
    validator: (value) => ['auto', 'flat', 'grouped'].includes(value),
  },
  /** When true, select every document after the initial list load. */
  selectAllOnLoad: {
    type: Boolean,
    default: false,
  },
})

const maxFiles = usePdfGalleryMaxFiles(toRef(props, 'maxFiles'))
const maxUploadMb = usePdfGalleryMaxUploadMb(toRef(props, 'maxUploadMb'))
const mergeMaxFiles = usePdfGalleryMergeMaxFiles(toRef(props, 'mergeMaxFiles'))
const qrCodeEnabled = usePdfGalleryQrCodeEnabled(toRef(props, 'qrCodeEnabled'))
const convertEnabled = usePdfGalleryConvertEnabled(toRef(props, 'convertEnabled'))
const protectedFilenames = usePdfGalleryProtectedFilenames(toRef(props, 'protectedFilenames'))
const ui = usePdfGalleryUi(
  toRef(props, 'title'),
  toRef(props, 'documentSingular'),
  toRef(props, 'documentPlural')
)

const docLabel = (count) => formatDocumentCount(count, ui.value)

const isViewMode = computed(() => props.mode === 'view')
const isFullMode = computed(() => !isViewMode.value)

const api = usePdfGalleryApi()
const { notification, showNotification, dismissNotification } = useEditorNotification()

const documents = ref([])
const selectedFilenames = ref(new Set())
const activeFilename = ref('')
const previewMode = ref('single')
const mergedPreviewUrl = ref('')
const loading = ref(false)
const uploading = ref(false)
const uploadProgress = ref(0)
const merging = ref(false)
const savingMerged = ref(false)
const extracting = ref(false)
const printing = ref(false)
const mergedSourceFilenames = ref([])
const dragOverUpload = ref(false)
const galleryListRef = ref(null)
const galleryGroupsListRef = ref(null)
const reorderInsertAt = ref(null)
const groupReorderInsertAt = ref(null)
const reorderDragIndex = ref(null)
const reorderDragGroupIndex = ref(null)
const reorderDragFilename = ref('')
const reorderDragScope = ref('item')
const reorderPointerActive = ref(false)
const reorderSaving = ref(false)
const reorderDragPreview = ref({
  visible: false,
  x: 0,
  y: 0,
  thumbUrl: '',
  filename: '',
})
const fileInputRef = ref(null)
const showQRCode = ref(false)
const qrCodeData = ref('')
let mergedObjectUrl = null

const activeDocument = computed(() =>
  documents.value.find((document) => document.filename === activeFilename.value) || null
)

const previewUrl = computed(() => {
  if (previewMode.value === 'merged' && mergedPreviewUrl.value) {
    return mergedPreviewUrl.value
  }

  const document = activeDocument.value

  if (!document?.previewable || !document?.url) {
    return ''
  }

  const version = document.timestamp || 0
  const separator = document.url.includes('?') ? '&' : '?'

  return `${document.url}${separator}v=${version}`
})

const canShowPreviewToolbar = computed(() => {
  if (isViewMode.value) {
    return Boolean(activeDocument.value)
  }

  if (previewMode.value === 'merged' && mergedPreviewUrl.value) {
    return true
  }

  if (activeDocument.value) {
    return true
  }

  return hasSelection.value
})

const previewEmptyMessage = computed(() => {
  if (isViewMode.value) {
    return 'Seleccione um documento para pré-visualizar.'
  }

  if (previewMode.value === 'merged') {
    return 'Seleccione documentos ou junte vários para pré-visualizar.'
  }

  if (activeDocument.value && !activeDocument.value.previewable) {
    if (activeDocument.value.kind === 'office') {
      return 'Pré-visualização indisponível para documentos Word.'
    }

    if (activeDocument.value.kind === 'image') {
      return 'Pré-visualização indisponível para imagens.'
    }

    return 'Pré-visualização indisponível para este documento.'
  }

  return 'Seleccione um documento ou junte vários para pré-visualizar.'
})

const previewTitle = computed(() => {
  if (previewMode.value === 'merged') {
    const order = mergeOrderPreview.value

    return order
      ? `Documento unido (${docLabel(selectedCount.value)}) — ordem ${order}`
      : `Documento unido (${docLabel(selectedCount.value)})`
  }

  return activeDocument.value?.label || activeDocument.value?.filename || 'Pré-visualização'
})

const selectedCount = computed(() => selectedFilenames.value.size)
const hasSelection = computed(() => selectedCount.value > 0)
const selectedDeletableCount = computed(
  () => filterDeletableFilenames([...selectedFilenames.value]).length,
)
const canDeleteSelection = computed(() => selectedDeletableCount.value > 0)
const canMerge = computed(
  () => selectedCount.value >= 2 && selectedCount.value <= mergeMaxFiles.value
)
const allSelected = computed(
  () => documents.value.length > 0 && selectedCount.value === documents.value.length,
)

const galleryRemainingSlots = computed(() => {
  if (!maxFiles.value || maxFiles.value <= 0) {
    return null
  }

  return Math.max(0, maxFiles.value - documents.value.length)
})

const qrMaxFiles = computed(() => {
  const remaining = galleryRemainingSlots.value

  return Number.isFinite(remaining) && remaining > 0 ? remaining : null
})

const isGalleryAtLimit = computed(
  () => maxFiles.value > 0 && documents.value.length >= maxFiles.value
)

const galleryLimitMessage = computed(() => {
  const max = maxFiles.value

  return max > 0
    ? `Limite da galeria atingido (${formatDocumentCount(max, ui.value)}). Elimine ${ui.value.documentPlural} para adicionar novos.`
    : ''
})

const selectedFilenamesInGalleryOrder = () =>
  documents.value
    .map((document) => document.filename)
    .filter((filename) => selectedFilenames.value.has(filename))

const mergeOrderPreview = computed(() => {
  if (selectedCount.value < 2) {
    return ''
  }

  return selectedFilenamesInGalleryOrder()
    .map((_, index) => index + 1)
    .join(' → ')
})

const isReordering = computed(() => reorderPointerActive.value)

const { documentGroups, isGroupedLayout } = usePdfGalleryGroups(
  documents,
  toRef(props, 'documentLayout'),
)

const canSaveMerged = computed(
  () => isFullMode.value && previewMode.value === 'merged' && mergedSourceFilenames.value.length >= 2 && !savingMerged.value
)

const canExtractPages = computed(
  () => isFullMode.value && previewMode.value === 'single' && activeDocument.value?.kind === 'pdf'
)

const isTruthyFlag = (value) => {
  if (value === true || value === 1) {
    return true
  }

  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase()

    return normalized === 'true' || normalized === '1'
  }

  return false
}

const isFalsyFlag = (value) => {
  if (value === false || value === 0) {
    return true
  }

  if (typeof value === 'string') {
    const normalized = value.trim().toLowerCase()

    return normalized === 'false' || normalized === '0'
  }

  return false
}

const normalizeGalleryDocument = (document) => {
  if (!document?.filename) {
    return document
  }

  const normalized = { ...document }
  const filenameProtected = isGalleryFilenameProtected(
    normalized.filename,
    protectedFilenames.value,
  )

  if (
    isTruthyFlag(normalized.protected)
    || isFalsyFlag(normalized.deletable)
    || filenameProtected
  ) {
    normalized.protected = true
    normalized.deletable = false
  } else if (normalized.deletable === undefined && normalized.protected === undefined) {
    normalized.deletable = true
    normalized.protected = false
  }

  return normalized
}

const normalizeGalleryDocuments = (items) =>
  (Array.isArray(items) ? items : []).map((document) => normalizeGalleryDocument(document))

const isDocumentDeletable = (document) => {
  const normalized = normalizeGalleryDocument(document)

  if (!normalized?.filename) {
    return false
  }

  if (isTruthyFlag(normalized.protected)) {
    return false
  }

  if (isFalsyFlag(normalized.deletable)) {
    return false
  }

  if (isGalleryFilenameProtected(normalized.filename, protectedFilenames.value)) {
    return false
  }

  return true
}

const canRemoveDocument = (document) => {
  if (!isFullMode.value && !isViewMode.value) {
    return false
  }

  return isDocumentDeletable(document)
}

const canDeleteActiveDocument = computed(
  () => (isFullMode.value || isViewMode.value) && isDocumentDeletable(activeDocument.value),
)

const filterDeletableFilenames = (filenames) =>
  filenames.filter((filename) => {
    const document = documents.value.find((item) => item.filename === filename)

    return isDocumentDeletable(document)
  })

const applySavedDocument = (document) => {
  const normalized = normalizeGalleryDocument(document)

  if (!normalized?.filename) {
    return
  }

  documents.value.push(normalized)
  activeFilename.value = normalized.filename
  previewMode.value = 'single'
  selectedFilenames.value = new Set([normalized.filename])
  mergedSourceFilenames.value = []
  revokeMergedUrl()
}

const revokeMergedUrl = () => {
  if (mergedObjectUrl) {
    URL.revokeObjectURL(mergedObjectUrl)
    mergedObjectUrl = null
  }

  mergedPreviewUrl.value = ''
  mergedSourceFilenames.value = []
}

const syncSelectionAfterListChange = () => {
  const existing = new Set(documents.value.map((document) => document.filename))
  selectedFilenames.value = new Set(
    [...selectedFilenames.value].filter((filename) => existing.has(filename)),
  )

  if (activeFilename.value && !existing.has(activeFilename.value)) {
    activeFilename.value = documents.value[0]?.filename || ''
    previewMode.value = 'single'
    revokeMergedUrl()
  }
}

const selectAllDocuments = () => {
  selectedFilenames.value = new Set(
    documents.value
      .map((document) => document.filename)
      .filter((filename) => typeof filename === 'string' && filename !== ''),
  )
}

const loadDocuments = async () => {
  loading.value = true

  try {
    const data = await api.listDocuments(props.userId)

    if (data.error) {
      throw new Error(data.error)
    }

    documents.value = normalizeGalleryDocuments(data.documents)

    if (props.selectAllOnLoad) {
      selectAllDocuments()
      await nextTick()

      // Guard against a render race where the first paint still had an empty selection.
      if (
        documents.value.length > 0
        && selectedFilenames.value.size !== documents.value.length
      ) {
        selectAllDocuments()
      }
    } else {
      syncSelectionAfterListChange()
    }

    if (!activeFilename.value && documents.value.length > 0) {
      activeFilename.value = documents.value[0].filename
    }
  } catch (error) {
    showNotification(
      'error',
      'Erro',
      error?.response?.data?.error || error?.message || `Erro ao carregar ${ui.value.documentPlural}.`
    )
  } finally {
    loading.value = false
  }
}

const toggleSelect = (filename) => {
  const next = new Set(selectedFilenames.value)

  if (next.has(filename)) {
    next.delete(filename)
  } else {
    next.add(filename)
  }

  selectedFilenames.value = next
}

const toggleSelectAll = () => {
  if (allSelected.value) {
    selectedFilenames.value = new Set()
    return
  }

  selectAllDocuments()
}

const clearSelection = () => {
  selectedFilenames.value = new Set()
}

const openDocument = (filename) => {
  activeFilename.value = filename
  previewMode.value = 'single'
  revokeMergedUrl()
}

const uploadAccept = computed(() =>
  convertEnabled.value
    ? 'application/pdf,.pdf,image/jpeg,image/png,image/webp,image/gif,.doc,.docx,.odt,.rtf'
    : 'application/pdf,.pdf'
)

const uploadTitle = computed(() => {
  if (uploading.value) {
    return `A carregar… ${uploadProgress.value}%`
  }

  if (convertEnabled.value) {
    return `Carregar ${ui.value.documentPlural} (PDF, imagem ou Word — máx. ${maxUploadMb.value} MB cada)`
  }

  return `Carregar ${ui.value.documentPlural} (máx. ${maxUploadMb.value} MB cada)`
})

const uploadFiles = async (fileList) => {
  if (!isFullMode.value) {
    return
  }

  const convertiblePattern = /\.(jpe?g|png|webp|gif|tiff?|bmp|docx?|odt|rtf)$/i
  const files = Array.from(fileList || []).filter((file) => {
    const name = String(file.name || '').toLowerCase()
    const type = String(file.type || '').toLowerCase()

    if (type.includes('pdf') || name.endsWith('.pdf')) {
      return true
    }

    return convertEnabled.value && (
      type.startsWith('image/') ||
      type.includes('word') ||
      type.includes('officedocument') ||
      type.includes('opendocument') ||
      type.includes('rtf') ||
      convertiblePattern.test(name)
    )
  })

  if (files.length === 0) {
    showNotification(
      'error',
      'Erro',
      convertEnabled.value
        ? `Seleccione PDF, imagem ou documento Word.`
        : `Seleccione ficheiros (${ui.value.documentPlural}).`
    )
    return
  }

  if (isGalleryAtLimit.value) {
    showNotification('error', 'Limite atingido', galleryLimitMessage.value)
    return
  }

  uploading.value = true

  try {
    for (const file of files) {
      uploadProgress.value = 0

      const data = await api.uploadDocument(props.userId, file, (progress) => {
        uploadProgress.value = progress
      })

      if (data.error) {
        throw new Error(data.error)
      }

      if (data.document) {
        const normalized = normalizeGalleryDocument(data.document)
        const existingIndex = documents.value.findIndex(
          (document) => document.filename === normalized.filename
        )

        if (existingIndex >= 0) {
          documents.value.splice(existingIndex, 1, normalized)
        } else {
          documents.value.push(normalized)
        }

        activeFilename.value = normalized.filename
        previewMode.value = 'single'
        revokeMergedUrl()
      }
    }

    showNotification(
      'success',
      'Sucesso',
      files.length === 1 ? 'Documento carregado com sucesso.' : `${files.length} documentos carregados.`
    )
  } catch (error) {
    showNotification(
      'error',
      'Erro',
      error?.response?.data?.error || error?.message || 'Falha no upload.'
    )
  } finally {
    uploading.value = false
    uploadProgress.value = 0
  }
}

const onFileInputChange = (event) => {
  uploadFiles(event.target.files)
  event.target.value = ''
}

const onUploadDrop = (event) => {
  if (!isFullMode.value) {
    return
  }

  dragOverUpload.value = false
  uploadFiles(event.dataTransfer?.files)
}

const onUploadDragOver = (event) => {
  if (!isFullMode.value) {
    return
  }

  event.preventDefault()
  dragOverUpload.value = true
}

const onUploadDragLeave = (event) => {
  if (!isFullMode.value) {
    return
  }

  event.preventDefault()
  dragOverUpload.value = false
}

const getQRCode = async () => {
  if (isGalleryAtLimit.value) {
    showNotification('error', 'Limite atingido', galleryLimitMessage.value)
    return
  }

  if (props.userId == null || props.userId === '') {
    showNotification('error', 'Erro', 'ID do utilizador em falta.')
    return
  }

  try {
    const data = await api.fetchQrCode(props.userId)
    qrCodeData.value = data?.qr_image || data?.svg || ''
    showQRCode.value = true
  } catch (error) {
    const msg =
      error?.response?.data?.error ||
      error?.response?.data?.message ||
      error?.message ||
      'Erro ao obter QR code.'

    showNotification('error', 'Erro', msg)
  }
}

const closeQrCode = () => {
  showQRCode.value = false
}

const handleDocumentsUploadedFromMobile = async (payload) => {
  const previous = new Set(documents.value.map((document) => document.filename))
  const newNames = new Set(payload?.new_filenames ?? [])

  if (Array.isArray(payload?.documents) && payload.documents.length > 0) {
    documents.value = normalizeGalleryDocuments(payload.documents)
  } else {
    await loadDocuments()
  }

  const newcomers = documents.value.filter((document) =>
    newNames.size > 0 ? newNames.has(document.filename) : !previous.has(document.filename)
  )

  if (newcomers.length === 0) {
    return
  }

  const newest = newcomers[newcomers.length - 1]
  openDocument(newest.filename)

  const label =
    newcomers.length === 1
      ? `Novo ${ui.value.documentSingular} do telemóvel — já pode pré-visualizar.`
      : `${formatDocumentCount(newcomers.length, ui.value)} do telemóvel — o mais recente foi seleccionado.`

  showNotification('success', 'Documentos recebidos', label)
}

usePdfGalleryRealtime(toRef(props, 'userId'), {
  onDocumentsUploaded: handleDocumentsUploadedFromMobile,
})

const deleteSelected = () => {
  if (!hasSelection.value) {
    return
  }

  requestDeleteConfirmation([...selectedFilenames.value])
}

const requestDeleteConfirmation = (filenames) => {
  const deletableFilenames = filterDeletableFilenames(filenames)

  if (deletableFilenames.length === 0) {
    return
  }

  const label = deletableFilenames.length === 1
    ? `este ${ui.value.documentSingular}`
    : `estes ${deletableFilenames.length} ${ui.value.documentPlural}`

  showNotification(
    'warning',
    'Confirmar eliminação',
    `Tem a certeza de que deseja eliminar ${label}?`,
    true,
    deletableFilenames,
    0,
    'delete-bulk',
    'Eliminar',
    'Cancelar'
  )
}

const deleteActiveDocument = () => {
  if (!activeFilename.value) {
    return
  }

  requestDeleteConfirmation([activeFilename.value])
}

const confirmDeleteSelected = async (filenames) => {
  loading.value = true

  try {
    const data = await api.deleteDocuments(props.userId, filenames)

    if (data.error) {
      throw new Error(data.error)
    }

    documents.value = documents.value.filter((document) => !filenames.includes(document.filename))
    selectedFilenames.value = new Set()
    syncSelectionAfterListChange()
    showNotification('success', 'Sucesso', `${ui.value.documentPlural} eliminado(s).`)
  } catch (error) {
    showNotification(
      'error',
      'Erro',
      error?.response?.data?.error || error?.message || 'Falha ao eliminar.'
    )
  } finally {
    loading.value = false
  }
}

const onNotificationConfirm = () => {
  const action = notification.value.action
  const context = notification.value.context

  notification.value.show = false

  if (action === 'delete-bulk' && Array.isArray(context) && context.length > 0) {
    confirmDeleteSelected(context)
  }
}

const onNotificationCancel = () => {
  dismissNotification()
}

const resetReorderDragState = () => {
  reorderDragIndex.value = null
  reorderDragGroupIndex.value = null
  reorderDragFilename.value = ''
  reorderInsertAt.value = null
  groupReorderInsertAt.value = null
  reorderDragScope.value = 'item'
  reorderPointerActive.value = false
  reorderDragPreview.value = {
    visible: false,
    x: 0,
    y: 0,
    thumbUrl: '',
    filename: '',
  }
}

const resolveReorderInsertAt = (clientY, listEl) => {
  const items = listEl?.querySelectorAll('[data-reorder-item]')

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

const isInReorderDragBlock = (filename, groupId = null) => {
  if (reorderDragScope.value === 'group' && groupId && reorderDragGroupIndex.value !== null) {
    const groups = documentGroups.value

    return groups?.[reorderDragGroupIndex.value]?.id === groupId
  }

  if (reorderDragFilename.value) {
    return reorderDragFilename.value === filename
  }

  if (reorderDragIndex.value === null) {
    return false
  }

  return documents.value[reorderDragIndex.value]?.filename === filename
}

const persistReorder = async (nextDocuments) => {
  reorderSaving.value = true

  try {
    const data = await api.reorderDocuments(
      props.userId,
      nextDocuments.map((document) => document.filename)
    )

    if (data?.error) {
      throw new Error(data.error)
    }
  } catch (error) {
    showNotification(
      'error',
      'Erro',
      error?.response?.data?.error || error?.message || 'Falha ao reordenar.'
    )
    await loadDocuments()
  } finally {
    reorderSaving.value = false
  }
}

const applyReorderMove = async (fromIndex, insertAt, groupId = null) => {
  if (fromIndex === null || insertAt === null) {
    return false
  }

  let targetInsertAt = insertAt

  if (groupId) {
    targetInsertAt = clampInsertAtToGroup(documents.value, groupId, insertAt)
  }

  if (targetInsertAt === fromIndex || targetInsertAt === fromIndex + 1) {
    return false
  }

  const updated = [...documents.value]
  const [item] = updated.splice(fromIndex, 1)
  let target = targetInsertAt

  if (fromIndex < targetInsertAt) {
    target -= 1
  }

  updated.splice(target, 0, item)
  documents.value = updated
  await persistReorder(updated)

  return true
}

const applyGroupReorderMove = async (fromGroupIndex, insertAtGroupIndex) => {
  const nextDocuments = reorderDocumentGroups(
    documents.value,
    fromGroupIndex,
    insertAtGroupIndex,
  )

  if (nextDocuments === documents.value) {
    return false
  }

  documents.value = nextDocuments
  await persistReorder(nextDocuments)

  return true
}

const startReorderPointerDrag = (index, event, groupId = null, groupIndex = null, listOverride = null) => {
  if (event.pointerType === 'mouse' && event.button !== 0) {
    return
  }

  const listEl = listOverride || galleryListRef.value
  const document = documents.value[index]

  if (!listEl || !document) {
    return
  }

  reorderDragScope.value = 'item'
  const useGroupList = Boolean(groupId && groupIndex !== null && listOverride && isGroupedLayout.value)

  const startX = event.clientX
  const startY = event.clientY
  let moved = false

  reorderDragIndex.value = index
  reorderDragFilename.value = document.filename
  reorderInsertAt.value = index
  reorderDragPreview.value = {
    visible: false,
    x: startX,
    y: startY,
    thumbUrl: document.thumb_url
      ? `${document.thumb_url}?v=${document.timestamp || 0}`
      : '',
    filename: document.filename,
  }

  const onMove = (moveEvent) => {
    if (
      !moved &&
      (Math.abs(moveEvent.clientY - startY) > 6 || Math.abs(moveEvent.clientX - startX) > 6)
    ) {
      moved = true
      reorderPointerActive.value = true
    }

    if (!moved) {
      return
    }

    moveEvent.preventDefault()

    reorderDragPreview.value = {
      ...reorderDragPreview.value,
      visible: true,
      x: moveEvent.clientX,
      y: moveEvent.clientY,
    }

    const nextInsertAt = resolveReorderInsertAt(moveEvent.clientY, listEl)

    if (useGroupList) {
      const relativeInsertAt = Math.max(
        0,
        Math.min(nextInsertAt, documentGroups.value[groupIndex].documents.length),
      )
      reorderInsertAt.value = flatIndexForGroupItem(documentGroups.value, groupIndex, 0) + relativeInsertAt
    } else {
      reorderInsertAt.value = nextInsertAt
    }
  }

  const onUp = async (upEvent) => {
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', onUp)
    window.removeEventListener('pointercancel', onUp)

    let insertAt = resolveReorderInsertAt(upEvent.clientY, listEl)
    const fromIndex = index
    const didMove = moved

    if (useGroupList) {
      const groups = documentGroups.value
      const relativeInsertAt = Math.max(
        0,
        Math.min(insertAt, groups[groupIndex].documents.length),
      )
      insertAt = flatIndexForGroupItem(groups, groupIndex, 0) + relativeInsertAt
    }

    reorderPointerActive.value = false
    resetReorderDragState()

    if (didMove) {
      await applyReorderMove(fromIndex, insertAt, groupId)
    }
  }

  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
  window.addEventListener('pointercancel', onUp)
  event.preventDefault()
}

const onReorderPointerDown = (index, event, groupId = null) => {
  if (documents.value.length < 2) {
    return
  }

  startReorderPointerDrag(index, event, groupId)
}

const onGroupItemReorderPointerDown = (groupIndex, itemIndex, event) => {
  const groups = documentGroups.value
  const group = groups?.[groupIndex]

  if (!group || group.documents.length < 2) {
    return
  }

  const flatIndex = flatIndexForGroupItem(groups, groupIndex, itemIndex)
  const listEl = event.currentTarget?.closest('ul[data-group-item-list]')
  startReorderPointerDrag(flatIndex, event, group.id, groupIndex, listEl)
}

const startGroupReorderPointerDrag = (groupIndex, event) => {
  if (event.pointerType === 'mouse' && event.button !== 0) {
    return
  }

  const groups = documentGroups.value
  const group = groups?.[groupIndex]
  const listEl = galleryGroupsListRef.value

  if (!listEl || !group || (groups?.length || 0) < 2) {
    return
  }

  const startX = event.clientX
  const startY = event.clientY
  let moved = false

  reorderDragScope.value = 'group'
  reorderDragGroupIndex.value = groupIndex
  groupReorderInsertAt.value = groupIndex
  reorderDragPreview.value = {
    visible: false,
    x: startX,
    y: startY,
    thumbUrl: group.documents[0]?.thumb_url
      ? `${group.documents[0].thumb_url}?v=${group.documents[0].timestamp || 0}`
      : '',
    filename: group.label,
  }

  const headerElements = () =>
    listEl.querySelectorAll('[data-group-reorder-item]')

  const onMove = (moveEvent) => {
    if (
      !moved &&
      (Math.abs(moveEvent.clientY - startY) > 6 || Math.abs(moveEvent.clientX - startX) > 6)
    ) {
      moved = true
      reorderPointerActive.value = true
    }

    if (!moved) {
      return
    }

    moveEvent.preventDefault()
    reorderDragPreview.value = {
      ...reorderDragPreview.value,
      visible: true,
      x: moveEvent.clientX,
      y: moveEvent.clientY,
    }
    groupReorderInsertAt.value = resolveGroupInsertAt(moveEvent.clientY, headerElements())
  }

  const onUp = async (upEvent) => {
    window.removeEventListener('pointermove', onMove)
    window.removeEventListener('pointerup', onUp)
    window.removeEventListener('pointercancel', onUp)

    const insertAt = resolveGroupInsertAt(upEvent.clientY, headerElements())
    const fromGroupIndex = groupIndex
    const didMove = moved

    reorderPointerActive.value = false
    resetReorderDragState()

    if (didMove) {
      await applyGroupReorderMove(fromGroupIndex, insertAt)
    }
  }

  window.addEventListener('pointermove', onMove)
  window.addEventListener('pointerup', onUp)
  window.addEventListener('pointercancel', onUp)
  event.preventDefault()
}

const onGroupReorderPointerDown = (groupIndex, event) => {
  startGroupReorderPointerDrag(groupIndex, event)
}

const parseMergeApiError = async (error, fallback) => {
  let message = error?.message || fallback

  if (error?.response?.data instanceof Blob) {
    try {
      const text = await error.response.data.text()
      const json = JSON.parse(text)
      message = json.error || message
    } catch {
      // ignore parse errors
    }
  } else if (error?.response?.data?.error) {
    message = error.response.data.error
  }

  return message
}

const sameFilenamesList = (left, right) =>
  left.length === right.length && left.every((filename, index) => filename === right[index])

const mergeDocumentsToBlob = async (filenames) => {
  const blob = await api.mergeDocuments(props.userId, filenames)

  if (!(blob instanceof Blob) || blob.size === 0) {
    throw new Error('O servidor não devolveu um PDF válido.')
  }

  return blob
}

const mergeSelected = async () => {
  if (!canMerge.value) {
    showNotification(
      'error',
      'Erro',
      `Seleccione entre 2 e ${mergeMaxFiles.value} ${ui.value.documentPlural} para juntar.`
    )
    return
  }

  merging.value = true

  try {
    const filenames = selectedFilenamesInGalleryOrder()
    const blob = await mergeDocumentsToBlob(filenames)

    revokeMergedUrl()
    mergedObjectUrl = URL.createObjectURL(blob)
    mergedPreviewUrl.value = mergedObjectUrl
    mergedSourceFilenames.value = filenames
    previewMode.value = 'merged'
    showNotification('success', 'Sucesso', `${ui.value.documentPlural} unidos com sucesso.`)
  } catch (error) {
    showNotification('error', 'Erro', await parseMergeApiError(error, `Falha ao juntar ${ui.value.documentPlural}.`))
  } finally {
    merging.value = false
  }
}

const saveMergedToGallery = async () => {
  const filenames = mergedSourceFilenames.value.length
    ? mergedSourceFilenames.value
    : selectedFilenamesInGalleryOrder()

  if (filenames.length < 2) {
    showNotification('error', 'Erro', `Junte pelo menos dois ${ui.value.documentPlural} antes de gravar.`)
    return
  }

  savingMerged.value = true

  try {
    const data = await api.saveMergedDocument(props.userId, filenames)

    if (data?.error) {
      throw new Error(data.error)
    }

    if (!data?.document) {
      throw new Error('O servidor não devolveu o PDF gravado.')
    }

    applySavedDocument(data.document)
    showNotification('success', 'Sucesso', `Documento unido gravado na ${ui.value.title.toLowerCase()}.`)
  } catch (error) {
    showNotification(
      'error',
      'Erro',
      error?.response?.data?.error || error?.message || 'Falha ao gravar o PDF unido.'
    )
  } finally {
    savingMerged.value = false
  }
}

const mergeAndSave = async () => {
  if (!canMerge.value) {
    showNotification(
      'error',
      'Erro',
      `Seleccione entre 2 e ${mergeMaxFiles.value} ${ui.value.documentPlural} para juntar.`
    )
    return
  }

  const filenames = selectedFilenamesInGalleryOrder()
  savingMerged.value = true

  try {
    const data = await api.saveMergedDocument(props.userId, filenames)

    if (data?.error) {
      throw new Error(data.error)
    }

    if (!data?.document) {
      throw new Error('O servidor não devolveu o PDF gravado.')
    }

    applySavedDocument(data.document)
    showNotification('success', 'Sucesso', `${formatDocumentCount(filenames.length, ui.value)} unidos e gravados na galeria.`)
  } catch (error) {
    showNotification(
      'error',
      'Erro',
      error?.response?.data?.error || error?.message || 'Falha ao juntar e gravar.'
    )
  } finally {
    savingMerged.value = false
  }
}

const extractPagesToGallery = async ({ from, to }) => {
  if (!canExtractPages.value || !activeFilename.value) {
    showNotification('error', 'Erro', `Seleccione um ${ui.value.documentSingular} para extrair páginas.`)
    return
  }

  extracting.value = true

  try {
    const data = await api.saveExtractedDocument(props.userId, activeFilename.value, {
      pageFrom: from,
      pageTo: to,
    })

    if (data?.error) {
      throw new Error(data.error)
    }

    if (!data?.document) {
      throw new Error('O servidor não devolveu o PDF extraído.')
    }

    applySavedDocument(data.document)

    const label = from === to ? `página ${from}` : `páginas ${from}–${to}`
    showNotification('success', 'Sucesso', `${label} extraída(s) e gravada(s) na galeria.`)
  } catch (error) {
    showNotification(
      'error',
      'Erro',
      error?.response?.data?.error || error?.message || 'Falha ao extrair páginas.'
    )
  } finally {
    extracting.value = false
  }
}

const resolvePrintUrl = async () => {
  if (isViewMode.value) {
    if (!activeFilename.value) {
      throw new Error(`Seleccione um ${ui.value.documentSingular} para descarregar.`)
    }

    const document = documents.value.find((item) => item.filename === activeFilename.value)

    if (document?.previewable && document?.url) {
      return { url: document.url, revoke: false }
    }

    const blob = await mergeDocumentsToBlob([activeFilename.value])

    return {
      url: URL.createObjectURL(blob),
      revoke: true,
    }
  }

  if (selectedCount.value >= 2) {
    if (!canMerge.value) {
      throw new Error(`Seleccione entre 2 e ${mergeMaxFiles.value} documentos para imprimir.`)
    }

    const filenames = selectedFilenamesInGalleryOrder()

    if (
      previewMode.value === 'merged' &&
      mergedPreviewUrl.value &&
      sameFilenamesList(mergedSourceFilenames.value, filenames)
    ) {
      return { url: mergedPreviewUrl.value, revoke: false }
    }

    const blob = await mergeDocumentsToBlob(filenames)

    return {
      url: URL.createObjectURL(blob),
      revoke: true,
    }
  }

  if (selectedCount.value === 1) {
    const filename = [...selectedFilenames.value][0]
    const document = documents.value.find((item) => item.filename === filename)

    if (document?.previewable && document?.url) {
      return { url: document.url, revoke: false }
    }

    if (document) {
      const blob = await mergeDocumentsToBlob([filename])

      return {
        url: URL.createObjectURL(blob),
        revoke: true,
      }
    }
  }

  if (previewUrl.value) {
    return { url: previewUrl.value, revoke: false }
  }

  if (activeDocument.value && !activeDocument.value.previewable) {
    const blob = await mergeDocumentsToBlob([activeDocument.value.filename])

    return {
      url: URL.createObjectURL(blob),
      revoke: true,
    }
  }

  throw new Error('Seleccione um ou mais documentos para imprimir.')
}

const printFromUrl = (url) =>
  new Promise((resolve) => {
    const iframe = document.createElement('iframe')
    iframe.style.position = 'fixed'
    iframe.style.right = '0'
    iframe.style.bottom = '0'
    iframe.style.width = '0'
    iframe.style.height = '0'
    iframe.style.border = '0'
    iframe.src = url

    const cleanup = () => {
      iframe.remove()
      resolve()
    }

    iframe.onload = () => {
      try {
        iframe.contentWindow?.focus()
        iframe.contentWindow?.print()
      } finally {
        setTimeout(cleanup, 1000)
      }
    }

    document.body.appendChild(iframe)
  })

const printPreview = async () => {
  if (printing.value) {
    return
  }

  printing.value = true

  try {
    const { url, revoke } = await resolvePrintUrl()
    await printFromUrl(url)

    if (revoke) {
      URL.revokeObjectURL(url)
    }
  } catch (error) {
    showNotification(
      'error',
      'Erro',
      await parseMergeApiError(error, 'Falha ao preparar a impressão.')
    )
  } finally {
    printing.value = false
  }
}

const documentFileUrl = (document) => {
  if (!document?.filename) {
    return null
  }

  const url =
    document.file_url ||
    (document.kind === 'pdf' ? document.url : null) ||
    `/api/pdf-gallery/files/${encodeURIComponent(String(props.userId))}/${encodeURIComponent(document.filename)}`

  const version = document.timestamp || 0
  const separator = url.includes('?') ? '&' : '?'

  return `${url}${separator}v=${version}`
}

const resolveDownloadUrl = async () => {
  if (
    !isViewMode.value &&
    previewMode.value === 'merged' &&
    selectedCount.value >= 2 &&
    canMerge.value
  ) {
    const blob = await mergeDocumentsToBlob(selectedFilenamesInGalleryOrder())

    return {
      url: URL.createObjectURL(blob),
      revoke: true,
    }
  }

  if (!isViewMode.value && selectedCount.value >= 2 && canMerge.value) {
    const blob = await mergeDocumentsToBlob(selectedFilenamesInGalleryOrder())

    return {
      url: URL.createObjectURL(blob),
      revoke: true,
    }
  }

  const document = activeDocument.value

  if (!document?.filename) {
    throw new Error(`Seleccione um ${ui.value.documentSingular} para descarregar.`)
  }

  const url = documentFileUrl(document)

  if (!url) {
    throw new Error('Não foi possível obter o ficheiro original.')
  }

  return { url, revoke: false }
}

const resolveDocumentDownloadName = (document, { merged = false } = {}) => {
  if (merged) {
    return 'documentos-unidos.pdf'
  }

  const base = document?.label || document?.filename

  return base || 'documento'
}

const downloadPreview = async () => {
  try {
    const merged =
      !isViewMode.value &&
      ((previewMode.value === 'merged' && selectedCount.value >= 2) || selectedCount.value >= 2)
    const { url, revoke } = await resolveDownloadUrl()
    const link = document.createElement('a')
    link.href = url
    link.download = resolveDocumentDownloadName(activeDocument.value, { merged })
    link.click()

    if (revoke) {
      URL.revokeObjectURL(url)
    }
  } catch (error) {
    showNotification(
      'error',
      'Erro',
      await parseMergeApiError(error, 'Falha ao descarregar o documento.')
    )
  }
}

onMounted(() => {
  loadDocuments()
})

const resolvePrimaryActionTarget = () => {
  if (previewMode.value === 'merged') {
    return {
      error:
        'Tem uma pré-visualização unida por gravar. Use «Gravar na galeria» antes de enviar, ou clique no documento que pretende usar.',
    }
  }

  if (previewMode.value === 'single' && activeFilename.value) {
    return { filename: activeFilename.value }
  }

  if (selectedFilenames.value.size === 1) {
    return { filename: [...selectedFilenames.value][0] }
  }

  if (selectedFilenames.value.size > 1) {
    return {
      error:
        'Seleccione um único documento para enviar: clique no ficheiro na lista (o documento activo na pré-visualização).',
    }
  }

  return {
    error:
      'Seleccione o documento a enviar: clique no ficheiro na lista (pode ser o auto, um anexo ou um PDF já unido).',
  }
}

const resolvePrimaryActionSelection = () => {
  const filenames = selectedFilenamesInGalleryOrder()

  if (filenames.length === 0) {
    return {
      error: 'Seleccione pelo menos um documento para continuar.',
    }
  }

  return { filenames }
}

defineExpose({
  resolvePrimaryActionTarget,
  resolvePrimaryActionSelection,
})

onBeforeUnmount(() => {
  resetReorderDragState()
  revokeMergedUrl()
})
</script>

<template>
  <div
    v-editor-tooltip-root
    class="pdf-gallery relative flex overflow-hidden bg-gray-100"
    :class="[
      asModal || compact ? 'h-full min-h-0' : 'min-h-[70vh]',
    ]"
  >
    <EditorTooltipLayer />

    <div class="pointer-events-none fixed inset-0 z-50 flex items-end justify-center px-4 py-6 sm:p-6">
      <div class="w-full max-w-sm">
        <Notification
          :show="notification.show"
          :type="notification.type"
          :title="notification.title"
          :message="notification.message"
          :show-actions="notification.showActions"
          :duration="notification.duration"
          :confirm-label="notification.confirmLabel"
          :cancel-label="notification.cancelLabel"
          @confirm="onNotificationConfirm"
          @cancel="onNotificationCancel"
        />
      </div>
    </div>

    <aside class="flex min-h-0 w-64 shrink-0 flex-col border-r border-gray-200 bg-white sm:w-72">
      <div class="flex flex-wrap justify-center gap-1.5 border-b border-gray-100 bg-gray-50/80 px-2 py-1.5">
        <template v-if="isFullMode">
        <label
          :title="uploadTitle"
          class="toolbar-icon-btn cursor-pointer"
          :class="{ 'pointer-events-none opacity-50': uploading || isGalleryAtLimit }"
          @click.prevent="!uploading && !isGalleryAtLimit && fileInputRef?.click()"
        >
          <svg class="toolbar-icon-svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"
            />
          </svg>
        </label>
        <input
          ref="fileInputRef"
          type="file"
          :accept="uploadAccept"
          multiple
          class="hidden"
          :disabled="uploading || isGalleryAtLimit"
          @change="onFileInputChange"
        />

        <button
          v-if="qrCodeEnabled"
          type="button"
          title="QR Code"
          class="toolbar-icon-btn"
          :class="{ 'pointer-events-none opacity-50': isGalleryAtLimit }"
          :disabled="isGalleryAtLimit"
          @click="getQRCode"
        >
          <svg class="toolbar-icon-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="3" width="7" height="7" rx="1" />
            <rect x="14" y="3" width="7" height="7" rx="1" />
            <rect x="3" y="14" width="7" height="7" rx="1" />
            <rect x="14" y="14" width="3" height="3" fill="currentColor" stroke="none" />
            <rect x="18" y="14" width="3" height="3" fill="currentColor" stroke="none" />
            <rect x="14" y="18" width="3" height="3" fill="currentColor" stroke="none" />
            <rect x="18" y="18" width="3" height="3" fill="currentColor" stroke="none" />
          </svg>
        </button>
        </template>

        <button
          type="button"
          title="Actualizar galeria"
          class="toolbar-icon-btn disabled:opacity-50"
          :disabled="loading"
          @click="loadDocuments"
        >
          <svg class="toolbar-icon-svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
            />
          </svg>
        </button>

        <template v-if="isFullMode">
        <button
          type="button"
          :title="`Juntar ${ui.documentPlural} seleccionados`"
          class="toolbar-icon-btn disabled:opacity-50"
          :disabled="!canMerge || merging || savingMerged"
          @click="mergeSelected"
        >
          <svg class="toolbar-icon-svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M8 7h12m0 0l-4-4m4 4l-4 4M8 17H4m0 0l4 4m-4-4l4-4"
            />
          </svg>
        </button>

        <button
          type="button"
          title="Juntar e gravar na galeria"
          class="toolbar-icon-btn disabled:opacity-50"
          :disabled="!canMerge || merging || savingMerged"
          @click="mergeAndSave"
        >
          <svg class="toolbar-icon-svg" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"
            />
          </svg>
        </button>
        </template>
      </div>

      <div class="shrink-0 border-b border-gray-100 px-3 py-2">
        <div class="flex items-center justify-between gap-2">
          <p class="text-xs font-medium text-gray-500">
            {{ ui.listLabel }}
            <span v-if="!loading">({{ documents.length }}<template v-if="maxFiles"> / {{ maxFiles }}</template>)</span>
          </p>
        </div>

        <div v-if="isFullMode && isReordering" class="mt-2 space-y-1">
          <p class="text-[10px] leading-snug text-violet-700">
            <template v-if="isGroupedLayout">
              Arraste os autos pelos pontos do cabeçalho; reordene anexos dentro de cada auto.
            </template>
            <template v-else>
              Arraste pelos pontos à esquerda; a barra azul indica a posição.
            </template>
            <span v-if="reorderSaving" class="text-violet-500"> A guardar…</span>
          </p>
        </div>

        <div v-else-if="hasSelection" class="mt-2 space-y-1.5">
          <div class="flex flex-wrap items-center gap-1.5">
            <span class="text-[10px] font-medium text-gray-600">
              {{ selectedCount }} selecionado{{ selectedCount === 1 ? '' : 's' }}
            </span>
            <button
              type="button"
              aria-label="Seleccionar todos"
              class="pdf-gallery-action-btn"
              @click="toggleSelectAll"
            >
              Todas
            </button>
            <button
              type="button"
              aria-label="Limpar selecção"
              class="pdf-gallery-action-btn"
              @click="clearSelection"
            >
              Limpar
            </button>
            <button
              v-if="selectedDeletableCount > 0"
              type="button"
              aria-label="Eliminar seleccionados"
              class="pdf-gallery-action-btn pdf-gallery-action-btn--danger"
              @click="deleteSelected"
            >
              Eliminar
            </button>
          </div>
        </div>

        <p
          v-else-if="documents.length > 1"
          class="mt-2 text-[10px] leading-snug text-gray-500"
        >
          <template v-if="isFullMode && isGroupedLayout">
            Reordene autos e anexos pelos pontos · clique na miniatura para pré-visualizar
          </template>
          <template v-else-if="isFullMode">
            Pontos à esquerda para reordenar · ícone de eliminar em cada ficheiro · clique na miniatura para pré-visualizar
          </template>
          <template v-else>
            Seleccione para eliminar em bulk · clique na miniatura para pré-visualizar
          </template>
        </p>
      </div>

      <div
        class="min-h-0 flex-1 overflow-y-auto p-2"
        :class="isFullMode && dragOverUpload ? 'bg-blue-50/60' : ''"
        @dragenter.prevent="onUploadDragOver"
        @dragover.prevent="onUploadDragOver"
        @dragleave.prevent="onUploadDragLeave"
        @drop.prevent="onUploadDrop"
      >
        <div v-if="loading && documents.length === 0" class="flex justify-center py-8">
          <div class="h-8 w-8 animate-spin rounded-full border-b-2 border-blue-600" />
        </div>
        <p
          v-else-if="documents.length === 0"
          class="px-2 py-6 text-center text-sm text-gray-500"
        >
          Nenhum {{ ui.documentSingular }}.
          <template v-if="isFullMode"> Carregue um ficheiro ou arraste para esta área.</template>
        </p>
        <ul
          v-else-if="isGroupedLayout"
          ref="galleryGroupsListRef"
          class="space-y-3"
        >
          <template v-for="(group, groupIndex) in documentGroups" :key="group.id">
            <li
              v-if="groupReorderInsertAt === groupIndex"
              class="pointer-events-none flex items-center px-1 py-px"
              aria-hidden="true"
            >
              <span class="h-0.5 flex-1 rounded-full bg-blue-500 shadow-sm" />
            </li>
            <li
              data-group-reorder-item
              class="rounded-lg border border-gray-200 bg-gray-50/80 transition"
              :class="{ 'opacity-45': isInReorderDragBlock('', group.id) }"
            >
              <div class="flex items-center gap-2 px-2 py-1.5">
                <button
                  v-if="isFullMode && (documentGroups?.length || 0) > 1"
                  type="button"
                  class="pdf-gallery-item__btn pdf-gallery-item__btn--reorder shrink-0"
                  aria-label="Arrastar auto para reordenar"
                  @click.stop
                  @pointerdown.stop="onGroupReorderPointerDown(groupIndex, $event)"
                >
                  <svg class="h-4 w-2.5" viewBox="0 0 8 14" fill="currentColor" aria-hidden="true">
                    <circle cx="2" cy="2" r="1.25" />
                    <circle cx="6" cy="2" r="1.25" />
                    <circle cx="2" cy="7" r="1.25" />
                    <circle cx="6" cy="7" r="1.25" />
                    <circle cx="2" cy="12" r="1.25" />
                    <circle cx="6" cy="12" r="1.25" />
                  </svg>
                </button>
                <p class="min-w-0 flex-1 truncate text-xs font-semibold text-gray-700">
                  {{ group.label }}
                </p>
                <span class="shrink-0 rounded-full bg-white px-2 py-0.5 text-[10px] font-bold text-gray-500">
                  {{ group.documents.length }}
                </span>
              </div>
              <ul
                data-group-item-list
                class="space-y-1.5 px-2 pb-2"
              >
                <template v-for="(document, itemIndex) in group.documents" :key="document.filename">
                  <li
                    v-if="reorderInsertAt === flatIndexForGroupItem(documentGroups, groupIndex, itemIndex)"
                    class="pointer-events-none flex items-center px-1 py-px"
                    aria-hidden="true"
                  >
                    <span class="h-0.5 flex-1 rounded-full bg-blue-500 shadow-sm" />
                  </li>
                  <li
                    data-reorder-item
                    class="rounded-lg transition"
                    :class="{ 'opacity-45': isInReorderDragBlock(document.filename) }"
                  >
                    <PdfGalleryItem
                      :url="document.url || ''"
                      :thumb-url="document.thumb_url ? `${document.thumb_url}?v=${document.timestamp || 0}` : ''"
                      :filename="document.filename"
                      :label="document.label || ''"
                      :kind="document.kind || 'pdf'"
                      :selected="selectedFilenames.has(document.filename)"
                      :active="activeFilename === document.filename && previewMode === 'single'"
                      :order-index="flatIndexForGroupItem(documentGroups, groupIndex, itemIndex)"
                      :page-count="document.page_count"
                      :size-bytes="document.size_bytes"
                      :is-drag-source="isInReorderDragBlock(document.filename)"
                      :can-reorder="isFullMode && group.documents.length > 1"
                      :show-select="documents.length > 0"
                      :show-remove="canRemoveDocument(document)"
                      :show-order-badge="isFullMode"
                      @toggle-select="toggleSelect(document.filename)"
                      @open="openDocument(document.filename)"
                      @remove="requestDeleteConfirmation([document.filename])"
                      @reorder-pointer-down="onGroupItemReorderPointerDown(groupIndex, itemIndex, $event)"
                    />
                  </li>
                </template>
                <li
                  v-if="reorderInsertAt === flatIndexForGroupItem(documentGroups, groupIndex, group.documents.length)"
                  class="pointer-events-none flex items-center px-1 py-px"
                  aria-hidden="true"
                >
                  <span class="h-0.5 flex-1 rounded-full bg-blue-500 shadow-sm" />
                </li>
              </ul>
            </li>
          </template>
          <li
            v-if="groupReorderInsertAt === (documentGroups?.length || 0)"
            class="pointer-events-none flex items-center px-1 py-px"
            aria-hidden="true"
          >
            <span class="h-0.5 flex-1 rounded-full bg-blue-500 shadow-sm" />
          </li>
        </ul>
        <ul
          v-else
          ref="galleryListRef"
          class="space-y-1.5"
        >
          <template v-for="(document, index) in documents" :key="document.filename">
            <li
              v-if="reorderInsertAt === index"
              class="pointer-events-none flex items-center px-1 py-px"
              aria-hidden="true"
            >
              <span class="h-0.5 flex-1 rounded-full bg-blue-500 shadow-sm" />
            </li>
            <li
              data-reorder-item
              class="rounded-lg transition"
              :class="{ 'opacity-45': isInReorderDragBlock(document.filename) }"
            >
              <PdfGalleryItem
                :url="document.url || ''"
                :thumb-url="document.thumb_url ? `${document.thumb_url}?v=${document.timestamp || 0}` : ''"
                :filename="document.filename"
                :label="document.label || ''"
                                :kind="document.kind || 'pdf'"
                :selected="selectedFilenames.has(document.filename)"
                :active="activeFilename === document.filename && previewMode === 'single'"
                :order-index="index"
                :page-count="document.page_count"
                :size-bytes="document.size_bytes"
                :is-drag-source="isInReorderDragBlock(document.filename)"
                :can-reorder="isFullMode && documents.length > 1"
                :show-select="documents.length > 0"
                :show-remove="canRemoveDocument(document)"
                :show-order-badge="isFullMode"
                @toggle-select="toggleSelect(document.filename)"
                @open="openDocument(document.filename)"
                @remove="requestDeleteConfirmation([document.filename])"
                @reorder-pointer-down="onReorderPointerDown(index, $event)"
              />
            </li>
          </template>
          <li
            v-if="reorderInsertAt === documents.length"
            class="pointer-events-none flex items-center px-1 py-px"
            aria-hidden="true"
          >
            <span class="h-0.5 flex-1 rounded-full bg-blue-500 shadow-sm" />
          </li>
        </ul>
      </div>
    </aside>

    <main class="relative flex min-h-0 min-w-0 flex-1 flex-col bg-gray-900">
      <PdfPreviewPanel
        class="h-full min-h-0"
        :url="previewUrl"
        :title="previewTitle"
        :show-save-merged="canSaveMerged"
        :show-extract-pages="canExtractPages"
        :show-print="isFullMode"
        :show-delete="canDeleteActiveDocument"
        :extracting="extracting"
        :printing="printing"
        :show-toolbar="canShowPreviewToolbar"
        :empty-message="previewEmptyMessage"
        @print="printPreview"
        @download="downloadPreview"
        @delete="deleteActiveDocument"
        @save-merged="saveMergedToGallery"
        @extract-pages="extractPagesToGallery"
      />
    </main>

    <QRCodePopup
      v-if="isFullMode && showQRCode"
      :show="showQRCode"
      :qr-code="qrCodeData"
      :max-files="qrMaxFiles"
      :max-upload-mb="maxUploadMb"
      :document-singular="ui.documentSingular"
      :document-plural="ui.documentPlural"
      @close="closeQrCode"
    />

    <div
      v-if="reorderDragPreview.visible"
      class="pdf-gallery-reorder-ghost pointer-events-none fixed z-[200] flex w-44 items-center gap-2 overflow-hidden rounded-lg border border-blue-400 bg-white px-1.5 py-1 shadow-lg"
      :style="{
        left: `${reorderDragPreview.x}px`,
        top: `${reorderDragPreview.y}px`,
      }"
    >
      <img
        v-if="reorderDragPreview.thumbUrl"
        :src="reorderDragPreview.thumbUrl"
        :alt="reorderDragPreview.filename"
        class="h-9 w-7 shrink-0 rounded border border-gray-200 object-cover object-top bg-gray-50"
        draggable="false"
      />
      <p class="min-w-0 flex-1 truncate text-[10px] text-gray-700">
        {{ reorderDragPreview.filename }}
      </p>
    </div>
  </div>
</template>

<style scoped>
.pdf-gallery-action-btn {
  all: unset;
  box-sizing: border-box;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 0.25rem;
  padding: 0.125rem 0.375rem;
  font-size: 10px;
  font-weight: 500;
  color: #4b5563;
  -webkit-appearance: none;
  appearance: none;
  background: transparent;
  border: none;
  box-shadow: none;
}

.pdf-gallery-action-btn:hover {
  background: #f3f4f6;
}

.pdf-gallery-action-btn--danger {
  margin-left: auto;
  border-radius: 0.375rem;
  background: #dc2626;
  padding: 0.125rem 0.5rem;
  color: #fff;
}

.pdf-gallery-action-btn--danger:hover {
  background: #b91c1c;
}

.pdf-gallery-action-btn--danger:disabled {
  cursor: not-allowed;
  opacity: 0.4;
}

.toolbar-icon-btn {
  @apply inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-black/50 text-white transition hover:bg-black/75 focus:outline-none focus:ring-2 focus:ring-white focus:ring-offset-1 focus:ring-offset-gray-100 disabled:pointer-events-none;
}

.toolbar-icon-svg {
  @apply h-[18px] w-[18px];
}

.pdf-gallery-reorder-ghost {
  transform: translate(8px, 8px);
  opacity: 0.95;
  will-change: transform, left, top;
}
</style>
