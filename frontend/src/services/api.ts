import axios, { type AxiosRequestConfig } from 'axios'
import { queryClient } from '@/services/queryClient'

/**
 * Single-origin Axios client. The app is served behind Nginx on :8080,
 * which proxies "/api" to Laravel — so a relative baseURL means no CORS.
 *
 * withCredentials + withXSRFToken make Sanctum's cookie-based SPA auth
 * work: the XSRF-TOKEN cookie is echoed back as the X-XSRF-TOKEN header.
 */
export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  withXSRFToken: true,
  headers: {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
  },
})

/**
 * Call once before the first authenticating request so Sanctum can set
 * the CSRF cookie.
 */
export function initCsrf() {
  return axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}

type RetriableConfig = AxiosRequestConfig & { _csrfRetried?: boolean }

/**
 * Cross-cutting response handling (SDD §21):
 *  - 419 (CSRF token mismatch) → refresh the cookie and retry once.
 *  - 401 (session lost) → clear the cached principal so route guards redirect;
 *    skipped for the `/user` probe itself to avoid a refetch loop.
 * 403 / 422 pass through for components (Forbidden page, field errors).
 */
api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const status = error?.response?.status
    const config = error?.config as RetriableConfig | undefined

    if (status === 419 && config && !config._csrfRetried) {
      config._csrfRetried = true
      await initCsrf()
      return api(config)
    }

    if (status === 401 && !(config?.url ?? '').endsWith('/user')) {
      queryClient.setQueryData(['auth', 'user'], null)
    }

    return Promise.reject(error)
  },
)
