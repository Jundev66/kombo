import type { Capabilities } from './capabilities'

/**
 * The HTTP client. No axios: ~15 KB to do what `fetch` does.
 *
 * The tenant travels neither as a parameter nor as a header — it comes from the
 * subdomain the page was loaded from. There is no way to ask for another
 * tenant's data from here, not even by mistake.
 */

export class ApiError extends Error {
  constructor(
    public readonly status: number,
    public readonly body: unknown,
    message: string,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

type TokenSource = () => string | null

let bearerToken: TokenSource = () => null

/**
 * The till and the kitchen use a token; the dashboard and the portal a session
 * cookie.
 *
 * It takes a FUNCTION rather than a value because the token changes within one
 * screen session: first the device's, then the person's once they enter a PIN.
 */
export function useBearerToken(source: TokenSource): void {
  bearerToken = source
}

function xsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

  // decodeURIComponent is not optional: the cookie arrives encoded, and sending
  // it raw returns a 419 that looks like a session problem and is not.
  return match?.[1] ? decodeURIComponent(match[1]) : null
}

/**
 * Everything in flight right now, so it can all be cut at once.
 *
 * It exists for signing out. Every Laravel response carries its session
 * `Set-Cookie`; a read that LEFT before signing out but ARRIVES after carries
 * the previous session's cookie and the browser applies it, undoing the sign-out
 * with the previous person's name still on screen.
 *
 * On a counter machine three people share in a shift, that is not a detail —
 * and it is intermittent, which is the worst way to have it.
 */
let inFlight = new AbortController()

export function abortInFlightRequests(): void {
  inFlight.abort()
  inFlight = new AbortController()
}

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
  /** Outside the cut above: signing out does not cancel itself. */
  uncancellable = false,
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
  }

  const xsrf = xsrfToken()
  if (xsrf) {
    headers['X-XSRF-TOKEN'] = xsrf
  }

  const token = bearerToken()
  if (token) {
    headers['Authorization'] = `Bearer ${token}`
  }

  // The body is added only when it exists. With `exactOptionalPropertyTypes`,
  // `body: undefined` is not the same as no `body` — and a GET with a body is
  // an invalid request.
  const init: RequestInit = { method, headers, credentials: 'same-origin' }

  if (!uncancellable) {
    init.signal = inFlight.signal
  }

  if (body !== undefined) {
    init.body = JSON.stringify(body)
  }

  const response = await fetch(`/api/v1${path}`, init)

  if (response.status === 204) {
    return null as T
  }

  const text = await response.text()
  const parsed: unknown = text ? JSON.parse(text) : null

  if (!response.ok) {
    const message =
      typeof parsed === 'object' && parsed !== null && 'message' in parsed
        ? String((parsed as { message: unknown }).message)
        : `La petición falló con ${response.status}`

    throw new ApiError(response.status, parsed, message)
  }

  return parsed as T
}

export const api = {
  get: <T>(path: string) => request<T>('GET', path),
  post: <T>(path: string, body?: unknown) => request<T>('POST', path, body),
  patch: <T>(path: string, body?: unknown) => request<T>('PATCH', path, body),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, body),
  delete: <T>(path: string) => request<T>('DELETE', path),

  capabilities: () => request<Capabilities>('GET', '/me'),

  /**
   * Asks for the CSRF cookie before signing in.
   *
   * Outside the `/api/v1` prefix because Sanctum serves it. Without this call,
   * the FIRST login in a tab answers 419 and the rest work — one of the most
   * confusing errors there is.
   */
  csrf: () => fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }),

  async login(email: string, password: string): Promise<void> {
    await api.csrf()
    await request<{ ok: boolean }>('POST', '/auth/login', { email, password })
  },

  /**
   * Signing out: what is still in flight is cut first, then the session closes.
   *
   * The order is the part that matters. See `abortInFlightRequests`.
   */
  async logout(): Promise<void> {
    abortInFlightRequests()

    await request<{ ok: boolean }>('POST', '/auth/logout', undefined, true)
  },
}
