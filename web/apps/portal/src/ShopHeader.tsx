import { brandSurface } from '@kombo/ui'
import type { ReactNode } from 'react'
import type { Shop } from './api'

/**
 * Quién es el negocio, arriba del todo.
 *
 * La usan la carta y el seguimiento del pedido. Era una sola cosa escrita dos
 * veces —y en el seguimiento, ni eso: el nombre iba en gris pequeño y el logo
 * no aparecía—. Alguien que llega por un enlace de WhatsApp tiene que
 * reconocer de quién es la pantalla antes de leer nada más.
 *
 * **El color de marca tiñe la franja del nombre y nada más.** No es timidez:
 * `brand_color` es un hexa que escribe el negocio, y en cuanto una superficie
 * lleva un color arbitrario hay que recalcular el color de TODO lo que va
 * encima. El primer intento metía ahí también el subtítulo, y «Abierto ahora»
 * en verde sobre un naranja oscuro no se leía. Dejando debajo el subtítulo y
 * los avisos, los colores de estado siguen sobre el neutro para el que se
 * eligieron — que es donde está garantizado que se leen.
 *
 * `brandSurface` descarta el color si el texto no contrasta encima. Y nunca se
 * usa en un botón ni en un estado: verde, ámbar y rojo dicen cómo va el
 * pedido, y una marca verde diría «listo» sin serlo.
 *
 * **La franja va a todo el ancho; su contenido, alineado con la página.** Sin
 * eso, en un portátil el nombre del negocio queda pegado al borde izquierdo y
 * la carta empieza doscientos píxeles más adentro, como si fueran dos páginas
 * distintas pegadas.
 */
export function ShopHeader({
  shop,
  as: Title = 'h1',
  subtitle,
  children,
}: {
  shop: Shop
  /**
   * Con qué etiqueta va el nombre del negocio.
   *
   * `h1` en la carta, donde la página ES el negocio. `p` en el seguimiento,
   * donde el encabezado de la página es el PEDIDO: es el dato que el cliente
   * lee en voz alta por teléfono, y dejarlo de subtítulo mientras la marca se
   * lleva el `h1` desordena la página para quien la recorre con un lector.
   */
  as?: 'h1' | 'p'
  /** Debajo de la franja, sobre el neutro: «Abierto ahora», «Pedido #518»… */
  subtitle?: ReactNode
  /** Avisos que van con la cabecera. */
  children?: ReactNode
}) {
  const marca = brandSurface(shop.brandColor)

  return (
    <header className="flex flex-col bg-[var(--surface-raised)]">
      <div
        style={
          marca === null ? undefined : { background: marca.background, color: marca.foreground }
        }
      >
        <div className="mx-auto flex w-full max-w-6xl items-center gap-3 px-4 py-4 sm:px-6">
          {shop.logoUrl != null && (
            <img
              src={shop.logoUrl}
              alt=""
              className="size-12 shrink-0 rounded-full bg-white object-cover"
            />
          )}

          <Title
            className="min-w-0 flex-1 truncate text-xl font-bold text-[var(--text-strong)]"
            // Con marca manda el color de la franja: la variable del tema
            // apunta a la tinta del sistema, que sobre un fondo oscuro no se lee.
            style={marca === null ? undefined : { color: marca.foreground }}
          >
            {shop.name}
          </Title>
        </div>
      </div>

      {(subtitle != null || children != null) && (
        <div className="mx-auto flex w-full max-w-6xl flex-col gap-2 px-4 pt-2 pb-4 sm:px-6">
          {subtitle}
          {children}
        </div>
      )}
    </header>
  )
}
