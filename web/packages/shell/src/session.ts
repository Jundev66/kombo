import { api, ApiError, type Capabilities } from '@kombo/api-client'
import { useSyncExternalStore } from 'react'

/**
 * La sesión: quién entró y qué puede hacer.
 *
 * Con `useSyncExternalStore` y no con zustand ni con contexto + reducer. Son
 * unas cuarenta líneas contra una dependencia más, y en una PC de mostrador de
 * 4 GB cada kilobyte del arranque se nota. Además, un contexto en la raíz
 * redibujaría el árbol entero cada vez que cambia cualquier cosa.
 */

export type SessionStatus = 'loading' | 'ready' | 'unavailable'

interface SessionState {
  capabilities: Capabilities | null
  status: SessionStatus
  error: string | null
}

let state: SessionState = { capabilities: null, status: 'loading', error: null }
const listeners = new Set<() => void>()

function set(next: Partial<SessionState>): void {
  state = { ...state, ...next }
  listeners.forEach((listener) => listener())
}

function subscribe(listener: () => void): () => void {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

export function useSession(): SessionState {
  return useSyncExternalStore(subscribe, () => state, () => state)
}

/**
 * Carga las capacidades.
 *
 * `/me` responde también SIN sesión —devuelve el negocio y cero permisos—, y
 * por eso esto se llama antes de saber si hay alguien dentro: la pantalla de
 * login necesita el nombre y el logo del negocio.
 */
export async function boot(): Promise<void> {
  try {
    set({ capabilities: await api.capabilities(), status: 'ready', error: null })
  } catch (error) {
    set({
      status: 'unavailable',
      error: error instanceof ApiError ? error.message : 'No se pudo contactar al servidor.',
    })
  }
}

export async function login(email: string, password: string): Promise<void> {
  await api.login(email, password)
  await boot()
}

/**
 * Cerrar sesión, y comprobar que quedó cerrada.
 *
 * La comprobación no sobra, y el motivo es de los que no se adivinan leyendo
 * el código.
 *
 * Cada respuesta de Laravel trae su cookie de sesión. Una lectura que SALIÓ
 * antes del cierre pero LLEGA después trae la cookie de la sesión anterior —la
 * cargó antes de que se destruyera— y el navegador la aplica tal cual: la
 * sesión vuelve a estar abierta. La pantalla se queda dentro, con el nombre de
 * quien acaba de irse arriba.
 *
 * `api.logout()` ya corta lo que está en vuelo, así que esto casi nunca hace
 * falta. Casi: nada impide que otra parte de la pantalla haya lanzado un
 * `fetch` por su cuenta. Y en una máquina de mostrador, que se pasan tres
 * personas por turno, «casi nunca» no es suficiente — lo que queda abierto es
 * la sesión de otra persona.
 *
 * Así que si después de cerrar sigue habiendo alguien dentro, se cierra otra
 * vez. Para entonces las lecturas rezagadas ya llegaron y no hay nada que
 * pueda devolver la cookie vieja.
 */
export async function logout(): Promise<void> {
  for (let intento = 0; intento < 3; intento++) {
    await api.logout()
    await boot()

    if (state.capabilities?.user == null) return
  }
}
