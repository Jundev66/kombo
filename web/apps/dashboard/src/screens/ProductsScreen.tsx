import { hasMore } from '@kombo/api-client'
import { useInfiniteQuery, useQuery } from '@tanstack/react-query'
import {
  Badge,
  buttonClasses,
  Card,
  EmptyState,
  Input,
  ListFooter,
  Money,
  plural,
  Spinner, Page, CardGrid
} from '@kombo/ui'
import { useState } from 'react'
import { Link } from 'react-router'
import { catalog } from '../api/catalog'

/**
 * The menu.
 *
 * The most-looked-at screen in the dashboard, so it shows what is decided at a
 * glance — name, price and availability — and nothing else. Everything else is
 * one tap away.
 */
export function ProductsScreen() {
  const [search, setSearch] = useState('')

  const rate = useQuery({ queryKey: ['rate'], queryFn: catalog.rate })

  /*
   * Paginated, and accumulating.
   *
   * `useInfiniteQuery` comes with TanStack Query, so there is no new dependency
   * and no hand-kept list of what has been fetched. A menu can hold hundreds of
   * products and this runs on a counter PC: fetching them all on open would pay
   * up front for a scroll almost nobody does.
   */
  const products = useInfiniteQuery({
    queryKey: ['products', search],
    queryFn: ({ pageParam }) =>
      catalog.products({ search, includeInactive: true, page: pageParam }),
    initialPageParam: 1,
    getNextPageParam: (last) => (hasMore(last.meta) ? last.meta.page + 1 : undefined),
  })

  const visible = products.data?.pages.flatMap((p) => p.data) ?? []
  const total = products.data?.pages[0]?.meta.total ?? 0
  const searching = search.trim() !== ''

  return (
    <Page width="board" className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-xl font-bold text-[var(--text-strong)]">Carta</h1>

        {/* How many there are, at the top: the answer to "is it all loaded?",
            which is what the owner comes to check. */}
        {total > 0 && <Badge>{plural(total, 'producto', 'productos')}</Badge>}

        <div className="flex-1" />
        {/* A link, not a button: this NAVIGATES. A button with a Link inside does
            not navigate and nests two controls the keyboard cannot interpret. */}
        <Link to="/carta/nuevo" className={buttonClasses()}>
          Añadir
        </Link>
      </div>

      <Input
        type="search"
        placeholder="Buscar en la carta…"
        value={search}
        onChange={(e) => setSearch(e.target.value)}
        aria-label="Buscar en la carta"
      />

      {rate.data == null && !rate.isLoading && (
        // Without a rate there is no charging in bolívares, and that has to be said
        // before somebody discovers it with a customer waiting.
        <p role="alert" className="rounded-[var(--radius-md)] bg-warn-50 p-3 text-sm text-warn-700">
          Todavía no has cargado la tasa del día.{' '}
          <Link to="/tasa" className="font-medium underline">
            Cárgala
          </Link>
          .
        </p>
      )}

      {products.isLoading && <Spinner />}

      {/* A search with no results and an empty menu are different things, and
          until now they said the same: searching "xyz" answered "Your menu is
          empty · Add what you sell", in front of an owner with six hundred
          products loaded. */}
      {visible.length === 0 &&
        !products.isLoading &&
        (searching ? (
          <EmptyState
            title={`Nada que se llame «${search.trim()}»`}
            description="Prueba con menos letras. Aquí se busca en toda la carta, no sólo en lo que se ve."
          />
        ) : (
          <EmptyState
            title="Tu carta está vacía"
            description="Añade lo que vendes. Puedes empezar por lo que más sale y seguir después."
            action={
              <Link to="/carta/nuevo" className={buttonClasses()}>
                Añadir el primero
              </Link>
            }
          />
        ))}

      <CardGrid>
        {visible.map((product) => (
          <li key={product.id}>
            <Card>
              <Link
                to={`/carta/${product.id}`}
                className="flex min-h-touch items-center gap-3 p-3"
              >
                {product.photoUrl != null ? (
                  <img
                    src={product.photoUrl}
                    alt=""
                    className="size-14 shrink-0 rounded-[var(--radius-md)] object-cover"
                  />
                ) : (
                  <div className="size-14 shrink-0 rounded-[var(--radius-md)] bg-[var(--surface-sunken)]" />
                )}

                <div className="min-w-0 flex-1">
                  <p className="truncate font-medium text-[var(--text-strong)]">{product.name}</p>

                  <div className="mt-1 flex flex-wrap items-center gap-1.5">
                    {!product.isActive && <Badge tone="neutral">Fuera de la carta</Badge>}
                    {product.isSoldOut && <Badge tone="bad">Se acabó</Badge>}
                    {product.prepMinutes != null && (
                      <Badge>{product.prepMinutes} min</Badge>
                    )}
                  </div>
                </div>

                <Money cents={product.priceCents} rate={rate.data?.rate ?? null} scale="sm" />
              </Link>
            </Card>
          </li>
        ))}
      </CardGrid>

      <ListFooter
        shown={visible.length}
        total={total}
        noun="productos"
        loading={products.isFetchingNextPage}
        onMore={() => void products.fetchNextPage()}
      />
    </Page>
  )
}
