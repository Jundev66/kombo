import { Badge, Button, Card, EmptyState, Money, Spinner, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { deliveries, type Delivery } from '../api/deliveries'

/**
 * The deliveries, for whoever makes them.
 *
 * Looked at on the bike, one-handed and in a hurry. Hence big buttons, one
 * action per card, and the address and phone number large — because the thing
 * most often done on a delivery is calling to ask where it is.
 *
 * It refreshes every 15 seconds, not every 5 like the kitchen: the urgency is
 * lower here and mobile data costs money.
 */
export function DeliveriesScreen() {
  const queryClient = useQueryClient()

  const list = useQuery({
    queryKey: ['deliveries'],
    queryFn: deliveries.list,
    refetchInterval: 15_000,
  })

  const invalidate = (): void => {
    void queryClient.invalidateQueries({ queryKey: ['deliveries'] })
  }

  const take = useMutation({ mutationFn: (id: string) => deliveries.take(id), onSuccess: invalidate })
  const release = useMutation({ mutationFn: (id: string) => deliveries.release(id), onSuccess: invalidate })
  const advance = useMutation({
    mutationFn: ({ id, status }: { id: string; status: 'out_for_delivery' | 'delivered' }) =>
      deliveries.advance(id, status),
    onSuccess: invalidate,
  })

  if (list.isLoading) return <Spinner />

  const mine = list.data?.filter((entrega) => entrega.isMine) ?? []
  const freeOnes = list.data?.filter((entrega) => !entrega.isMine) ?? []

  return (
    <Page width="board" className="flex flex-col gap-6">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Entregas</h1>

      <section className="flex flex-col gap-3">
        <h2 className="font-semibold text-[var(--text-strong)]">Lo que llevo yo</h2>

        {mine.length === 0 && (
          <p className="text-sm text-[var(--text-muted)]">Ahora mismo no llevas nada.</p>
        )}

        {mine.map((entrega) => (
          <DeliveryCard
            key={entrega.id}
            entrega={entrega}
            action={
              entrega.status === 'ready' ? (
                <Button
                  size="touch"
                  block
                  onClick={() => advance.mutate({ id: entrega.id, status: 'out_for_delivery' })}
                >
                  Salgo con él
                </Button>
              ) : (
                <Button
                  size="touch"
                  block
                  onClick={() => advance.mutate({ id: entrega.id, status: 'delivered' })}
                >
                  Entregado
                </Button>
              )
            }
            secondary={
              entrega.status === 'ready' ? (
                <Button variant="ghost" block onClick={() => release.mutate(entrega.id)}>
                  Soltar
                </Button>
              ) : null
            }
          />
        ))}
      </section>

      <section className="flex flex-col gap-3">
        <h2 className="font-semibold text-[var(--text-strong)]">Listos para salir</h2>

        {freeOnes.length === 0 && (
          <EmptyState title="No hay nada esperando" description="Cuando la cocina marque algo listo, aparece aquí." />
        )}

        {freeOnes.map((entrega) => (
          <DeliveryCard
            key={entrega.id}
            entrega={entrega}
            action={
              <Button size="touch" block onClick={() => take.mutate(entrega.id)}>
                Lo llevo yo
              </Button>
            }
          />
        ))}
      </section>
    </Page>
  )
}

function DeliveryCard({
  entrega,
  action,
  secondary,
}: {
  entrega: Delivery
  action: React.ReactNode
  secondary?: React.ReactNode
}) {
  return (
    <Card className="flex flex-col gap-3 p-4">
      <div className="flex flex-wrap items-center gap-3">
        <span className="tabular text-xl font-bold text-[var(--text-strong)]">
          #{entrega.number}
        </span>

        {entrega.zoneName != null && <Badge>{entrega.zoneName}</Badge>}

        <div className="flex-1" />

        {/* What to collect on arrival. The only thing the courier needs to know
            about the money, which is why it is large. */}
        {entrega.toCollectCents > 0 ? (
          <span className="text-right">
            <span className="block text-xs text-[var(--text-muted)]">Cobrar</span>
            <Money cents={entrega.toCollectCents} scale="md" />
          </span>
        ) : (
          <Badge tone="ok">Ya pagó</Badge>
        )}
      </div>

      <div>
        <p className="font-medium text-[var(--text-strong)]">{entrega.customerName}</p>
        <p className="text-[var(--text-default)]">{entrega.address}</p>

        {/* Calling is what happens most on a delivery: a link, so it dials with
            one tap. */}
        {entrega.customerPhone != null && (
          <a
            href={`tel:${entrega.customerPhone}`}
            className="tabular mt-1 inline-block font-medium text-accent-600"
          >
            {entrega.customerPhone}
          </a>
        )}
      </div>

      {action}
      {secondary}
    </Card>
  )
}
