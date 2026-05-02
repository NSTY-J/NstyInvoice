import axios from 'axios'

export const api = axios.create({
  baseURL: '/api',
  withCredentials: true,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
  },
})

// CSRF token interceptor — token žije v Pinia auth store
let csrfToken: string | null = null
export function setCsrfToken(token: string | null) {
  csrfToken = token
}

api.interceptors.request.use((config) => {
  if (csrfToken && config.method && config.method.toUpperCase() !== 'GET') {
    config.headers.set('X-CSRF-Token', csrfToken)
  }
  // Pošli aktuální UI locale, aby backend hlášky chodily ve správném jazyce.
  // Auth middleware ji přepíše user.locale (pokud přihlášen).
  const locale = localStorage.getItem('locale') || 'cs'
  config.headers.set('Accept-Language', locale)

  // Multi-supplier — aktuální supplier z localStorage (Pinia persist).
  // Server fallbackuje na MIN(supplier.id) když chybí/neplatný.
  const sid = localStorage.getItem('myinvoice.current_supplier_id')
  if (sid && /^\d+$/.test(sid)) {
    config.headers.set('X-Supplier-Id', sid)
  }
  return config
})

// 401 → redirect na /login (kromě situace kdy už jsme na /login nebo /setup)
api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      const path = window.location.pathname
      if (!path.startsWith('/login') && !path.startsWith('/setup')) {
        window.location.href = '/login'
      }
    }
    if (error.response?.status === 423 && error.response?.data?.error?.code === 'setup_required') {
      window.location.href = '/setup'
    }
    return Promise.reject(error)
  },
)

export interface HealthResponse {
  status: 'ok'
  version: string
  env: string
  db: boolean
  redis: boolean
  time: string
}

export const systemApi = {
  health: () => api.get<HealthResponse>('/health').then((r) => r.data),
}
