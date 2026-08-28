import { TerminalGate, terminal } from '@kombo/shell'
import { useState } from 'react'
import { formatWait, useKitchen } from './useKitchen'
import type { Ticket } from './api'

/**
 * La pantalla de cocina.
 *
 * **No navega, no filtra y no busca.** No hay router aquí, y es a propósito:
 * un cocinero con las manos ocupadas no explora una aplicación. Tres columnas,
 * un botón por comanda, y nada más que tocar.
 */
export function App() {
  const [inside, setInside] = useState(terminal.stationToken() !== null)

  if (!inside) {
    return (
      <TerminalGate
        deviceName="Cocina"
        question="¿Quién está en la cocina?"
        onReady={() => setInside(true)}
      />
    )
  }

  return <Board onLeave={() => { terminal.endShift(); setInside(false) }} />
}

const COLUMNS = [
  { status: 'pending', title: 'Por hacer' },
  { status: 'preparing', title: 'En la plancha' },
  { status: 'ready', title: 'Para entregar' },
] as const

function Board({ onLeave }: { onLeave: () => void }) {
  const { tickets, hidden, loading, error, advance, waitedSeconds, isLate } = useKitchen()

  return (
    <div className="flex h-dvh flex-col bg-[var(--surface-sunken)]">
      <header className="flex h-14 shrink-0 items-center gap-3 bg-ink-900 px-4 text-white">
        <h1 className="font-semibold">Cocina</h1>

        <div className="flex-1" />

        {/* Que la conexión se cayó se dice, pero sin borrar la pantalla: esos
            pedidos siguen en la plancha. */}
        {error != null && (
          <span role="alert" className="rounded-full bg-warn-500 px-2 py-0.5 text-xs text-ink-900">
            {error}
          </span>
        )}

        {/* Si hay comandas que no caben, SE DICE. Cortarlas en silencio es el
            peor fallo posible aquí: como el orden es de la más vieja a la más
            nueva, lo que se queda fuera son las recién entradas — y el cliente
            espera comida que nadie está haciendo. */}
        {hidden > 0 && (
          <span role="alert" className="rounded-full bg-bad-500 px-2 py-0.5 text-xs font-medium text-white">
            {hidden} sin caber · marca las que ya salieron
          </span>
        )}

        <span className="tabular text-sm text-ink-300">
          {loading ? 'Cargando…' : `${tickets.length} pedidos`}
        </span>

        <button type="button" onClick={onLeave} className="text-sm text-ink-300 underline-offset-2 hover:underline">
          Salir
        </button>
      </header>

      <div className="grid flex-1 grid-cols-1 gap-3 overflow-hidden p-3 md:grid-cols-3">
        {COLUMNS.map((column) => {
          const enColumna = tickets.filter((t) => t.status === column.status)

          return (
            <section key={column.status} className="flex min-h-0 flex-col">
              <h2 className="mb-2 flex items-center gap-2 text-sm font-semibold tracking-wide text-ink-400 uppercase">
                {column.title}
                <span className="tabular text-ink-500">{enColumna.length}</span>
              </h2>

              <ul className="flex flex-1 flex-col gap-3 overflow-auto">
                {enColumna.length === 0 && (
                  <li className="py-8 text-center text-sm text-ink-500">Nada aquí</li>
                )}

                {enColumna.map((ticket) => (
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
       * Con nombre: «Comanda #131».
       *
       * Un lector de pantalla anuncia de qué comanda habla antes de leer lo
       * que lleva, y quien escribe una prueba puede señalar UNA sin depender
       * de cómo queda pegado el texto —el número y el cronómetro van juntos,
       * así que «#131» y «#1310» se confunden.
       */
      aria-label={`Comanda #${ticket.number}`}
      /*
       * Trancada ⇒ el BORDE ENTERO en rojo, no un iconito.
       *
       * Se tiene que ver desde la plancha, de reojo, sin dejar lo que se está
       * haciendo. Un punto de color en una esquina no lo ve nadie.
       */
      className={`rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-3 ${
        late ? 'border-2 border-bad-500' : 'border border-[var(--surface-border)]'
      }`}
    >
      <header className="flex items-baseline justify-between gap-2">
        {/* El número en grande: es lo que se grita en el mostrador. */}
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

            {/* Los agregados en línea propia y en ámbar: son justo lo que se
                pasa por alto al leer rápido, y pasarlos por alto es rehacer el
                plato. */}
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
          // 64 px de alto y todo el ancho: se toca con el dorso de la mano,
          // con guantes y sin mirar.
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

/** Entero tal cual; decimal con coma, que es como se escribe aquí. */
function formatQuantity(quantity: number): string {
  return Number.isInteger(quantity) ? String(quantity) : quantity.toFixed(3).replace('.', ',')
}
