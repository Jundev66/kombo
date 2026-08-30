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
 * La carta.
 *
 * Es la pantalla que más se mira del panel, así que muestra lo que se decide
 * de un vistazo —nombre, precio y si está disponible— y nada más. Todo lo
 * demás está a un toque.
 */
export function ProductsScreen() {
  const [buscar, setBuscar] = useState('')

  const rate = useQuery({ queryKey: ['rate'], queryFn: catalog.rate })

  /*
   * Por páginas, y acumulando.
   *
   * `useInfiniteQuery` ya viene con TanStack Query, así que no hay dependencia
   * nueva ni que llevar a mano la lista de lo que ya se trajo. La carta puede
   * tener cientos de productos y esto corre en una PC de mostrador: traerlos
   * todos de golpe al abrir la pantalla sería pagar por adelantado un scroll
   * que casi nadie hace.
   */
  const products = useInfiniteQuery({
    queryKey: ['products', buscar],
    queryFn: ({ pageParam }) =>
      catalog.products({ buscar, incluirInactivos: true, page: pageParam }),
    initialPageParam: 1,
    getNextPageParam: (last) => (hasMore(last.meta) ? last.meta.page + 1 : undefined),
  })

  const visibles = products.data?.pages.flatMap((p) => p.data) ?? []
  const total = products.data?.pages[0]?.meta.total ?? 0
  const buscando = buscar.trim() !== ''

  return (
    <Page ancho="tablero" className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-xl font-bold text-[var(--text-strong)]">Carta</h1>

        {/* Cuántos hay, arriba: es la respuesta a «¿está todo cargado?», que es
            justo lo que el dueño viene a comprobar. */}
        {total > 0 && <Badge>{plural(total, 'producto', 'productos')}</Badge>}

        <div className="flex-1" />
        {/* Enlace, no botón: esto NAVEGA. Un botón con un Link dentro no
            navega y anida dos controles que el teclado no sabe interpretar. */}
        <Link to="/carta/nuevo" className={buttonClasses()}>
          Añadir
        </Link>
      </div>

      <Input
        type="search"
        placeholder="Buscar en la carta…"
        value={buscar}
        onChange={(e) => setBuscar(e.target.value)}
        aria-label="Buscar en la carta"
      />

      {rate.data == null && !rate.isLoading && (
        // Sin tasa no se puede cobrar en bolívares, y hay que decirlo antes de
        // que alguien lo descubra con un cliente delante.
        <p role="alert" className="rounded-[var(--radius-md)] bg-warn-50 p-3 text-sm text-warn-700">
          Todavía no has cargado la tasa del día.{' '}
          <Link to="/tasa" className="font-medium underline">
            Cárgala
          </Link>
          .
        </p>
      )}

      {products.isLoading && <Spinner />}

      {/* Buscar sin resultados y no tener carta son cosas distintas, y hasta
          ahora decían lo mismo: buscar «xyz» contestaba «Tu carta está vacía ·
          Añade lo que vendes» con un botón de añadir el primero, delante de un
          dueño con seiscientos productos cargados. */}
      {visibles.length === 0 &&
        !products.isLoading &&
        (buscando ? (
          <EmptyState
            title={`Nada que se llame «${buscar.trim()}»`}
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
        {visibles.map((product) => (
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
        shown={visibles.length}
        total={total}
        noun="productos"
        loading={products.isFetchingNextPage}
        onMore={() => void products.fetchNextPage()}
      />
    </Page>
  )
}
