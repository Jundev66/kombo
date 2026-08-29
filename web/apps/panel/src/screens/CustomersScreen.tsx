import { Badge, Button, Card, EmptyState, Field, Input, Money, Spinner, Textarea } from '@kombo/ui'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { customers } from '../api/customers'

/**
 * Quién compra.
 *
 * Ordenada por **lo último que pidieron**, no alfabéticamente: la pregunta que
 * se hace aquí es «¿quién viene seguido?», no «¿dónde está González?».
 *
 * La ficha se llena sola con cada pedido. Lo único que se escribe a mano es la
 * nota — «no le pongan cebolla», «paga siempre en efectivo»— que es lo que
 * hace que la libreta sirva para algo.
 */
export function CustomersScreen() {
  const [buscar, setBuscar] = useState('')
  const [abierto, setAbierto] = useState<string | null>(null)

  const lista = useQuery({ queryKey: ['customers', buscar], queryFn: () => customers.list(buscar) })

  if (abierto !== null) {
    return <CustomerDetail id={abierto} onBack={() => setAbierto(null)} />
  }

  return (
    <div className="flex flex-col gap-4">
      <h1 className="text-xl font-bold text-[var(--text-strong)]">Clientes</h1>

      <Field label="Buscar" hint="Por nombre, o por el número completo.">
        {({ id }) => (
          <Input
            id={id}
            type="search"
            value={buscar}
            placeholder="Ana, o 04141234567"
            onChange={(e) => setBuscar(e.target.value)}
          />
        )}
      </Field>

      {lista.isLoading && <Spinner />}

      {lista.data?.length === 0 && (
        <EmptyState
          title="Todavía no hay clientes"
          description="La ficha se llena sola con cada pedido que traiga un teléfono."
        />
      )}

      <ul className="flex flex-col gap-2">
        {lista.data?.map((cliente) => (
          <li key={cliente.id}>
            <Card className="flex items-center gap-3 p-4">
              <button
                type="button"
                onClick={() => setAbierto(cliente.id)}
                className="min-w-0 flex-1 text-left"
              >
                <p className="font-medium text-[var(--text-strong)]">
                  {cliente.name ?? 'Sin nombre'}
                </p>
                <p className="text-sm text-[var(--text-muted)]">{cliente.phone}</p>
              </button>

              <Badge>{cliente.ordersCount} pedidos</Badge>

              <Money cents={cliente.spentCents} />
            </Card>
          </li>
        ))}
      </ul>
    </div>
  )
}

function CustomerDetail({ id, onBack }: { id: string; onBack: () => void }) {
  const queryClient = useQueryClient()
  const ficha = useQuery({ queryKey: ['customer', id], queryFn: () => customers.one(id) })
  const [notas, setNotas] = useState<string | null>(null)

  const guardar = useMutation({
    mutationFn: (texto: string) => customers.saveNotes(id, texto),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['customer', id] })
      void queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
  })

  if (ficha.isLoading) return <Spinner />
  if (ficha.data === undefined) return null

  const cliente = ficha.data

  return (
    <div className="flex flex-col gap-4">
      <button
        type="button"
        onClick={onBack}
        className="self-start text-sm text-[var(--text-muted)] underline-offset-2 hover:underline"
      >
        ‹ Todos los clientes
      </button>

      <div>
        <h1 className="text-xl font-bold text-[var(--text-strong)]">
          {cliente.name ?? 'Sin nombre'}
        </h1>
        <p className="text-sm text-[var(--text-muted)]">{cliente.phone}</p>
      </div>

      <Card className="flex flex-wrap items-center gap-4 p-4">
        <div>
          <p className="text-sm text-[var(--text-muted)]">Ha pedido</p>
          <p className="tabular text-money font-semibold">{cliente.ordersCount} veces</p>
        </div>

        <div>
          <p className="text-sm text-[var(--text-muted)]">Ha gastado</p>
          <Money cents={cliente.spentCents} scale="md" />
        </div>
      </Card>

      <Card className="flex flex-col gap-3 p-4">
        <Field label="Nota" hint="«No le pongan cebolla», «paga siempre en efectivo».">
          {({ id: fieldId }) => (
            <Textarea
              id={fieldId}
              value={notas ?? cliente.notes ?? ''}
              onChange={(e) => setNotas(e.target.value)}
            />
          )}
        </Field>

        <Button
          className="self-start"
          disabled={guardar.isPending || notas === null}
          onClick={() => guardar.mutate(notas ?? '')}
        >
          {guardar.isPending ? 'Guardando…' : 'Guardar la nota'}
        </Button>
      </Card>

      <Card className="flex flex-col gap-2 p-4">
        <h2 className="font-semibold text-[var(--text-strong)]">Lo que ha pedido</h2>

        <ul className="flex flex-col gap-1">
          {cliente.orders.map((pedido) => (
            <li key={pedido.id} className="flex justify-between gap-3 text-sm">
              <span className="text-[var(--text-default)]">
                <span className="tabular">#{pedido.number}</span> · {pedido.statusLabel}
              </span>
              <Money cents={pedido.totalCents} scale="sm" />
            </li>
          ))}
        </ul>
      </Card>
    </div>
  )
}
