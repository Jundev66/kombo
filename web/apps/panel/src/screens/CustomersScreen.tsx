import { hasMore } from '@kombo/api-client'
import { useSession } from '@kombo/shell'
import {
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  Input,
  ListFooter,
  Money,
  plural,
  Spinner,
  Textarea, Page, CardGrid
} from '@kombo/ui'
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Link } from 'react-router'
import { customers } from '../api/customers'
import { channelLabel, orderDate, statusTone } from '../api/orders'

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

  const { capabilities } = useSession()
  const timezone = capabilities?.tenant?.timezone ?? 'America/Caracas'

  // Por páginas, igual que la carta: el servidor cortaba en cien y la pantalla
  // no tenía forma de saberlo ni de llegar al resto.
  const lista = useInfiniteQuery({
    queryKey: ['customers', buscar],
    queryFn: ({ pageParam }) => customers.list(buscar, pageParam),
    initialPageParam: 1,
    getNextPageParam: (last) => (hasMore(last.meta) ? last.meta.page + 1 : undefined),
  })

  const visibles = lista.data?.pages.flatMap((p) => p.data) ?? []
  const total = lista.data?.pages[0]?.meta.total ?? 0
  const buscando = buscar.trim() !== ''

  if (abierto !== null) {
    return <CustomerDetail id={abierto} onBack={() => setAbierto(null)} />
  }

  return (
    <Page ancho="tablero" className="flex flex-col gap-4">
      <div className="flex items-center gap-3">
        <h1 className="text-xl font-bold text-[var(--text-strong)]">Clientes</h1>
        {total > 0 && <Badge>{plural(total, 'cliente', 'clientes')}</Badge>}
      </div>

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

      {visibles.length === 0 &&
        !lista.isLoading &&
        (buscando ? (
          <EmptyState
            title={`Nadie que coincida con «${buscar.trim()}»`}
            description="El teléfono se busca completo: está cifrado, así que por trozos no se puede."
          />
        ) : (
          <EmptyState
            title="Todavía no hay clientes"
            description="La ficha se llena sola con cada pedido que traiga un teléfono."
          />
        ))}

      <CardGrid>
        {visibles.map((cliente) => (
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
                <p className="text-sm text-[var(--text-muted)]">
                  {cliente.phone}
                  {/* La lista se ordena por esto y no lo enseñaba, así que el
                      orden parecía arbitrario. Y «hace cuánto que no viene» es
                      media respuesta a por qué se mira esta pantalla. */}
                  {cliente.lastOrderAt != null && ` · ${orderDate(cliente.lastOrderAt, timezone)}`}
                </p>
              </button>

              <Badge>{plural(cliente.ordersCount, 'pedido', 'pedidos')}</Badge>

              <Money cents={cliente.spentCents} />
            </Card>
          </li>
        ))}
      </CardGrid>

      <ListFooter
        shown={visibles.length}
        total={total}
        noun="clientes"
        loading={lista.isFetchingNextPage}
        onMore={() => void lista.fetchNextPage()}
      />
    </Page>
  )
}

function CustomerDetail({ id, onBack }: { id: string; onBack: () => void }) {
  const queryClient = useQueryClient()
  const ficha = useQuery({ queryKey: ['customer', id], queryFn: () => customers.one(id) })
  const [notas, setNotas] = useState<string | null>(null)

  // El huso DEL NEGOCIO, no el del navegador: un dueño que abre el panel de
  // viaje vería el pedido de anoche fechado hoy.
  const { capabilities } = useSession()
  const timezone = capabilities?.tenant?.timezone ?? 'America/Caracas'

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
          {/* «1 vez», no «1 veces». Es una línea, y una falta de ortografía en
              la pantalla del dueño le dice cuánto cuidado le pusimos al resto. */}
          <p className="tabular text-money font-semibold">
            {plural(cliente.ordersCount, 'vez', 'veces')}
          </p>
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
        <h2 className="font-semibold text-[var(--text-strong)]">
          {/*
           * «Los 30 últimos de 47», no «Lo que ha pedido» a secas.
           *
           * El servidor trae treinta y la tarjeta de arriba dice «Ha pedido 47
           * veces»: los dos números se contradecían a la vista y nada explicaba
           * cuál creer. No se pagina —treinta es un histórico razonable de
           * mirar y `ordersCount` ya viaja en la misma respuesta—; lo que
           * faltaba era decirlo.
           */}
          {cliente.orders.length < cliente.ordersCount
            ? `Los ${cliente.orders.length} últimos de ${cliente.ordersCount}`
            : 'Lo que ha pedido'}
        </h2>

        {cliente.orders.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">
            Todavía no ha pedido nada con este número.
          </p>
        ) : (
          <ul className="flex flex-col">
            {cliente.orders.map((pedido) => (
              <li key={pedido.id}>
                {/*
                 * Cada pedido ABRE. El identificador ya venía en la respuesta y
                 * se tiraba, así que ver qué llevaba aquel pedido de hace un mes
                 * obligaba a buscar el número a mano en el tablero.
                 */}
                <Link
                  to={`/pedidos/${pedido.id}`}
                  className="flex min-h-touch items-center gap-3 border-b border-[var(--surface-hairline)] py-2 last:border-0"
                >
                  <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-2">
                      <span className="tabular font-medium text-[var(--text-strong)]">
                        #{pedido.number}
                      </span>
                      <Badge tone={statusTone(pedido.status)}>{pedido.statusLabel}</Badge>
                    </p>

                    {/* Fecha y canal. Una lista de números sin fecha no es un
                        histórico, y por dónde entró cada pedido es media
                        respuesta a «¿de dónde me viene la gente?». */}
                    <p className="text-xs text-[var(--text-muted)]">
                      {orderDate(pedido.placedAt, timezone)} · {channelLabel(pedido.channel)}
                    </p>
                  </div>

                  <Money cents={pedido.totalCents} scale="sm" />
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
