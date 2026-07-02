import axios from 'axios'

const CSRF_COOKIE_ROUTE = '/sanctum/csrf-cookie'
const CSRF_TOKEN_ROUTE = '/api/pdf-gallery/csrf-token'

const bareClient = axios.create({
  withCredentials: true,
  headers: {
    'X-Requested-With': 'XMLHttpRequest',
  },
})

const readMetaCsrfToken = () =>
  document.head.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''

const writeMetaCsrfToken = (token) => {
  if (!token || typeof document === 'undefined') {
    return
  }

  const meta = document.head.querySelector('meta[name="csrf-token"]')

  if (meta) {
    meta.setAttribute('content', token)
  }
}

const applyCsrfDefaults = () => {
  const token = readMetaCsrfToken()

  if (token) {
    axios.defaults.headers.common['X-CSRF-TOKEN'] = token
  }
}

const setRequestCsrfHeader = (config, token) => {
  if (!token) {
    return
  }

  if (config.headers && typeof config.headers.set === 'function') {
    config.headers.set('X-CSRF-TOKEN', token)
  } else {
    config.headers = {
      ...(config.headers || {}),
      'X-CSRF-TOKEN': token,
    }
  }
}

let refreshPromise = null

export const refreshCsrfToken = async () => {
  if (refreshPromise) {
    return refreshPromise
  }

  refreshPromise = (async () => {
    await bareClient.get(CSRF_COOKIE_ROUTE)

    const { data } = await bareClient.get(CSRF_TOKEN_ROUTE)
    const token = data?.token

    if (!token) {
      throw new Error('Não foi possível renovar o token CSRF.')
    }

    writeMetaCsrfToken(token)
    applyCsrfDefaults()

    return token
  })().finally(() => {
    refreshPromise = null
  })

  return refreshPromise
}

axios.defaults.withCredentials = true
axios.defaults.withXSRFToken = true
axios.defaults.xsrfCookieName = 'XSRF-TOKEN'
axios.defaults.xsrfHeaderName = 'X-XSRF-TOKEN'
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'
applyCsrfDefaults()

axios.interceptors.request.use((config) => {
  const token = readMetaCsrfToken()

  if (token) {
    setRequestCsrfHeader(config, token)
  }

  return config
})

axios.interceptors.response.use(
  (response) => response,
  async (error) => {
    const config = error?.config

    if (!config || config._csrfRetried || error?.response?.status !== 419) {
      return Promise.reject(error)
    }

    if (config.url?.includes(CSRF_COOKIE_ROUTE) || config.url?.includes(CSRF_TOKEN_ROUTE)) {
      return Promise.reject(error)
    }

    config._csrfRetried = true

    try {
      const token = await refreshCsrfToken()
      setRequestCsrfHeader(config, token)

      return axios(config)
    } catch (refreshError) {
      return Promise.reject(refreshError)
    }
  }
)

if (typeof document !== 'undefined') {
  document.addEventListener('inertia:success', applyCsrfDefaults)
}

export default axios
