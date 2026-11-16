/** Thin, typed fetch wrapper for the EasyHealthcare API (envelope: {data, meta} / {error}). */
export interface ApiError { code: string; message: string; fields?: Record<string, string[]>; context?: unknown }
export class ApiException extends Error {
  constructor(public status: number, public error: ApiError) { super(error.message) }
}
export interface Paginated<T> { data: T[]; meta: { pagination: { page: number; per_page: number; total: number; last_page: number } } }

const TOKEN_KEY = 'ehc.token'
const PATIENT_TOKEN_KEY = 'ehc.patient_token'

// Tokens live in sessionStorage (cleared when the tab closes). Production deployments should prefer
// Sanctum SPA cookie auth on a first-party domain; this client supports both.
export const auth = {
  get token() { return sessionStorage.getItem(TOKEN_KEY) },
  set token(v: string | null) { v ? sessionStorage.setItem(TOKEN_KEY, v) : sessionStorage.removeItem(TOKEN_KEY) },
  get patientToken() { return sessionStorage.getItem(PATIENT_TOKEN_KEY) },
  set patientToken(v: string | null) { v ? sessionStorage.setItem(PATIENT_TOKEN_KEY, v) : sessionStorage.removeItem(PATIENT_TOKEN_KEY) },
  organizationId: null as string | null,
}

type Query = Record<string, string | number | boolean | undefined | null>

async function request<T>(method: string, path: string, body?: unknown, opts: { query?: Query; patient?: boolean; raw?: boolean; headers?: Record<string, string> } = {}): Promise<T> {
  const url = new URL(path.startsWith('/api') ? path : `/api/v1${path}`, window.location.origin)
  for (const [k, v] of Object.entries(opts.query ?? {})) if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v))
  const headers: Record<string, string> = { Accept: 'application/json', ...opts.headers }
  const token = opts.patient ? auth.patientToken : auth.token
  if (token) headers.Authorization = `Bearer ${token}`
  if (auth.organizationId && !opts.patient) headers['X-Organization-Id'] = auth.organizationId
  let payload: BodyInit | undefined
  if (body instanceof FormData) payload = body
  else if (body !== undefined) { headers['Content-Type'] = 'application/json'; payload = JSON.stringify(body) }

  const res = await fetch(url, { method, headers, body: payload })
  if (opts.raw) {
    if (!res.ok) throw new ApiException(res.status, { code: 'HTTP_' + res.status, message: res.statusText })
    return res as unknown as T
  }
  if (res.status === 204) return undefined as T
  const json = await res.json().catch(() => ({}))
  if (!res.ok) {
    if (res.status === 401 && !opts.patient && !path.includes('/auth/')) window.dispatchEvent(new CustomEvent('ehc:unauthenticated'))
    throw new ApiException(res.status, json.error ?? { code: 'HTTP_' + res.status, message: res.statusText })
  }
  return json as T
}

export const api = {
  get: <T>(path: string, query?: Query, patient = false) => request<{ data: T }>('GET', path, undefined, { query, patient }).then(r => r.data),
  list: <T>(path: string, query?: Query) => request<Paginated<T>>('GET', path, undefined, { query }),
  post: <T>(path: string, body: unknown = {}, patient = false, headers?: Record<string, string>) => request<{ data: T }>('POST', path, body, { patient, headers }).then(r => r?.data),
  put: <T>(path: string, body: unknown) => request<{ data: T }>('PUT', path, body).then(r => r.data),
  patch: <T>(path: string, body: unknown) => request<{ data: T }>('PATCH', path, body).then(r => r.data),
  del: (path: string, patient = false) => request<void>('DELETE', path, undefined, { patient }),
  /** Opens a PDF/CSV endpoint that needs the bearer token. */
  async open(path: string, query?: Query, patient = false) {
    const res = await request<Response>('GET', path, undefined, { query, patient, raw: true })
    const blob = await res.blob()
    window.open(URL.createObjectURL(blob), '_blank', 'noopener')
  },
}

export function errorMessage(e: unknown): string {
  if (e instanceof ApiException) {
    const field = e.error.fields ? Object.values(e.error.fields)[0]?.[0] : undefined
    return field ?? e.error.message
  }
  return e instanceof Error ? e.message : 'Something went wrong'
}
