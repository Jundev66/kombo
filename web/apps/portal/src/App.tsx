import { ApiError } from '@kombo/api-client'
import { EmptyState, Spinner } from '@kombo/ui'
import { useEffect, useState } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router'
import { shopApi, type Menu, type Shop } from './api'
import { useCart } from './cart'
import { CheckoutScreen } from './CheckoutScreen'
import { MenuScreen } from './MenuScreen'
import { TrackScreen } from './TrackScreen'

/**
 * Everything that needs to know which tenant this is.
 *
 * A separate component and not `App`, for a concrete reason: the basket is
 * stored with the tenant in its key, and `App` paints once before the tenant is
 * known. Living higher up, that first pass would read the wrong key — and
 * worse, write an empty basket over the customer's saved one.
 */
function Ordering({ shop, menu }: { shop: Shop; menu: Menu }) {
  const cart = useCart(shop.slug)

  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<MenuScreen shop={shop} menu={menu} cart={cart} />} />
        <Route path="/carrito" element={<CheckoutScreen shop={shop} cart={cart} />} />

        {/* The link the customer keeps. Deliberately short: it is sent over
            WhatsApp and read aloud on the phone. */}
        <Route path="/p/:token" element={<TrackScreen shop={shop} />} />

        {/* Anything else is the menu: an old link must not land on an error
            screen. */}
        <Route path="*" element={<MenuScreen shop={shop} menu={menu} cart={cart} />} />
      </Routes>
    </BrowserRouter>
  )
}

/**
 * The customer portal.
 *
 * Three screens and no more: the menu, the order, and where it is. A hungry
 * customer does not explore an app — they look, choose, pay and wait.
 *
 * The shop and the menu load ONCE at startup and are passed down. On a phone
 * with poor signal, every extra request is another chance to see something
 * half-drawn and close it.
 */
export function App() {
  const [shop, setShop] = useState<Shop | null>(null)
  const [menu, setMenu] = useState<Menu | null>(null)
  const [loading, setLoading] = useState(true)
  const [noPortal, setNoPortal] = useState(false)

  useEffect(() => {
    Promise.all([shopApi.shop(), shopApi.menu()])
      .then(([tienda, menu]) => {
        setShop(tienda)
        setMenu(menu)
      })
      .catch((failure: unknown) => {
        // A 404 is not a failure: either this tenant has no portal, or the address
        // belongs to no tenant. It is said, rather than leaving a spinner turning
        // forever.
        if (failure instanceof ApiError && failure.status === 404) {
          setNoPortal(true)
        }
      })
      .finally(() => setLoading(false))
  }, [])

  if (loading) return <Spinner label="Un momento…" />

  if (noPortal || shop === null || menu === null) {
    return (
      <div className="grid min-h-dvh place-items-center p-6">
        <EmptyState
          title="Aquí no hay nada que pedir"
          description="Este negocio no tiene la carta en línea. Escríbele o pásate por el local."
        />
      </div>
    )
  }

  return <Ordering shop={shop} menu={menu} />
}
