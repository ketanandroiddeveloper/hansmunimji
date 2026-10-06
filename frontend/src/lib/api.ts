export const API_BASE = (import.meta.env.VITE_API_BASE as string | undefined)?.replace(/\/$/, '') ?? '/api/v1'

export type FieldErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly status: number
  readonly code: string
  readonly fields: FieldErrors
  readonly requestId: string | null

  constructor(status: number, code: string, message: string, fields: FieldErrors = {}, requestId: string | null = null) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.code = code
    this.fields = fields
    this.requestId = requestId
  }

  get isNetwork(): boolean {
    return this.status === 0
  }
}

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  headers?: Record<string, string>
  signal?: AbortSignal
  query?: Record<string, string | number | boolean | null | undefined>
}

export interface Envelope<T> {
  data: T
  meta?: Record<string, unknown>
}

type HeaderProvider = (method: string) => Record<string, string>
const headerProviders: HeaderProvider[] = []

/** Lets the admin module attach the CSRF header without coupling the public bundle to it. */
export function registerHeaderProvider(provider: HeaderProvider): () => void {
  headerProviders.push(provider)
  return () => {
    const i = headerProviders.indexOf(provider)
    if (i >= 0) headerProviders.splice(i, 1)
  }
}

function buildUrl(path: string, query?: RequestOptions['query']): string {
  const url = `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`
  if (!query) return url
  const params = new URLSearchParams()
  for (const [k, v] of Object.entries(query)) {
    if (v !== undefined && v !== null && v !== '') params.set(k, String(v))
  }
  const qs = params.toString()
  return qs ? `${url}?${qs}` : url
}

export async function request<T>(path: string, options: RequestOptions = {}): Promise<Envelope<T>> {
  const method = options.method ?? 'GET'
  const headers: Record<string, string> = { Accept: 'application/json' }
  for (const provider of headerProviders) Object.assign(headers, provider(method))
  Object.assign(headers, options.headers)

  let body: BodyInit | undefined
  if (options.body instanceof FormData) {
    body = options.body
  } else if (options.body !== undefined) {
    headers['Content-Type'] = 'application/json'
    body = JSON.stringify(options.body)
  }

  let response: Response
  try {
    response = await fetch(buildUrl(path, options.query), {
      method,
      headers,
      body,
      signal: options.signal,
      credentials: 'same-origin',
    })
  } catch (error) {
    if (error instanceof DOMException && error.name === 'AbortError') throw error
    throw new ApiError(0, 'network_error', 'We could not reach the server. Please check your connection and try again.')
  }

  if (response.status === 204) return { data: undefined as T }

  const isJson = response.headers.get('Content-Type')?.includes('application/json') ?? false
  const payload: unknown = isJson ? await response.json().catch(() => null) : null

  if (!response.ok) {
    const err = (payload as { error?: { code?: string; message?: string; fields?: FieldErrors; request_id?: string } } | null)?.error
    throw new ApiError(
      response.status,
      err?.code ?? 'http_error',
      err?.message ?? 'Something went wrong. Please try again.',
      err?.fields ?? {},
      err?.request_id ?? response.headers.get('X-Request-Id'),
    )
  }

  return (payload ?? { data: undefined }) as Envelope<T>
}

export const api = {
  get: <T>(path: string, options: Omit<RequestOptions, 'method' | 'body'> = {}) => request<T>(path, { ...options, method: 'GET' }).then((r) => r.data),
  getEnvelope: <T>(path: string, options: Omit<RequestOptions, 'method' | 'body'> = {}) => request<T>(path, { ...options, method: 'GET' }),
  post: <T>(path: string, body?: unknown, options: Omit<RequestOptions, 'method' | 'body'> = {}) => request<T>(path, { ...options, method: 'POST', body }).then((r) => r.data),
  put: <T>(path: string, body?: unknown, options: Omit<RequestOptions, 'method' | 'body'> = {}) => request<T>(path, { ...options, method: 'PUT', body }).then((r) => r.data),
  patch: <T>(path: string, body?: unknown, options: Omit<RequestOptions, 'method' | 'body'> = {}) => request<T>(path, { ...options, method: 'PATCH', body }).then((r) => r.data),
  delete: <T>(path: string, options: Omit<RequestOptions, 'method' | 'body'> = {}) => request<T>(path, { ...options, method: 'DELETE' }).then((r) => r.data),
}

/** Idempotency keys must match the backend's /^[A-Za-z0-9-]{16,64}$/ rule. */
export function idempotencyKey(): string {
  return crypto.randomUUID()
}
