import axios from '../http/client'

export function usePdfGalleryApi() {
  const listDocuments = async (userId) => {
    const { data } = await axios.get('/api/pdf-gallery/documents', {
      params: { user_id: userId },
    })

    return data
  }

  const uploadDocument = async (userId, file, onProgress) => {
    const formData = new FormData()
    formData.append('user_id', String(userId))
    formData.append('file', file)

    const { data } = await axios.post('/api/pdf-gallery/upload', formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (event) => {
        if (!onProgress || !event.total) {
          return
        }

        onProgress(Math.round((event.loaded / event.total) * 100))
      },
    })

    return data
  }

  const deleteDocuments = async (userId, filenames) => {
    const { data } = await axios.delete('/api/pdf-gallery/documents', {
      data: {
        user_id: userId,
        filenames,
      },
    })

    return data
  }

  const reorderDocuments = async (userId, filenames) => {
    const { data } = await axios.post('/api/pdf-gallery/reorder', {
      user_id: userId,
      filenames,
    })

    return data
  }

  const mergeDocuments = async (userId, filenames, { download = false } = {}) => {
    const response = await axios.post(
      '/api/pdf-gallery/merge',
      {
        user_id: userId,
        filenames,
        download,
      },
      { responseType: 'blob' }
    )

    return response.data
  }

  const saveMergedDocument = async (userId, filenames) => {
    const { data } = await axios.post('/api/pdf-gallery/merge/save', {
      user_id: userId,
      filenames,
    })

    return data
  }

  const extractDocument = async (userId, filename, { pageFrom, pageTo, pages, download = false } = {}) => {
    const payload = {
      user_id: userId,
      filename,
      download,
    }

    if (Array.isArray(pages) && pages.length > 0) {
      payload.pages = pages
    } else {
      payload.page_from = pageFrom
      payload.page_to = pageTo
    }

    const response = await axios.post('/api/pdf-gallery/extract', payload, {
      responseType: 'blob',
    })

    return response.data
  }

  const saveExtractedDocument = async (userId, filename, { pageFrom, pageTo, pages } = {}) => {
    const payload = {
      user_id: userId,
      filename,
    }

    if (Array.isArray(pages) && pages.length > 0) {
      payload.pages = pages
    } else {
      payload.page_from = pageFrom
      payload.page_to = pageTo
    }

    const { data } = await axios.post('/api/pdf-gallery/extract/save', payload)

    return data
  }

  const fetchQrCode = async (userId) => {
    const { data } = await axios.post('/api/pdf-gallery/qrcode', {
      user_id: userId,
    })

    return data
  }

  return {
    listDocuments,
    uploadDocument,
    deleteDocuments,
    reorderDocuments,
    mergeDocuments,
    saveMergedDocument,
    extractDocument,
    saveExtractedDocument,
    fetchQrCode,
  }
}
