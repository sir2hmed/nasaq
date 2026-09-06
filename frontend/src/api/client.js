import axios from 'axios'

export const apiBaseUrl = (import.meta.env.VITE_API_BASE_URL ?? 'http://localhost:8000/api')
  .replace(/\/$/, '')
export const applicationBaseUrl = apiBaseUrl.replace(/\/api$/, '')

const sharedOptions = {
  headers: {
    Accept: 'application/json',
  },
  timeout: 15000,
  withCredentials: true,
  withXSRFToken: true,
}

export const apiClient = axios.create({
  ...sharedOptions,
  baseURL: apiBaseUrl,
})

const sessionClient = axios.create({
  ...sharedOptions,
  baseURL: applicationBaseUrl,
})

apiClient.interceptors.response.use(
  (response) => response,
  async (error) => {
    const config = error.config
    const isSafeGet = config?.method?.toLowerCase() === 'get'
    const isRetryable = !error.response || error.response.status >= 500

    if (isSafeGet && isRetryable && !config.__nasaqRetried) {
      config.__nasaqRetried = true
      return apiClient(config)
    }

    if (error.response?.status === 401 && typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('nasaq:unauthorized'))
    }

    return Promise.reject(error)
  },
)

export async function initializeCsrf() {
  await sessionClient.get('/sanctum/csrf-cookie')
}

export function fieldErrors(error) {
  return error.response?.data?.errors ?? {}
}
