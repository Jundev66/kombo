import { ApiError } from '@kombo/api-client'
import { ServerUnavailable } from '@kombo/shell'
import { Button, Card, Field, Input, Money, Spinner } from '@kombo/ui'
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { platform } from './api'
import { TenantDetailScreen } from './TenantDetailScreen'
import { TenantsScreen } from './TenantsScreen'

/**
 * Platform administration.
 *
 * It lives at `admin.domain` and comes in through its own door: being inside a
 * tenant does not open this, or the other way round.
 *
 * No router: it is two and a half screens, and adding one would cost bundle to
 * replace a `useState`.
 */
const queryClient = new QueryClient({
  defaultOptions: { queries: { retry: false, refetchOnWindowFocus: false } },
})

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <Shell />
    </QueryClientProvider>
  )
}

function Shell() {
  const session = useQuery({ queryKey: ['platform-me'], queryFn: platform.me })
  const [openNow, setOpenNow] = useState<string | null>(null)

  if (session.isLoading) return <Spinner label="Un momento…" />

  // Three states and no more, the same three as `Boot` on the tenants' side.
  // `/platform/me` answers 200 with `null` when nobody is signed in, so a server
  // that does not answer at all is a DIFFERENT thing: without this branch the
  // query fails, `data` comes back undefined, and the door is painted over a
  // dead backend. It says "sign in" to somebody whose password was never the
  // problem — and it is why the test guarding this door stayed green through an
  // outage that answered 502 to every request. KMB-0014.
  if (session.isError) {
    return (
      <ServerUnavailable
        error={session.error instanceof ApiError ? session.error.message : null}
      />
    )
  }

  if (session.data == null) {
    return <LoginScreen onDone={() => void session.refetch()} />
  }

  return (
    <div className="min-h-dvh bg-[var(--surface-sunken)]">
      {/* The bar spans the full width with its content aligned to the page's, or
          the name sits against the edge while the content starts two hundred
          pixels in. */}
      <header className="border-b border-[var(--surface-border)] bg-[var(--surface-raised)]">
        <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-4 sm:px-6 lg:px-8">
          <div className="flex-1">
            <p className="font-semibold text-[var(--text-strong)]">Kombo · Administración</p>
            <p className="text-sm text-[var(--text-muted)]">{session.data.name}</p>
          </div>

          <Button
            variant="ghost"
            onClick={async () => {
              await platform.logout()
              void session.refetch()
            }}
          >
            Salir
          </Button>
        </div>
      </header>

      {/* `max-w-7xl` and not `max-w-4xl`: this is a board of tenants, and what
          the width buys is how many are visible at once. */}
      <main className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8">
        {openNow === null ? (
          <>
            <Metrics />
            <TenantsScreen onOpen={setOpenNow} />
          </>
        ) : (
          <TenantDetailScreen id={openNow} onBack={() => setOpenNow(null)} />
        )}
      </main>
    </div>
  )
}

/**
 * Four figures and no more. A board with twenty charts is a board nobody looks
 * at; these answer "is this going well?" in five seconds.
 */
function Metrics() {
  const metrics = useQuery({ queryKey: ['platform-metrics'], queryFn: platform.metrics })

  if (metrics.data === undefined) return null

  const m = metrics.data

  return (
    <div className="flex flex-col gap-4">
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Metric label="Negocios activos" value={String(m.tenants.active)} />
        <Metric label="Altas del mes" value={String(m.tenants.newThisMonth)} />
        <Metric label="Ingreso mensual" money={m.mrrCents} />
        <Metric label="Pedidos del mes" value={m.ordersThisMonth.toLocaleString('es-VE')} />
      </div>

      {(m.tenants.pastDue > 0 || m.tenants.suspended > 0) && (
        <Card className="flex flex-wrap items-center gap-3 p-4">
          <p className="flex-1 text-sm text-[var(--text-default)]">
            {m.tenants.pastDue > 0 && <strong>{m.tenants.pastDue} vencidos</strong>}
            {m.tenants.pastDue > 0 && m.tenants.suspended > 0 && ' · '}
            {m.tenants.suspended > 0 && <strong>{m.tenants.suspended} suspendidos</strong>}
          </p>
        </Card>
      )}

      {m.expiringSoon.length > 0 && (
        <Card className="flex flex-col gap-2 p-4">
          <h2 className="font-semibold text-[var(--text-strong)]">Vencen esta semana</h2>

          <ul className="flex flex-col gap-1 text-sm">
            {m.expiringSoon.map((tenant) => (
              <li key={tenant.slug} className="flex justify-between gap-3">
                <span className="text-[var(--text-default)]">{tenant.name}</span>
                <span className="tabular text-[var(--text-muted)]">en {tenant.daysLeft} d</span>
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}

function Metric({ label, value, money }: { label: string; value?: string; money?: number }) {
  return (
    <Card className="p-4">
      <p className="text-sm text-[var(--text-muted)]">{label}</p>

      {money !== undefined ? (
        <Money cents={money} scale="lg" />
      ) : (
        <p className="tabular text-money-lg font-semibold text-[var(--text-strong)]">{value}</p>
      )}
    </Card>
  )
}

function LoginScreen({ onDone }: { onDone: () => void }) {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [sending, setSending] = useState(false)

  async function onSubmit(event: FormEvent): Promise<void> {
    event.preventDefault()
    setSending(true)
    setError(null)

    try {
      await platform.login(email, password)
      onDone()
    } catch (failure) {
      // One message for all three possible failures: never reveal which of the
      // three the caller got right.
      setError(failure instanceof ApiError ? failure.message : 'No se pudo entrar.')
      setSending(false)
    }
  }

  return (
    <main className="grid min-h-dvh place-items-center bg-[var(--surface-sunken)] p-6">
      <Card className="w-full max-w-sm p-6">
        <h1 className="mb-6 text-center text-xl font-bold text-[var(--text-strong)]">
          Kombo · Administración
        </h1>

        <form onSubmit={onSubmit} className="flex flex-col gap-4">
          <Field label="Correo" required>
            {({ id }) => (
              <Input id={id} type="email" value={email} onChange={(e) => setEmail(e.target.value)} />
            )}
          </Field>

          <Field label="Contraseña" required error={error ?? undefined}>
            {({ id, invalid }) => (
              <Input
                id={id}
                type="password"
                value={password}
                invalid={invalid}
                onChange={(e) => setPassword(e.target.value)}
              />
            )}
          </Field>

          <Button type="submit" block disabled={sending}>
            {sending ? 'Entrando…' : 'Entrar'}
          </Button>
        </form>
      </Card>
    </main>
  )
}
