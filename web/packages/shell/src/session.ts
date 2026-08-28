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

export async function logout(): Promise<void> {
  await api.logout()
  await boot()
}
