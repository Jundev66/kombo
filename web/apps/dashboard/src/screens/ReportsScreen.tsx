import { Card, EmptyState, Money, plural, Spinner, buttonClasses, formatUsd, Page} from '@kombo/ui'
import { useQuery } from '@tanstack/react-query'
import { useState } from 'react'
import { channelLabel, paymentLabel } from '../api/orders'
import { PERIODS, reports, type Period, type SalesReport } from '../api/reports'

/**
 * What you sold.
 *
 * Four questions and no more, because they are the ones a food business owner
 * actually asks: how much did I sell, what sells most, what time do people come
 * in, and how do they pay me.
 *
 * No pie charts and no year-on-year comparisons: this is opened on a phone,
 * standing up, between two orders.
 */
export function ReportsScreen() {
  const [period, setPeriod] = useState<Period>('today')

  const report = useQuery({
    queryKey: ['reports', period],
    queryFn: () => reports.sales(period),
  })

  return (
    <Page width="board" className="flex flex-col gap-4">
      <div className="flex flex-wrap items-center gap-3">
        <h1 className="flex-1 text-xl font-bold text-[var(--text-strong)]">Ventas</h1>

        {/* A link and not a button: it leads somewhere — to a file — and putting
            a `fetch` in the way would pull it into memory to offer it again. */}
        <a
          href={reports.exportUrl(period)}
          className={buttonClasses('secondary', 'md')}
          download
        >
          Exportar
        </a>
      </div>

      <nav aria-label="Período" className="flex gap-2 overflow-x-auto">
        {PERIODS.map((option) => (
          <button
            key={option.value}
            type="button"
            aria-pressed={period === option.value}
            onClick={() => setPeriod(option.value)}
            className={`min-h-11 shrink-0 rounded-full px-4 text-sm font-medium ${
              period === option.value
                ? 'bg-accent-500 text-white'
                : 'bg-[var(--surface-raised)] text-[var(--text-default)]'
            }`}
          >
            {option.label}
          </button>
        ))}
      </nav>

      {report.isLoading && <Spinner />}

      {report.data !== undefined && <Report data={report.data} />}
    </Page>
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
      {/* The number is the protagonist: what was sold, in the screen's largest
          scale. It is the figure people come for. */}
      <Card className="flex flex-col gap-3 p-4">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <p className="text-sm text-[var(--text-muted)]">Vendido</p>
            <Money cents={summary.soldCents} scale="xl" />
          </div>

          <div className="text-right">
            <p className="text-sm text-[var(--text-muted)]">{plural(summary.orders, 'pedido', 'pedidos')}</p>
            <p className="tabular text-sm text-[var(--text-muted)]">
              {formatUsd(summary.averageTicketCents)} de promedio
            </p>
          </div>
        </div>

        {/* Sold and collected are not the same, and the difference is what is
            still owed. Shown only when there is something pending: a zero line
            every day stops being read. */}
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
            {plural(summary.cancelled, 'cancelado', 'cancelados')}
          </p>
        )}
      </Card>

      {data.byProduct.length > 0 && (
        <Card className="flex flex-col gap-2 p-4">
          <h2 className="font-semibold text-[var(--text-strong)]">Lo que más se vende</h2>

          <ul className="flex flex-col gap-1">
            {data.byProduct.map((product) => (
              <li key={product.name} className="flex justify-between gap-3 text-sm">
                <span className="min-w-0 flex-1 truncate text-[var(--text-default)]">
                  <span className="tabular text-[var(--text-muted)]">{product.quantity}×</span>{' '}
                  {product.name}
                </span>
                <span className="tabular">{formatUsd(product.totalCents)}</span>
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
              {data.byPaymentMethod.map((method) => (
                <li key={method.method} className="flex justify-between gap-3 text-sm">
                  <span className="text-[var(--text-default)]">{paymentLabel(method.method)}</span>
                  <span className="tabular">{formatUsd(method.totalCents)}</span>
                </li>
              ))}
            </ul>
          </Card>
        )}

        {data.byChannel.length > 0 && (
          <Card className="flex flex-col gap-2 p-4">
            <h2 className="font-semibold text-[var(--text-strong)]">Por dónde entraron</h2>

            <ul className="flex flex-col gap-1">
              {data.byChannel.map((channel) => (
                <li key={channel.channel} className="flex justify-between gap-3 text-sm">
                  <span className="text-[var(--text-default)]">
                    {channelLabel(channel.channel)}
                  </span>
                  <span className="tabular">
                    {channel.orders} · {formatUsd(channel.totalCents)}
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
 * The peak hour, in bars.
 *
 * Bars rather than a 24-row table: what people come here for is not a number
 * but a SHAPE — when it gets busy. That is seen at a glance or not at all.
 *
 * Drawn with divs rather than a charting library: it is 24 bars, and the
 * smallest library is 40 KB paid for by somebody's metered phone.
 */
function HourChart({ hours }: { hours: SalesReport['byHour'] }) {
  const maximum = Math.max(...hours.map((h) => h.totalCents), 1)
  const peak = hours.reduce((better, hour) => (hour.totalCents > better.totalCents ? hour : better))

  // The dead early hours add nothing and eat the width.
  const visible = hours.filter((h) => h.hour >= 6)

  return (
    <Card className="flex flex-col gap-3 p-4">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <h2 className="font-semibold text-[var(--text-strong)]">A qué hora</h2>

        {peak.totalCents > 0 && (
          <p className="text-sm text-[var(--text-muted)]">
            La hora fuerte:{' '}
            <strong className="tabular text-[var(--text-strong)]">
              {String(peak.hour).padStart(2, '0')}:00
            </strong>
          </p>
        )}
      </div>

      <div className="flex h-28 items-end gap-0.5">
        {visible.map((hour) => (
          <div
            key={hour.hour}
            className="flex flex-1 flex-col items-center justify-end gap-1"
            // The native title: a phone has no hover, but the shop's desktop does, and
            // it is free.
            title={`${String(hour.hour).padStart(2, '0')}:00 · ${plural(hour.orders, 'order', 'orders')}`}
          >
            <div
              className={`w-full rounded-t-sm ${
                hour.hour === peak.hour && hour.totalCents > 0 ? 'bg-accent-500' : 'bg-accent-200'
              }`}
              style={{ height: `${Math.max(2, (hour.totalCents / maximum) * 100)}%` }}
            />
          </div>
        ))}
      </div>

      {/* Only some labels: 18 two-digit numbers across a phone's width cannot
          be read. */}
      <div className="flex justify-between text-xs text-[var(--text-muted)]">
        <span>6h</span>
        <span>12h</span>
        <span>18h</span>
        <span>23h</span>
      </div>
    </Card>
  )
}
