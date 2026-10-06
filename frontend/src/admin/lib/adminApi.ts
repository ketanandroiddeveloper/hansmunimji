import { api, ApiError, registerHeaderProvider, request } from '../../lib/api'
import type { Envelope, RequestOptions } from '../../lib/api'

export interface AdminUser {
  id: number
  email: string
  name: string
  two_factor_enabled: boolean
  two_factor_required: boolean
  last_login_at: string | null
  roles: string[]
  permissions: string[]
}

export type Paginated<T> = { items: T[]; meta: { page: number; per_page: number; total: number; total_pages: number } & Record<string, unknown> }

let csrfToken: string | null = null
let unregister: (() => void) | null = null

export function setCsrfToken(token: string | null): void {
  csrfToken = token
  if (!unregister) {
    unregister = registerHeaderProvider((method): Record<string, string> => (method !== 'GET' && csrfToken ? { 'X-CSRF-Token': csrfToken } : {}))
  }
}

export async function fetchMe(): Promise<AdminUser | null> {
  try {
    const me = await api.get<AdminUser & { csrf_token: string }>('/auth/me')
    const { csrf_token, ...user } = me
    setCsrfToken(csrf_token)
    return user
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) {
      setCsrfToken(null)
      return null
    }
    throw e
  }
}

/** The server rotates CSRF tokens per session; a stale tab refreshes once and retries. */
async function withCsrfRetry<T>(run: () => Promise<T>): Promise<T> {
  try {
    return await run()
  } catch (e) {
    if (e instanceof ApiError && e.code === 'csrf_mismatch') {
      await fetchMe()
      return run()
    }
    throw e
  }
}

type Opts = Omit<RequestOptions, 'method' | 'body'>

export const adminApi = {
  get: <T>(path: string, options: Opts = {}) => api.get<T>(path, options),
  list: async <T>(path: string, options: Opts = {}): Promise<Paginated<T>> => {
    const env: Envelope<T[]> = await request<T[]>(path, { ...options, method: 'GET' })
    return { items: env.data, meta: env.meta as Paginated<T>['meta'] }
  },
  post: <T>(path: string, body?: unknown, options: Opts = {}) => withCsrfRetry(() => api.post<T>(path, body, options)),
  put: <T>(path: string, body?: unknown, options: Opts = {}) => withCsrfRetry(() => api.put<T>(path, body, options)),
  patch: <T>(path: string, body?: unknown, options: Opts = {}) => withCsrfRetry(() => api.patch<T>(path, body, options)),
  delete: <T>(path: string, options: Opts = {}) => withCsrfRetry(() => api.delete<T>(path, options)),
}

export function errorMessage(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.fields)[0]?.[0]
    return first && error.code === 'validation_failed' ? first : error.message
  }
  return 'Something went wrong. Please try again.'
}
