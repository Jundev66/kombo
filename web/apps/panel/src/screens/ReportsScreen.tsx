import { Card, EmptyState, Money, Spinner, buttonClasses, formatUsd } from '@kombo/ui'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { paymentLabel } from '../api/orders'
import { CHANNEL_LABELS, PERIODS, reports, type Period, type SalesReport } from '../api/reports'

/**
 * Qué vendiste.
 *
 * La pantalla contesta cuatro preguntas y ninguna más, porque son las que un
 * dueño de comida se hace de verdad: **cuánto vendí**, **qué se vende más**,
 * **a qué hora entra la gente** y **cómo me pagan**.
 *
 * Nada de gráficos de tarta ni de comparativas contra el año pasado: esto se
 * abre en un teléfono, de pie, entre dos pedidos.
 */
export function ReportsScreen() {
  const [periodo, setPeriodo] = useState<Period>('hoy')

  const reporte = useQuery({
    queryKey: ['reports', periodo],
    queryFn: () => reports.sales(periodo),
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="flex-1 text-xl font-bold text-[var(--text-strong)]">Ventas</h1>

        {/* Un enlace y no un botón: lleva a otro sitio —a un archivo— y meter
            un `fetch` por medio sería bajarlo a memoria para volver a
            ofrecerlo. */}
        <a
          href={reports.exportUrl(periodo)}
          className={buttonClasses('secondary', 'md')}
          download
        >
          Exportar
        </a>
      </div>

      <nav aria-label="Período" className="flex gap-2 overflow-x-auto">
        {PERIODS.map((opcion) => (
          <button
            key={opcion.value}
            type="button"
            aria-pressed={periodo === opcion.value}
            onClick={() => setPeriodo(opcion.value)}
            className={`min-h-11 shrink-0 rounded-full px-4 text-sm font-medium ${
              periodo === opcion.value
                ? 'bg-accent-500 text-white'
                : 'bg-[var(--surface-raised)] text-[var(--text-default)]'
            }`}
          >
            {opcion.label}
          </button>
        ))}
      </nav>

      {reporte.isLoading && <Spinner />}

      {reporte.data !== undefined && <Report data={reporte.data} />}
    </div>
  )
}

function Report({ data }: { data: SalesReport }) {
  const { summary } = data

  if (summary.orders === 0) {
    return (
      <EmptyState
        title="Todavía no hay ventas en este período"
        description="Cuando entre el primer pedido confirmado aparece aquí."
      />
    )
  }

  return (
    <div className="flex flex-col gap-4">
      {/* El número es el protagonista: lo vendido, en la mayor escala de la
          pantalla. Es la cifra que se viene a buscar. */}
      <Card className="flex flex-col gap-3 p-4">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="text-sm text-[var(--text-muted)]">Vendido</p>
            <Money cents={summary.soldCents} scale="xl" />
          </div>

          <div className="text-right">
            <p className="text-sm text-[var(--text-muted)]">{summary.orders} pedidos</p>
            <p className="tabular text-sm text-[var(--text-muted)]">
              {formatUsd(summary.averageTicketCents)} de promedio
            </p>
          </div>
        </div>

        {/* Vendido y cobrado no son lo mismo, y la diferencia es lo que falta
            por cobrar. Se dice sólo cuando hay algo pendiente: un renglón en
            cero todos los días deja de leerse. */}
        {summary.outstandingCents > 0 && (
          <p className="flex justify-between border-t border-[var(--surface-border)] pt-3 text-sm">
            <span className="text-[var(--text-muted)]">Falta por cobrar</span>
            <span className="tabular font-medium text-warn-700">
              {formatUsd(summary.outstandingCents)}
            </span>
          </p>
        )}

        {summary.cancelled > 0 && (
          <p className="text-sm text-[var(--text-muted)]">
            {summary.cancelled} {summary.cancelled === 1 ? 'cancelado' : 'cancelados'}
          </p>
        )}
      </Card>

      {data.byProduct.length > 0 && (
        <Card className="flex flex-col gap-2 p-4">
          <h2 className="font-semibold text-[var(--text-strong)]">Lo que más se vende</h2>

          <ul className="flex flex-col gap-1">
            {data.byProduct.map((producto) => (
              <li key={producto.name} className="flex justify-between gap-3 text-sm">
                <span className="min-w-0 flex-1 truncate text-[var(--text-default)]">
                  <span className="tabular text-[var(--text-muted)]">{producto.quantity}×</span>{' '}
                  {producto.name}
                </span>
                <span className="tabular">{formatUsd(producto.totalCents)}</span>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <HourChart hours={data.byHour} />

      <div className="grid gap-4 sm:grid-cols-2">
        {data.byPaymentMethod.length > 0 && (
          <Card className="flex flex-col gap-2 p-4">
            <h2 className="font-semibold text-[var(--text-strong)]">Cómo pagaron</h2>

            <ul className="flex flex-col gap-1">
              {data.byPaymentMethod.map((metodo) => (
                <li key={metodo.method} className="flex justify-between gap-3 text-sm">
                  <span className="text-[var(--text-default)]">{paymentLabel(metodo.method)}</span>
                  <span className="tabular">{formatUsd(metodo.totalCents)}</span>
                </li>
              ))}
            </ul>
          </Card>
        )}

        {data.byChannel.length > 0 && (
          <Card className="flex flex-col gap-2 p-4">
            <h2 className="font-semibold text-[var(--text-strong)]">Por dónde entraron</h2>

            <ul className="flex flex-col gap-1">
              {data.byChannel.map((canal) => (
                <li key={canal.channel} className="flex justify-between gap-3 text-sm">
                  <span className="text-[var(--text-default)]">
                    {CHANNEL_LABELS[canal.channel] ?? canal.channel}
                  </span>
                  <span className="tabular">
                    {canal.orders} · {formatUsd(canal.totalCents)}
                  </span>
                </li>
              ))}
            </ul>
          </Card>
        )}
      </div>
    </div>
  )
}

/**
 * La hora pico, con barras.
 *
 * Con barras y no con una tabla de 24 filas: lo que se viene a buscar aquí no
 * es un número, es una **forma** — a qué hora se llena. Eso se ve de un
 * vistazo o no sirve de nada.
 *
 * Se dibuja con divs y no con una librería de gráficos: son 24 barras, y la
 * librería más pequeña son 40 KB que paga el teléfono de alguien con datos
 * contados.
 */
function HourChart({ hours }: { hours: SalesReport['byHour'] }) {
  const maximo = Math.max(...hours.map((h) => h.totalCents), 1)
  const pico = hours.reduce((mejor, hora) => (hora.totalCents > mejor.totalCents ? hora : mejor))

  // Las horas muertas de la madrugada no aportan nada y se comen el ancho.
  const visibles = hours.filter((h) => h.hour >= 6)

  return (
    <Card className="flex flex-col gap-3 p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-semibold text-[var(--text-strong)]">A qué hora</h2>

        {pico.totalCents > 0 && (
          <p className="text-sm text-[var(--text-muted)]">
            La hora fuerte:{' '}
            <strong className="tabular text-[var(--text-strong)]">
              {String(pico.hour).padStart(2, '0')}:00
            </strong>
          </p>
        )}
      </div>

      <div className="flex h-28 items-end gap-0.5">
        {visibles.map((hora) => (
          <div
            key={hora.hour}
            className="flex flex-1 flex-col items-center justify-end gap-1"
            // El título nativo: en un teléfono no hay hover, pero en el
            // escritorio del local sí, y es gratis.
            title={`${String(hora.hour).padStart(2, '0')}:00 · ${hora.orders} pedidos`}
          >
            <div
              className={`w-full rounded-t-sm ${
                hora.hour === pico.hour && hora.totalCents > 0 ? 'bg-accent-500' : 'bg-accent-200'
              }`}
              style={{ height: `${Math.max(2, (hora.totalCents / maximo) * 100)}%` }}
            />
          </div>
        ))}
      </div>

      {/* Sólo algunas etiquetas: 18 números de dos cifras en el ancho de un
          teléfono no se leen. */}
      <div className="flex justify-between text-xs text-[var(--text-muted)]">
        <span>6h</span>
        <span>12h</span>
        <span>18h</span>
        <span>23h</span>
      </div>
    </Card>
  )
}
