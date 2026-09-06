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
 * Who buys.
 *
 * Ordered by the last thing they ordered rather than alphabetically: the
 * question here is "who comes back often?", not "where is González?".
 *
 * The record fills itself in with every order. The only thing written by hand
 * is the note — "no onion for them", "always pays cash" — which is what makes
 * the book worth having.
 */
export function CustomersScreen() {
  const [search, setSearch] = useState('')
  const [openNow, setOpenNow] = useState<string | null>(null)

  const { capabilities } = useSession()
  const timezone = capabilities?.tenant?.timezone ?? 'America/Caracas'

  // Paginated like the menu: the server capped at a hundred and the screen had
  // no way to know, or to reach the rest.
  const list = useInfiniteQuery({
    queryKey: ['customers', search],
    queryFn: ({ pageParam }) => customers.list(search, pageParam),
    initialPageParam: 1,
    getNextPageParam: (last) => (hasMore(last.meta) ? last.meta.page + 1 : undefined),
  })

  const visible = list.data?.pages.flatMap((p) => p.data) ?? []
  const total = list.data?.pages[0]?.meta.total ?? 0
  const searching = search.trim() !== ''

  if (openNow !== null) {
    return <CustomerDetail id={openNow} onBack={() => setOpenNow(null)} />
  }

  return (
    <Page width="board" className="flex flex-col gap-4">
      <div className="flex items-center gap-3">
        <h1 className="text-xl font-bold text-[var(--text-strong)]">Clientes</h1>
        {total > 0 && <Badge>{plural(total, 'cliente', 'clientes')}</Badge>}
      </div>

      <Field label="Buscar" hint="Por nombre, o por el número completo.">
        {({ id }) => (
          <Input
            id={id}
            type="search"
            value={search}
            placeholder="Ana, o 04141234567"
            onChange={(e) => setSearch(e.target.value)}
          />
        )}
      </Field>

      {list.isLoading && <Spinner />}

      {visible.length === 0 &&
        !list.isLoading &&
        (searching ? (
          <EmptyState
            title={`Nadie que coincida con «${search.trim()}»`}
            description="El teléfono se busca completo: está cifrado, así que por trozos no se puede."
          />
        ) : (
          <EmptyState
            title="Todavía no hay clientes"
            description="La ficha se llena sola con cada pedido que traiga un teléfono."
          />
        ))}

      <CardGrid>
        {visible.map((customer) => (
          <li key={customer.id}>
            <Card className="flex items-center gap-3 p-4">
              <button
                type="button"
                onClick={() => setOpenNow(customer.id)}
                className="min-w-0 flex-1 text-left"
              >
                <p className="font-medium text-[var(--text-strong)]">
                  {customer.name ?? 'Sin nombre'}
                </p>
                <p className="text-sm text-[var(--text-muted)]">
                  {customer.phone}
                  {/* The list is ordered by this and did not show it, so the order looked
                      arbitrary. And "how long since they came" is half the
                      reason this screen gets opened. */}
                  {customer.lastOrderAt != null && ` · ${orderDate(customer.lastOrderAt, timezone)}`}
                </p>
              </button>

              <Badge>{plural(customer.ordersCount, 'pedido', 'pedidos')}</Badge>

              <Money cents={customer.spentCents} />
            </Card>
          </li>
        ))}
      </CardGrid>

      <ListFooter
        shown={visible.length}
        total={total}
        noun="clientes"
        loading={list.isFetchingNextPage}
        onMore={() => void list.fetchNextPage()}
      />
    </Page>
  )
}

function CustomerDetail({ id, onBack }: { id: string; onBack: () => void }) {
  const queryClient = useQueryClient()
  const record = useQuery({ queryKey: ['customer', id], queryFn: () => customers.one(id) })
  const [notas, setNotas] = useState<string | null>(null)

  // The TENANT's timezone, not the browser's: an owner opening the dashboard
  // abroad would see last night's order dated today.
  const { capabilities } = useSession()
  const timezone = capabilities?.tenant?.timezone ?? 'America/Caracas'

  const save = useMutation({
    mutationFn: (text: string) => customers.saveNotes(id, text),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['customer', id] })
      void queryClient.invalidateQueries({ queryKey: ['customers'] })
    },
  })

  if (record.isLoading) return <Spinner />
  if (record.data === undefined) return null

  const customer = record.data

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
          {customer.name ?? 'Sin nombre'}
        </h1>
        <p className="text-sm text-[var(--text-muted)]">{customer.phone}</p>
      </div>

      <Card className="flex flex-wrap items-center gap-4 p-4">
        <div>
          <p className="text-sm text-[var(--text-muted)]">Ha pedido</p>
          {/* "1 vez", not "1 veces". One line, and a grammar mistake on the
              owner's screen tells them how much care went into the rest. */}
          <p className="tabular text-money font-semibold">
            {plural(customer.ordersCount, 'vez', 'veces')}
          </p>
        </div>

        <div>
          <p className="text-sm text-[var(--text-muted)]">Ha gastado</p>
          <Money cents={customer.spentCents} scale="md" />
        </div>
      </Card>

      <Card className="flex flex-col gap-3 p-4">
        <Field label="Nota" hint="«No le pongan cebolla», «paga siempre en efectivo».">
          {({ id: fieldId }) => (
            <Textarea
              id={fieldId}
              value={notas ?? customer.notes ?? ''}
              onChange={(e) => setNotas(e.target.value)}
            />
          )}
        </Field>

        <Button
          className="self-start"
          disabled={save.isPending || notas === null}
          onClick={() => save.mutate(notas ?? '')}
        >
          {save.isPending ? 'Guardando…' : 'Guardar la nota'}
        </Button>
      </Card>

      <Card className="flex flex-col gap-2 p-4">
        <h2 className="font-semibold text-[var(--text-strong)]">
          {/*
           * "The last 30 of 47", not a bare "What they have ordered".
           *
           * The server brings thirty while the card above says "Ordered 47
           * times": the two numbers contradicted each other on sight. It is not
           * paginated — thirty is a reasonable history — what was missing was
           * saying so.
           */}
          {customer.orders.length < customer.ordersCount
            ? `Los ${customer.orders.length} últimos de ${customer.ordersCount}`
            : 'Lo que ha pedido'}
        </h2>

        {customer.orders.length === 0 ? (
          <p className="text-sm text-[var(--text-muted)]">
            Todavía no ha pedido nada con este número.
          </p>
        ) : (
          <ul className="flex flex-col">
            {customer.orders.map((order) => (
              <li key={order.id}>
                {/*
                 * Each order OPENS. The id already came in the response and was
                 * thrown away, so seeing what last month's order contained
                 * meant hunting the number by hand on the board.
                 */}
                <Link
                  to={`/pedidos/${order.id}`}
                  className="flex min-h-touch items-center gap-3 border-b border-[var(--surface-hairline)] py-2 last:border-0"
                >
                  <div className="min-w-0 flex-1">
                    <p className="flex items-center gap-2">
                      <span className="tabular font-medium text-[var(--text-strong)]">
                        #{order.number}
                      </span>
                      <Badge tone={statusTone(order.status)}>{order.statusLabel}</Badge>
                    </p>

                    {/* Date and channel. A list of numbers with no date is not a history,
                        and which door each order came in through is half the
                        answer to "where do my customers come from?". */}
                    <p className="text-xs text-[var(--text-muted)]">
                      {orderDate(order.placedAt, timezone)} · {channelLabel(order.channel)}
                    </p>
                  </div>

                  <Money cents={order.totalCents} scale="sm" />
                </Link>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
