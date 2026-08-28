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
 * Todo lo que necesita saber quién es el negocio.
 *
 * Va en un componente aparte y **no en `App`** por una razón concreta: el
 * carrito se guarda con el negocio en la clave, y `App` se pinta una primera
 * vez cuando todavía no se sabe cuál es. Si el carrito viviera arriba, esa
 * primera pasada leería una clave que no es —y, peor, escribiría un carrito
 * vacío encima del que el cliente tenía guardado—. Montándolo cuando el
 * negocio ya se conoce, ese momento no existe.
 */
function Ordering({ shop, menu }: { shop: Shop; menu: Menu }) {
  const cart = useCart(shop.slug)

  return (
    <BrowserRouter>
      <Routes>
        <Route path="/" element={<MenuScreen shop={shop} menu={menu} cart={cart} />} />
        <Route path="/carrito" element={<CheckoutScreen shop={shop} cart={cart} />} />

        {/* El enlace que el cliente guarda. Corto a propósito: se manda por
            WhatsApp y se lee en voz alta por teléfono. */}
        <Route path="/p/:token" element={<TrackScreen shop={shop} />} />

        {/* Cualquier otra cosa es la carta: un enlace viejo no puede acabar en
            una pantalla de error. */}
        <Route path="*" element={<MenuScreen shop={shop} menu={menu} cart={cart} />} />
      </Routes>
    </BrowserRouter>
  )
}

/**
 * El portal del cliente.
 *
 * Tres pantallas y nada más: la carta, el pedido, y dónde va. Un cliente con
 * hambre no explora una aplicación — mira, elige, paga y espera.
 *
 * La tienda y la carta se cargan **una vez** al arrancar y se pasan hacia
 * abajo. En un teléfono con mala señal, cada petición de más es otra
 * oportunidad de ver algo a medias y cerrar.
 */
export function App() {
  const [shop, setShop] = useState<Shop | null>(null)
  const [menu, setMenu] = useState<Menu | null>(null)
  const [loading, setLoading] = useState(true)
  const [noPortal, setNoPortal] = useState(false)

  useEffect(() => {
    Promise.all([shopApi.shop(), shopApi.menu()])
      .then(([tienda, carta]) => {
        setShop(tienda)
        setMenu(carta)
      })
      .catch((failure: unknown) => {
        // 404 no es un fallo: es que este negocio no tiene portal, o que la
        // dirección no es de ningún negocio. Se dice, en vez de dejar una
        // pantalla girando para siempre.
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
