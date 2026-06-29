import axios from 'axios'

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
 * the CSRF cookie. (Used later by the auth feature.)
 */
export function initCsrf() {
  return axios.get('/sanctum/csrf-cookie', { withCredentials: true })
}
