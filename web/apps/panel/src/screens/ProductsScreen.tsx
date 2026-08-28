import { useQuery } from '@tanstack/react-query'
import { Badge, buttonClasses, Card, EmptyState, Input, Money, Spinner } from '@kombo/ui'
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

  const products = useQuery({
    queryKey: ['products', buscar],
    queryFn: () => catalog.products({ buscar, incluirInactivos: true }),
  })

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between gap-3">
        <h1 className="text-xl font-bold text-[var(--text-strong)]">Carta</h1>
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

      {products.data?.length === 0 && (
        <EmptyState
          title="Tu carta está vacía"
          description="Añade lo que vendes. Puedes empezar por lo que más sale y seguir después."
          action={
            <Link to="/carta/nuevo" className={buttonClasses()}>
              Añadir el primero
            </Link>
          }
        />
      )}

      <ul className="flex flex-col gap-2">
        {products.data?.map((product) => (
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
      </ul>
    </div>
  )
}
