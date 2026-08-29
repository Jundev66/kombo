import type { Capabilities } from './capabilities'

/**
 * El cliente HTTP. Sin axios: son ~15 KB para hacer lo que hace `fetch`.
 *
 * **El negocio no viaja como parámetro ni como cabecera.** Sale del subdominio
 * desde el que se cargó la página. No hay forma de pedir los datos de otro
 * negocio desde aquí, ni siquiera equivocándose.
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
 * La caja y la cocina usan token; el panel y el portal, cookie de sesión.
 *
 * Recibe una FUNCIÓN y no un valor porque el token cambia dentro de la misma
 * sesión de la pantalla: primero es el del dispositivo, y al poner el PIN pasa
 * a ser el de la persona.
 */
export function useBearerToken(source: TokenSource): void {
  bearerToken = source
}

function xsrfToken(): string | null {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/)

  // decodeURIComponent NO es opcional: la cookie viene codificada y mandarla
  // tal cual devuelve un 419 que parece un problema de sesión y no lo es.
  return match?.[1] ? decodeURIComponent(match[1]) : null
}

/**
 * Todo lo que hay en vuelo ahora mismo, para poder cortarlo de golpe.
 *
 * Existe por el cierre de sesión, y el motivo es más sutil de lo que parece.
 *
 * Cada respuesta de Laravel trae su `Set-Cookie` de sesión. Una lectura que
 * SALIÓ antes de cerrar sesión pero LLEGA después trae la cookie de la sesión
 * anterior —la cargó antes de que se destruyera— y el navegador la aplica tal
 * cual: deshace el cierre de sesión. La pantalla se queda dentro, con el
 * nombre de la persona anterior arriba.
 *
 * En una máquina de mostrador, que se pasan tres personas en un turno, eso no
 * es un detalle. Y es intermitente —hace falta que la lectura y el cierre se
 * solapen de verdad— que es la peor forma de tenerlo.
 *
 * Cortarlas antes de cerrar significa que sus respuestas no llegan, y una
 * respuesta que no llega no trae cookie que aplicar.
 */
let enVuelo = new AbortController()

export function abortarPeticionesEnVuelo(): void {
  enVuelo.abort()
  enVuelo = new AbortController()
}

async function request<T>(
  method: string,
  path: string,
  body?: unknown,
  /** Fuera del corte de arriba: el propio cierre de sesión no se autocancela. */
  aparte = false,
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

  // El cuerpo se añade sólo si existe. Con `exactOptionalPropertyTypes`,
  // pasar `body: undefined` no es lo mismo que no pasar `body` — y aquí la
  // diferencia importa: un GET con cuerpo es una petición inválida.
  const init: RequestInit = { method, headers, credentials: 'same-origin' }

  if (!aparte) {
    init.signal = enVuelo.signal
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
   * Pide la cookie de CSRF antes de entrar.
   *
   * Va fuera del prefijo `/api/v1` porque la sirve Sanctum. Sin esta llamada,
   * el PRIMER login de una pestaña responde 419 y el resto funciona — que es
   * de los errores más confusos que hay.
   */
  csrf: () => fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }),

  async login(email: string, password: string): Promise<void> {
    await api.csrf()
    await request<{ ok: boolean }>('POST', '/auth/login', { email, password })
  },

  /**
   * Cerrar sesión: primero se corta lo que quedó en vuelo, y después se cierra.
   *
   * El orden es la parte que importa. Ver `abortarPeticionesEnVuelo`.
   */
  async logout(): Promise<void> {
    abortarPeticionesEnVuelo()

    await request<{ ok: boolean }>('POST', '/auth/logout', undefined, true)
  },
}
