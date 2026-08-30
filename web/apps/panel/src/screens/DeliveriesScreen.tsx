import { Badge, Button, Card, EmptyState, Money, Spinner, Page} from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { deliveries, type Delivery } from '../api/deliveries'

/**
 * Las entregas, para quien las lleva.
 *
 * Se mira en la moto, con una mano y con prisa. De ahí todo lo de abajo:
 * **botones grandes**, **una acción por tarjeta**, y la dirección y el teléfono
 * en grande — porque lo que más se hace en un reparto es llamar para preguntar
 * dónde es.
 *
 * Se refresca sola cada 15 segundos. No cada 5 como la cocina: aquí la
 * urgencia es menor y los datos móviles se pagan.
 */
export function DeliveriesScreen() {
  const queryClient = useQueryClient()

  const lista = useQuery({
    queryKey: ['deliveries'],
    queryFn: deliveries.list,
    refetchInterval: 15_000,
  })

  const invalidar = (): void => {
    void queryClient.invalidateQueries({ queryKey: ['deliveries'] })
  }

  const tomar = useMutation({ mutationFn: (id: string) => deliveries.take(id), onSuccess: invalidar })
  const soltar = useMutation({ mutationFn: (id: string) => deliveries.release(id), onSuccess: invalidar })
  const avanzar = useMutation({
    mutationFn: ({ id, status }: { id: string; status: 'out_for_delivery' | 'delivered' }) =>
      deliveries.advance(id, status),
    onSuccess: invalidar,
  })

  if (lista.isLoading) return <Spinner />

  const mias = lista.data?.filter((entrega) => entrega.isMine) ?? []
  const libres = lista.data?.filter((entrega) => !entrega.isMine) ?? []

  return (
    <Page ancho="tablero" className="flex flex-col gap-6">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Entregas</h1>

      <section className="flex flex-col gap-3">
        <h2 className="font-semibold text-[var(--text-strong)]">Lo que llevo yo</h2>

        {mias.length === 0 && (
          <p className="text-sm text-[var(--text-muted)]">Ahora mismo no llevas nada.</p>
        )}

        {mias.map((entrega) => (
          <DeliveryCard
            key={entrega.id}
            entrega={entrega}
            action={
              entrega.status === 'ready' ? (
                <Button
                  size="touch"
                  block
                  onClick={() => avanzar.mutate({ id: entrega.id, status: 'out_for_delivery' })}
                >
                  Salgo con él
                </Button>
              ) : (
                <Button
                  size="touch"
                  block
                  onClick={() => avanzar.mutate({ id: entrega.id, status: 'delivered' })}
                >
                  Entregado
                </Button>
              )
            }
            secondary={
              entrega.status === 'ready' ? (
                <Button variant="ghost" block onClick={() => soltar.mutate(entrega.id)}>
                  Soltar
                </Button>
              ) : null
            }
          />
        ))}
      </section>

      <section className="flex flex-col gap-3">
        <h2 className="font-semibold text-[var(--text-strong)]">Listos para salir</h2>

        {libres.length === 0 && (
          <EmptyState title="No hay nada esperando" description="Cuando la cocina marque algo listo, aparece aquí." />
        )}

        {libres.map((entrega) => (
          <DeliveryCard
            key={entrega.id}
            entrega={entrega}
            action={
              <Button size="touch" block onClick={() => tomar.mutate(entrega.id)}>
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

        {/* Lo que hay que cobrar al llegar. Es lo único que el repartidor
            necesita saber del dinero, y por eso va en grande. */}
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

        {/* Llamar es lo que más se hace en un reparto: va como enlace para que
            se marque de un toque. */}
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
