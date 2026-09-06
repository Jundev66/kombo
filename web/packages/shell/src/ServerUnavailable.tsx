/**
 * The server did not answer.
 *
 * Its own component because two doors need it and they must say the same thing:
 * the tenants' `Boot` and platform administration, which has its own session and
 * cannot use `Boot`. Duplicating the copy is how the two sides drift into
 * describing the same outage differently.
 *
 * The technical detail goes underneath on purpose. Whoever is at the counter
 * needs to know whether the problem is theirs or the system's, and whoever is
 * asked about it needs something to repeat.
 */
export function ServerUnavailable({ error }: { error?: string | null }) {
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
