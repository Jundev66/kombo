import { useEffect, type ReactNode } from 'react'
import { LoginScreen } from './LoginScreen'
import { boot, useSession } from './session'

/**
 * El arranque, con tres estados y ninguno más.
 *
 * Ni pantallas en blanco ni spinners eternos: si el servidor no contesta se
 * dice, con el detalle técnico debajo. Quien está en el mostrador necesita
 * saber si el problema es suyo o del sistema.
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
    return (
      <main className="grid min-h-dvh place-items-center p-6 text-center">
        <div>
          <h1 className="text-lg font-bold text-[var(--text-strong)]">
            No se pudo contactar al servidor
          </h1>
          <p className="mt-2 text-sm text-[var(--text-muted)]">
            Revisa la conexión. Si el problema sigue, avísanos.
          </p>
          {error != null && (
            <p className="mt-4 font-mono text-xs text-[var(--text-muted)]">{error}</p>
          )}
        </div>
      </main>
    )
  }

  if (capabilities?.user == null) {
    return <LoginScreen />
  }

  return <>{children}</>
}
