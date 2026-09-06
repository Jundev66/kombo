import {
  Badge,
  Button,
  Card,
  CardGrid,
  EmptyState,
  Money,
  Page,
  Spinner,
  formatUsd,
} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router'
import { catalog } from '../api/catalog'
import { nextStep, orders, statusTone, waitedLabel, type Order } from '../api/orders'

/**
 * The orders board.
 *
 * It shows what is decided at a glance — number, status, how much and how long
 * ago — and one button per card: the next step. The rest is in the detail, one
 * tap away.
 *
 * Open ones first and oldest to newest, the order they have to be dealt with.
 */
export function OrdersScreen() {
  const queryClient = useQueryClient()

  const rate = useQuery({ queryKey: ['rate'], queryFn: catalog.rate })

  const board = useQuery({
    queryKey: ['orders'],
    queryFn: orders.open,
    /*
     * Refreshes every 10 s.
     *
     * An order can arrive from the portal or a bot while the owner is looking,
     * and finding out on a manual reload is finding out late. Ten seconds and
     * not one: this runs on a counter PC.
     */
    refetchInterval: 10_000,
  })

  const advance = useMutation({
    mutationFn: ({ id, status }: { id: string; status: string }) => orders.advance(id, status),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['orders'] }),
  })

  return (
    <Page width="board" className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-xl font-bold text-[var(--text-strong)]">Pedidos</h1>
        {board.data != null && <Badge>{board.data.meta.total} abiertos</Badge>}
      </div>

      {/* If there are more than fit, IT SAYS SO. Ordered oldest to newest,
          what falls off the end are the just-arrived ones — and a board that
          hides them silently is worse than a full one. */}
      {(board.data?.meta.hidden ?? 0) > 0 && (
        <p role="alert" className="rounded-[var(--radius-md)] bg-bad-50 px-3 py-2 text-sm font-medium text-bad-700">
          Hay {board.data?.meta.hidden} pedidos más que no caben aquí. Cierra los que ya
          entregaste.
        </p>
      )}

      {board.isLoading && <Spinner />}

      {board.data?.data.length === 0 && (
        <EmptyState
          title="No hay pedidos abiertos"
          description="Aquí van a aparecer solos según entren, por el portal, por WhatsApp o desde el mostrador."
        />
      )}

      {/*
       * A grid, not a column.
       *
       * With twenty-two open orders and one column only seven are visible on a
       * laptop — and the ones not seen are the ones not dealt with. On the phone
       * it stays one column: there the card IS the row.
       */}
      <CardGrid>
        {board.data?.data.map((order) => (
          <li key={order.id}>
            {/* `h-full` so cards in the same row match: one with "still owed" and
                one without left the grid with misaligned edges. */}
            <Card className="h-full p-3">
              <div className="flex h-full items-start gap-3">
                <Link to={`/pedidos/${order.id}`} className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    {/* The number large: it is what gets shouted across the counter. */}
                    <span className="tabular text-xl font-bold text-[var(--text-strong)]">
                      #{order.number}
                    </span>
                    <Badge tone={statusTone(order.status)}>{order.statusLabel}</Badge>
                  </div>

                  <p className="mt-0.5 text-xs text-[var(--text-muted)]">
                    {order.serviceTypeLabel} · {waitedLabel(order.waitingSeconds)}
                    {order.customerName != null && ` · ${order.customerName}`}
                  </p>

                  <p className="mt-1 truncate text-sm text-[var(--text-default)]">
                    {summary(order)}
                  </p>

                  {/* With the FIGURE, not just the label. What is still owed is one of
                      the first things an owner looks at, and the bare label
                      forces opening the order to learn whether it is two
                      dollars or twenty. */}
                  {order.outstandingCents > 0 && (
                    <p className="tabular mt-1 text-xs font-medium text-warn-700">
                      Falta por cobrar {formatUsd(order.outstandingCents)}
                    </p>
                  )}
                </Link>

                <div className="flex shrink-0 flex-col items-end gap-2">
                  <Money cents={order.totalCents} rate={rate.data?.rate ?? null} scale="sm" />

                  {(() => {
                    const step = nextStep(order)

                    return step === null ? null : (
                      <Button
                        size="sm"
                        disabled={advance.isPending}
                        onClick={() => advance.mutate({ id: order.id, status: step.status })}
                      >
                        {step.label}
                      </Button>
                    )
                  })()}
                </div>
              </div>
            </Card>
          </li>
        ))}
      </CardGrid>
    </Page>
  )
}

function summary(order: Order): string {
  if (order.items == null || order.items.length === 0) return '—'

  return order.items.map((item) => `${item.quantity}× ${item.name}`).join(', ')
}
