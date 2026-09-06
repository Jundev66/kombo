import { api, ApiError, type Capabilities } from '@kombo/api-client'
import { useSyncExternalStore } from 'react'

/**
 * The session: who signed in and what they can do.
 *
 * With `useSyncExternalStore` rather than zustand or context + reducer. About
 * forty lines against one more dependency, and on a 4 GB counter PC every
 * kilobyte of the boot shows. A context at the root would also redraw the whole
 * tree on any change.
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
 * Loads the capabilities.
 *
 * `/me` also answers WITHOUT a session — returning the tenant and zero
 * permissions — which is why this is called before knowing whether anyone is
 * inside: the login screen needs the tenant's name and logo.
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
 * Signing out, and checking it stayed out.
 *
 * Every Laravel response carries its session cookie. A read that LEFT before
 * the sign-out but ARRIVES after carries the previous session's cookie and the
 * browser applies it: the session is open again, with the name of whoever just
 * left still on screen.
 *
 * `api.logout()` already cuts what is in flight, so this is almost never
 * needed. Almost: nothing stops another part of the screen firing its own
 * `fetch`, and on a machine three people share per shift, "almost never" is not
 * enough — what stays open is somebody else's session.
 */
export async function logout(): Promise<void> {
  for (let attemptNo = 0; attemptNo < 3; attemptNo++) {
    await api.logout()
    await boot()

    if (state.capabilities?.user == null) return
  }
}
