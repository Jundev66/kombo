import {
  backToDashboard,
  SupervisionBanner,
  TerminalGate,
  useDoorway,
  useSession,
} from '@kombo/shell'
import { plural } from '@kombo/ui'
import { formatWait, useKitchen } from './useKitchen'
import type { Ticket } from './api'

/**
 * The kitchen screen.
 *
 * It does not navigate, filter or search, and there is no router here: a cook
 * with their hands full does not explore an app. Three columns, one button per
 * ticket, and nothing else to tap.
 */
export function App() {
  const { capabilities } = useSession()
  const { mode, enter, endShift } = useDoorway()

  if (mode === 'gate') {
    return (
      <TerminalGate deviceName="Cocina" question="¿Quién está en la cocina?" onReady={enter} />
    )
  }

  const supervising = mode === 'supervision'

  return (
    // The height is set by this container: with the banner up, two elements
    // claiming the whole screen push the last column off it.
    <div className="flex h-dvh flex-col bg-[var(--surface-sunken)]">
      {supervising && capabilities?.user != null && (
        <SupervisionBanner user={capabilities.user} onLeave={backToDashboard} />
      )}

      <Board
        // Whoever operates according to the SERVER, not whoever entered the PIN.
        operator={capabilities?.user?.name ?? null}
        onLeave={supervising ? null : endShift}
      />
    </div>
  )
}

const COLUMNS = [
  { status: 'pending', title: 'Por hacer' },
  { status: 'preparing', title: 'En la plancha' },
  { status: 'ready', title: 'Para entregar' },
] as const

function Board({ operator, onLeave }: { operator: string | null; onLeave: (() => void) | null }) {
  const { tickets, hidden, loading, error, advance, waitedSeconds, isLate } = useKitchen()

  return (
    // `min-h-0` and not `h-dvh`: height is set by whoever mounts us, who knows
    // whether there is a banner above.
    <div className="flex min-h-0 flex-1 flex-col bg-[var(--surface-sunken)]">
      <header className="flex h-14 shrink-0 items-center gap-3 bg-ink-900 px-4 text-white">
        <h1 className="font-semibold">Cocina</h1>

        {operator != null && (
          <span className="min-w-0 truncate text-sm text-ink-300">· {operator}</span>
        )}

        <div className="flex-1" />

        {/* A dropped connection is announced without clearing the screen: those
            orders are still on the griddle. */}
        {error != null && (
          <span role="alert" className="rounded-full bg-warn-500 px-2 py-0.5 text-xs text-ink-900">
            {error}
          </span>
        )}

        {/* If there are tickets that do not fit, IT SAYS SO. Truncating silently
            is the worst possible failure here: ordered oldest to newest, what
            falls off the end are the just-arrived ones — and the customer waits
            for food nobody is making. */}
        {hidden > 0 && (
          <span role="alert" className="rounded-full bg-bad-500 px-2 py-0.5 text-xs font-medium text-white">
            {hidden} sin caber · marca las que ya salieron
          </span>
        )}

        <span className="tabular text-sm text-ink-300">
          {loading ? 'Cargando…' : plural(tickets.length, 'pedido', 'pedidos')}
        </span>

        {onLeave != null && (
          <button
            type="button"
            onClick={onLeave}
            className="shrink-0 text-sm text-ink-300 underline-offset-2 hover:underline"
          >
            Salir
          </button>
        )}
      </header>

      <div className="grid flex-1 grid-cols-1 gap-3 overflow-hidden p-3 md:grid-cols-3">
        {COLUMNS.map((column) => {
          const inColumn = tickets.filter((t) => t.status === column.status)

          return (
            <section key={column.status} className="flex min-h-0 flex-col">
              <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold tracking-wide text-ink-400 uppercase">
                {column.title}
                <span className="tabular text-ink-500">{inColumn.length}</span>
              </h2>

              <ul className="flex flex-1 flex-col gap-3 overflow-auto">
                {inColumn.length === 0 && (
                  <li className="py-8 text-center text-sm text-ink-500">Nada aquí</li>
                )}

                {inColumn.map((ticket) => (
                  <li key={ticket.id}>
                    <OrderCard
                      ticket={ticket}
                      seconds={waitedSeconds(ticket)}
                      late={isLate(ticket)}
                      onAdvance={() => void advance(ticket)}
                    />
                  </li>
                ))}
              </ul>
            </section>
          )
        })}
      </div>
    </div>
  )
}

function OrderCard({
  ticket,
  seconds,
  late,
  onAdvance,
}: {
  ticket: Ticket
  seconds: number
  late: boolean
  onAdvance: () => void
}) {
  return (
    <article
      /*
       * Named: "Ticket #131".
       *
       * A screen reader announces which ticket before reading what is on it,
       * and a test can point at ONE without depending on how the text runs
       * together — the number and the stopwatch sit side by side, so "#131" and
       * "#1310" get confused.
       */
      aria-label={`Comanda #${ticket.number}`}
      /*
       * Running late ⇒ the WHOLE BORDER red, not a small icon.
       *
       * It has to be visible from the griddle, out of the corner of an eye,
       * without stopping what you are doing. Nobody sees a dot in a corner.
       */
      className={`rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-3 ${
        late ? 'border-2 border-bad-500' : 'border border-[var(--surface-border)]'
      }`}
    >
      <header className="flex items-baseline justify-between gap-2">
        {/* The number large: it is what gets shouted across the counter. */}
        <span className="tabular text-2xl font-bold text-[var(--text-strong)]">#{ticket.number}</span>
        <span className={`tabular text-lg ${late ? 'font-bold text-bad-500' : 'text-[var(--text-muted)]'}`}>
          {formatWait(seconds)}
        </span>
      </header>

      <p className="mb-2 text-xs text-[var(--text-muted)]">
        {serviceLabel(ticket.serviceType)}
        {ticket.takenByName != null && ` · ${ticket.takenByName}`}
      </p>

      <ul className="mb-3 flex flex-col gap-1.5">
        {ticket.items.map((item) => (
          <li key={item.id}>
            <p className="text-lg text-[var(--text-strong)]">
              <b className="tabular">{formatQuantity(item.quantity)}×</b> {item.name}
            </p>

            {/* Add-ons on their own line and in amber: exactly what gets skipped
                when reading fast, and skipping them means remaking the dish. */}
            {item.modifiers.length > 0 && (
              <p className="text-base font-medium text-warn-500">{item.modifiers.join(' · ')}</p>
            )}

            {item.notes != null && (
              <p className="text-sm text-[var(--text-muted)]">«{item.notes}»</p>
            )}
          </li>
        ))}
      </ul>

      {ticket.notes != null && (
        <p className="mb-3 text-sm text-[var(--text-muted)]">«{ticket.notes}»</p>
      )}

      {ticket.nextLabel != null && (
        <button
          type="button"
          onClick={onAdvance}
          // 64 px tall and full width: tapped with the back of a hand, with gloves
          // on and without looking.
          className="h-16 w-full rounded-[var(--radius-md)] bg-brand-700 text-lg font-medium text-white hover:bg-brand-600"
        >
          {ticket.nextLabel}
        </button>
      )}
    </article>
  )
}

function serviceLabel(serviceType: string | null): string {
  switch (serviceType) {
    case 'dine_in':
      return 'Para comer aquí'
    case 'delivery':
      return 'Delivery'
    default:
      return 'Para llevar'
  }
}

/** Whole as it is; decimals with a comma, which is how it is written here. */
function formatQuantity(quantity: number): string {
  return Number.isInteger(quantity) ? String(quantity) : quantity.toFixed(3).replace('.', ',')
}
