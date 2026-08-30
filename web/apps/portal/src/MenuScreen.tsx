import { Button, EmptyState, formatBs, formatUsd } from '@kombo/ui'
import { useMemo, useState } from 'react'
import { Link } from 'react-router'
import type { Menu, MenuProduct, Shop } from './api'
import type { Cart } from './cart'
import { ProductSheet } from './ProductSheet'
import { ShopHeader } from './ShopHeader'

/**
 * La carta, en el teléfono de alguien que tiene hambre.
 *
 * **Foto grande y precio grande.** No es decoración: en comida se elige con los
 * ojos, y una lista de nombres en gris vende bastante menos que la foto de la
 * arepa.
 *
 * Y una sola acción abajo, fija, siempre en el mismo sitio: ver el pedido. El
 * pulgar la alcanza sin mirar.
 */
export function MenuScreen({ shop, menu, cart }: { shop: Shop; menu: Menu; cart: Cart }) {
  const [category, setCategory] = useState<string | null>(null)
  const [chosen, setChosen] = useState<MenuProduct | null>(null)

  const visible = useMemo(
    () => menu.products.filter((p) => category === null || p.categoryId === category),
    [menu, category],
  )

  function groupsOf(product: MenuProduct): Menu['modifierGroups'] {
    return menu.modifierGroups.filter((g) => product.modifierGroupIds.includes(g.id))
  }

  return (
    <div className="flex min-h-dvh flex-col bg-[var(--surface-sunken)] pb-24">
      <ShopHeader
        shop={shop}
        subtitle={
          <p className="text-sm">
            {shop.isOpen ? (
              <span className="font-medium text-ok-700">Abierto ahora</span>
            ) : (
              // Se dice arriba del todo, antes de que arme el pedido. No al
              // final, cuando ya eligió tres cosas.
              <span className="font-medium text-bad-500">Cerrado ahora mismo</span>
            )}
            {shop.exchangeRate != null && (
              <span className="tabular text-[var(--text-muted)]">
                {' '}
                · Bs {shop.exchangeRate.toLocaleString('es-VE')}/$
              </span>
            )}
          </p>
        }
      >
        {shop.notice != null && (
          <p
            role="status"
            className="rounded-[var(--radius-md)] bg-warn-50 px-3 py-2 text-sm text-warn-700"
          >
            {shop.notice}
          </p>
        )}

        {!shop.isOpen && (
          <p className="rounded-[var(--radius-md)] bg-[var(--surface-sunken)] px-3 py-2 text-sm text-[var(--text-muted)]">
            Puedes mirar la carta. Para pedir, vuelve cuando abramos:{' '}
            {shop.hours.find((d) => !d.isClosed)?.opensAt ?? '—'}.
          </p>
        )}
      </ShopHeader>

      {/*
       * De aquí abajo, con tope de ancho y centrado.
       *
       * Sin él, en un portátil cada producto era una franja de metro y medio
       * con dos palabras en la esquina: la maqueta del teléfono estirada, que
       * es la otra forma de no ser responsive.
       */}
      <div className="mx-auto w-full max-w-6xl px-4 sm:px-6">
      {menu.categories.length > 0 && (
        <nav aria-label="Secciones" className="-mx-4 flex gap-2 overflow-x-auto px-4 py-3 sm:mx-0 sm:px-0">
          <CategoryChip active={category === null} onClick={() => setCategory(null)}>
            Todo
          </CategoryChip>

          {menu.categories.map((c) => (
            <CategoryChip key={c.id} active={category === c.id} onClick={() => setCategory(c.id)}>
              {c.name}
            </CategoryChip>
          ))}
        </nav>
      )}

      {/*
       * En rejilla y con la foto ARRIBA a partir de tablet.
       *
       * En comida se elige con los ojos: es el principio número uno del sistema
       * visual, y en el teléfono ya se cumplía con la miniatura al lado. En una
       * pantalla ancha hay sitio para que la foto sea la protagonista de
       * verdad, que es como se ve cualquier carta.
       */}
      <ul className="grid grid-cols-1 gap-3 pb-4 sm:grid-cols-2 lg:grid-cols-3">
        {visible.map((product) => (
          <li key={product.id}>
            <button
              type="button"
              onClick={() => setChosen(product)}
              className="flex h-full w-full items-center gap-3 rounded-[var(--radius-lg)] bg-[var(--surface-raised)] p-3 text-left shadow-[var(--shadow-card)] active:bg-[var(--surface-sunken)] sm:flex-col sm:items-stretch"
            >
              {/*
               * Sin foto va un hueco del mismo tamaño, no nada.
               *
               * En rejilla, una tarjeta sin foto al lado de una con foto se
               * estira para igualar la altura y deja un cajón blanco con dos
               * líneas arriba — parece rota. El hueco gris es el mismo recurso
               * que ya usa la carta del panel, y de paso deja claro que a ese
               * producto le falta la foto: es lo que más vende.
               */}
              {product.photoUrl != null ? (
                <img
                  src={product.photoUrl}
                  alt=""
                  className="size-20 shrink-0 rounded-[var(--radius-md)] object-cover sm:h-40 sm:w-full"
                />
              ) : (
                <span
                  aria-hidden="true"
                  className="size-20 shrink-0 rounded-[var(--radius-md)] bg-[var(--surface-sunken)] sm:h-40 sm:w-full"
                />
              )}

              <span className="min-w-0 flex-1">
                <span className="block font-medium text-[var(--text-strong)]">{product.name}</span>

                {product.description != null && product.description !== '' && (
                  <span className="line-clamp-2 block text-sm text-[var(--text-muted)]">
                    {product.description}
                  </span>
                )}

                <span className="tabular mt-1 block font-semibold text-[var(--text-strong)]">
                  {formatUsd(product.priceCents)}
                  {shop.exchangeRate != null && (
                    <span className="ml-2 text-xs font-normal text-[var(--text-muted)]">
                      {formatBs(product.priceCents, shop.exchangeRate)}
                    </span>
                  )}
                </span>
              </span>
            </button>
          </li>
        ))}
      </ul>

      {visible.length === 0 && (
        <EmptyState
          title="Aquí no hay nada todavía"
          description="Prueba en otra sección."
        />
      )}
      </div>

      {/* La barra del pedido: aparece sólo cuando hay algo, fija abajo, con el
          total siempre visible. Que el cliente sepa cuánto lleva sin tener que
          entrar a mirar. */}
      {cart.count > 0 && (
        <div className="fixed inset-x-0 bottom-0 border-t border-[var(--surface-border)] bg-[var(--surface-raised)] p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
          <Link to="/carrito" className="mx-auto block max-w-lg">
            <Button size="touch" block>
              Ver mi pedido · {cart.count} · {formatUsd(cart.subtotalCents)}
            </Button>
          </Link>
        </div>
      )}

      {chosen != null && (
        <ProductSheet
          product={chosen}
          groups={groupsOf(chosen)}
          onClose={() => setChosen(null)}
          onAdd={(modifiers, quantity) => {
            cart.add(chosen, modifiers, quantity)
            setChosen(null)
          }}
        />
      )}
    </div>
  )
}

function CategoryChip({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: string
}) {
  return (
    <button
      type="button"
      aria-pressed={active}
      onClick={onClick}
      className={`min-h-11 shrink-0 rounded-full px-4 text-sm font-medium ${
        active ? 'bg-accent-500 text-white' : 'bg-[var(--surface-raised)] text-[var(--text-default)]'
      }`}
    >
      {children}
    </button>
  )
}
