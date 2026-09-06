import { useEffect, type ReactNode } from 'react'
import { LoginScreen } from './LoginScreen'
import { ServerUnavailable } from './ServerUnavailable'
import { boot, useSession } from './session'

/**
 * Boot, with three states and no more.
 *
 * No blank screens and no eternal spinners: if the server does not answer, it
 * says so with the technical detail underneath. Whoever is at the counter needs
 * to know whether the problem is theirs or the system's.
 */
export function Boot({ children }: { children: ReactNode }) {
  const { capabilities, status, error } = useSession()

  useEffect(() => {
    void boot()
  }, [])

  if (status === 'loading') {
    return (
      <main className="grid min-h-dvh place-items-center text-[var(--text-muted)]">
        Cargando…
      </main>
    )
  }

  if (status === 'unavailable') {
    return <ServerUnavailable error={error} />
  }

  if (capabilities?.user == null) {
    return <LoginScreen />
  }

  return <>{children}</>
}
