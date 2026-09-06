import { Button, EmptyState, formatBs, formatUsd } from '@kombo/ui'
import { useMemo, useState } from 'react'
import { Link } from 'react-router'
import type { Menu, MenuProduct, Shop } from './api'
import type { Cart } from './cart'
import { ProductSheet } from './ProductSheet'
import { ShopHeader } from './ShopHeader'

/**
 * The menu, on the phone of somebody who is hungry.
 *
 * Big photo and big price. Not decoration: with food people choose with their
 * eyes, and a list of grey names sells considerably less than a photo of the
 * arepa.
 *
 * And one action at the bottom, fixed, always in the same place: see the order.
 * The thumb reaches it without looking.
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
              // Said at the very top, before they build an order. Not at the end, once
              // they have already chosen three things.
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
       * From here down, capped and centred.
       *
       * Without it, on a laptop each product was a metre-and-a-half band with
       * two words in the corner: the phone layout stretched, which is the other
       * way of not being responsive.
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
       * A grid, with the photo ON TOP from tablet up.
       *
       * With food people choose with their eyes — principle number one of the
       * visual system. On the phone the thumbnail alongside already satisfies
       * it; on a wide screen there is room for the photo to really lead.
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
               * With no photo there is a placeholder of the same size, not
               * nothing.
               *
               * In a grid, a card without a photo next to one with a photo
               * stretches to match the height and leaves a white box with two
               * lines at the top — it looks broken. The grey placeholder also
               * makes clear that product is missing the photo.
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

      {/* The order bar: it appears only when there is something, fixed at the
          bottom, with the total always visible. */}
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
