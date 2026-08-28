import { Badge, Button, Card, EmptyState, Money, Spinner } from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router'
import { catalog } from '../api/catalog'
import { nextStep, orders, waitedLabel, type Order } from '../api/orders'

/**
 * El tablero de pedidos.
 *
 * Muestra lo que se decide de un vistazo —número, estado, cuánto y desde hace
 * cuánto— y un solo botón por tarjeta: el siguiente paso. Lo demás está en el
 * detalle, a un toque.
 *
 * Los abiertos primero y del más viejo al más nuevo, que es el orden en el que
 * hay que atenderlos.
 */
export function OrdersScreen() {
  const queryClient = useQueryClient()

  const rate = useQuery({ queryKey: ['rate'], queryFn: catalog.rate })

  const board = useQuery({
    queryKey: ['orders'],
    queryFn: orders.open,
    /*
     * Se refresca solo cada 10 s.
     *
     * Un pedido puede entrar por el portal o por un bot mientras el dueño mira
     * la pantalla, y enterarse al recargar a mano es enterarse tarde. Diez
     * segundos y no uno: esto corre en una PC de mostrador, y consultar cada
     * segundo la calienta sin que nadie note la diferencia.
     */
    refetchInterval: 10_000,
  })

  const avanzar = useMutation({
    mutationFn: ({ id, status }: { id: string; status: string }) => orders.advance(id, status),
    onSuccess: () => void queryClient.invalidateQueries({ queryKey: ['orders'] }),
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-xl font-bold text-[var(--text-strong)]">Pedidos</h1>
        {board.data != null && <Badge>{board.data.meta.total} abiertos</Badge>}
      </div>

      {/* Si hay más de los que caben, SE DICE. Como el orden va del más viejo
          al más nuevo, lo que se queda fuera son los recién entrados — y un
          tablero que los esconde en silencio es peor que uno lleno. */}
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

      <ul className="flex flex-col gap-2">
        {board.data?.data.map((order) => (
          <li key={order.id}>
            <Card className="p-3">
              <div className="flex items-start gap-3">
                <Link to={`/pedidos/${order.id}`} className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    {/* El número en grande: es lo que se grita en el mostrador. */}
                    <span className="tabular text-xl font-bold text-[var(--text-strong)]">
                      #{order.number}
                    </span>
                    <Badge tone={toneFor(order)}>{order.statusLabel}</Badge>
                  </div>

                  <p className="mt-0.5 text-xs text-[var(--text-muted)]">
                    {order.serviceTypeLabel} · {waitedLabel(order.waitingSeconds)}
                    {order.customerName != null && ` · ${order.customerName}`}
                  </p>

                  <p className="mt-1 truncate text-sm text-[var(--text-default)]">
                    {resumen(order)}
                  </p>

                  {order.outstandingCents > 0 && (
                    <p className="mt-1 text-xs font-medium text-warn-700">
                      Falta por cobrar
                    </p>
                  )}
                </Link>

                <div className="flex shrink-0 flex-col items-end gap-2">
                  <Money cents={order.totalCents} rate={rate.data?.rate ?? null} scale="sm" />

                  {(() => {
                    const paso = nextStep(order)

                    return paso === null ? null : (
                      <Button
                        size="sm"
                        disabled={avanzar.isPending}
                        onClick={() => avanzar.mutate({ id: order.id, status: paso.status })}
                      >
                        {paso.label}
                      </Button>
                    )
                  })()}
                </div>
              </div>
            </Card>
          </li>
        ))}
      </ul>
    </div>
  )
}

/**
 * Un pedido sin confirmar lleva ámbar: es el único estado en el que el sistema
 * está esperando a una persona, y un pedido olvidado veinte minutos es un
 * cliente perdido.
 */
function toneFor(order: Order): 'neutral' | 'warn' | 'ok' {
  if (order.status === 'placed' || order.status === 'pending_payment') return 'warn'
  if (order.status === 'ready') return 'ok'

  return 'neutral'
}

function resumen(order: Order): string {
  if (order.items == null || order.items.length === 0) return '—'

  return order.items.map((item) => `${item.quantity}× ${item.name}`).join(', ')
}
