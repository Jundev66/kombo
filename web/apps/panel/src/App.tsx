import { Boot, logout, useSession } from '@kombo/shell'
import { Button } from '@kombo/ui'

/**
 * El panel del dueño. Por ahora, lo que la Fase 1 ya puede demostrar: que se
 * entra, que el servidor sabe quién eres y qué puedes, y que el frontend
 * pinta eso sin decidir nada.
 *
 * Las pantallas de verdad —pedidos, catálogo, equipo— llegan en su fase.
 */
export function App() {
  return (
    <Boot>
      <Inicio />
    </Boot>
  )
}

function Inicio() {
  const { capabilities } = useSession()

  if (capabilities?.user == null) return null

  const { tenant, user, modules, permissions, limits, moduleNames } = capabilities

  return (
    <main className="mx-auto min-h-dvh max-w-2xl p-6">
      <header className="mb-8 flex items-start justify-between gap-4">
        <div>
          <h1 className="text-2xl font-bold text-[var(--text-strong)]">{tenant?.name}</h1>
          <p className="text-sm text-[var(--text-muted)]">
            {user.name} · {user.isOwner ? 'Dueño' : 'Equipo'}
          </p>
        </div>
        <Button variant="secondary" size="sm" onClick={() => void logout()}>
          Salir
        </Button>
      </header>

      {tenant?.needsAttention === true && (
        <p
          role="alert"
          className="mb-6 rounded-[var(--radius-md)] bg-warn-50 p-3 text-sm text-warn-700"
        >
          Tu cuenta necesita atención. Revisa el pago para no quedarte en sólo lectura.
        </p>
      )}

      <section className="mb-6">
        <h2 className="mb-2 text-sm font-semibold text-[var(--text-muted)]">Lo que tienes</h2>
        <ul className="flex flex-wrap gap-2">
          {modules.map((code) => (
            <li
              key={code}
              className="rounded-[var(--radius-md)] bg-[var(--surface-raised)] px-3 py-1.5 text-sm"
            >
              {moduleNames[code] ?? code}
            </li>
          ))}
        </ul>
      </section>

      <section className="mb-6">
        <h2 className="mb-2 text-sm font-semibold text-[var(--text-muted)]">Lo que puedes hacer</h2>
        <ul className="flex flex-wrap gap-2">
          {permissions.map((permission) => (
            <li key={permission} className="font-mono text-xs text-[var(--text-muted)]">
              {permission}
            </li>
          ))}
        </ul>
      </section>

      <section>
        <h2 className="mb-2 text-sm font-semibold text-[var(--text-muted)]">Tu plan</h2>
        <p className="text-sm">
          {/* `null` es ilimitado, nunca cero. */}
          Hasta {limits.maxUsers ?? '∞'} personas en el equipo ·{' '}
          {limits.maxProducts ?? '∞'} productos
        </p>
      </section>
    </main>
  )
}
