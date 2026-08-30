import { ApiError } from '@kombo/api-client'
import { Button, Card, Field, Input, Money, Spinner } from '@kombo/ui'
import { QueryClient, QueryClientProvider, useQuery } from '@tanstack/react-query'
import { useState, type FormEvent } from 'react'
import { platform } from './api'
import { TenantDetailScreen } from './TenantDetailScreen'
import { TenantsScreen } from './TenantsScreen'

/**
 * La super administración.
 *
 * Vive en `admin.dominio` y entra por su propia puerta: estar dentro de un
 * negocio no abre esto, ni al revés. Es la pantalla que permite vender el
 * sistema — dar de alta, cobrar, y saber a quién hay que llamar hoy.
 *
 * Sin router: son dos pantallas y media, y meter uno costaría bundle para
 * resolver un `useState`.
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
  const sesion = useQuery({ queryKey: ['platform-me'], queryFn: platform.me })
  const [abierto, setAbierto] = useState<string | null>(null)

  if (sesion.isLoading) return <Spinner label="Un momento…" />

  if (sesion.data == null) {
    return <LoginScreen onDone={() => void sesion.refetch()} />
  }

  return (
    <div className="min-h-dvh bg-[var(--surface-sunken)]">
      {/* La barra a todo el ancho y su contenido alineado con el de la página:
          si no, el nombre queda pegado al borde y el contenido empieza
          doscientos píxeles más adentro, como si fueran dos páginas. */}
      <header className="border-b border-[var(--surface-border)] bg-[var(--surface-raised)]">
        <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-4 sm:px-6 lg:px-8">
          <div className="flex-1">
            <p className="font-semibold text-[var(--text-strong)]">Kombo · Administración</p>
            <p className="text-sm text-[var(--text-muted)]">{sesion.data.name}</p>
          </div>

          <Button
            variant="ghost"
            onClick={async () => {
              await platform.logout()
              void sesion.refetch()
            }}
          >
            Salir
          </Button>
        </div>
      </header>

      {/* `max-w-7xl` y no `max-w-4xl`: esto es un tablero de negocios, y lo que
          se gana con el ancho es cuántos se ven de una vez. */}
      <main className="mx-auto flex max-w-7xl flex-col gap-6 px-4 py-5 sm:px-6 lg:px-8">
        {abierto === null ? (
          <>
            <Metrics />
            <TenantsScreen onOpen={setAbierto} />
          </>
        ) : (
          <TenantDetailScreen id={abierto} onBack={() => setAbierto(null)} />
        )}
      </main>
    </div>
  )
}

/**
 * Cuatro cifras y ninguna más.
 *
 * Un tablero con veinte gráficos es un tablero que nadie mira. Éstas contestan
 * «¿esto va bien?» en cinco segundos.
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
      // Un solo mensaje para los tres fallos posibles: no revelar cuál de las
      // tres cosas acertó quien lo intenta.
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
