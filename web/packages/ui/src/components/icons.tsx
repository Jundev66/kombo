import type { ComponentType, ReactNode } from 'react'

/**
 * Los iconos, dibujados a mano y en línea.
 *
 * **No es una librería, y no por gusto.** El presupuesto de arranque son 180 KB
 * en la caja y 120 KB en la cocina, y cualquier paquete de iconos se lleva una
 * parte que no vuelve. Estos son quince trazos: pesan menos de un kilobyte
 * entre todos, y cada uno se exporta por separado para que la aplicación que no
 * lo usa no lo arrastre.
 *
 * Antes eran emojis. Funcionaban, pero cada sistema operativo dibuja el suyo:
 * la misma pantalla se veía distinta en la tablet de la cocina, en el teléfono
 * del dueño y en la PC del mostrador, y algunos ni siquiera existían en la
 * fuente de un Android viejo —donde salía un cuadrito—. Un icono que a veces es
 * un cuadrito no es un icono.
 *
 * Heredan el color (`currentColor`) y el grosor no cambia con el tamaño: a
 * 24 px en una barra y a 20 px en una lista tienen que verse de la misma
 * familia.
 */
export type Icon = ComponentType<{ className?: string }>

function Glyph({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <svg
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth={1.5}
      strokeLinecap="round"
      strokeLinejoin="round"
      // Decorativos: la etiqueta de texto va siempre al lado, así que
      // anunciarlos otra vez sería repetir cada entrada del menú dos veces.
      aria-hidden="true"
      className={className ?? 'size-6'}
    >
      {children}
    </svg>
  )
}

/** Pedidos. */
export const ReceiptIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M6 2h12v20l-3-2-3 2-3-2-3 2Z" />
    <path d="M9.5 7.5h5M9.5 11.5h5" />
  </Glyph>
)

/** La carta. */
export const MenuIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M6 2v5a2 2 0 0 0 4 0V2M8 9v13" />
    <path d="M16 2c1.5 1.2 2 3 2 5s-.5 3.2-2 4v11" />
  </Glyph>
)

/** Categorías. */
export const FolderIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z" />
  </Glyph>
)

/** Agregados. */
export const PlusCircleIcon: Icon = (props) => (
  <Glyph {...props}>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 8.5v7M8.5 12h7" />
  </Glyph>
)

/** Entregas. */
export const TruckIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M2 6h11v10H2ZM13 9.5h4l3 3V16h-7" />
    <circle cx="7" cy="18" r="2" />
    <circle cx="17" cy="18" r="2" />
  </Glyph>
)

/** Un cliente. */
export const UserIcon: Icon = (props) => (
  <Glyph {...props}>
    <circle cx="12" cy="8" r="4" />
    <path d="M4.5 21a7.5 7.5 0 0 1 15 0" />
  </Glyph>
)

/** Zonas de reparto. */
export const PinIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12Z" />
    <circle cx="12" cy="10" r="2.5" />
  </Glyph>
)

/** Ventas. */
export const ChartIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M3 21h18" />
    <path d="M6 21v-6M11 21V7M16 21v-9M21 21V4" />
  </Glyph>
)

/** WhatsApp y los canales. */
export const ChatIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M21 12a8 8 0 0 1-8 8H8l-5 3 1.2-4.3A8 8 0 0 1 11 4h2a8 8 0 0 1 8 8Z" />
  </Glyph>
)

/** El horario. */
export const ClockIcon: Icon = (props) => (
  <Glyph {...props}>
    <circle cx="12" cy="12" r="9" />
    <path d="M12 7v5.2l3.2 2" />
  </Glyph>
)

/** El equipo. */
export const UsersIcon: Icon = (props) => (
  <Glyph {...props}>
    <circle cx="9" cy="8" r="3.5" />
    <path d="M2.5 20a6.5 6.5 0 0 1 13 0" />
    <path d="M16 5.2a3.5 3.5 0 0 1 0 5.6M17.5 14.2A6.5 6.5 0 0 1 21.5 20" />
  </Glyph>
)

/** La tasa del día. */
export const BanknoteIcon: Icon = (props) => (
  <Glyph {...props}>
    <rect x="2" y="6" width="20" height="12" rx="2" />
    <circle cx="12" cy="12" r="2.5" />
    <path d="M6 12h.01M18 12h.01" />
  </Glyph>
)

/** La caja del mostrador. */
export const RegisterIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M3 9 4.5 4h15L21 9M4 9v11h16V9" />
    <path d="M9.5 20v-5.5h5V20" />
  </Glyph>
)

/** La cocina. */
export const FlameIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M12 22a6 6 0 0 0 6-6c0-5-6-14-6-14S6 11 6 16a6 6 0 0 0 6 6Z" />
    <path d="M12 18a2.5 2.5 0 0 0 2.5-2.5c0-2-2.5-5.5-2.5-5.5s-2.5 3.5-2.5 5.5A2.5 2.5 0 0 0 12 18Z" />
  </Glyph>
)

/** «Más». */
export const MoreIcon: Icon = (props) => (
  <Glyph {...props}>
    <circle cx="5" cy="12" r="1.25" fill="currentColor" />
    <circle cx="12" cy="12" r="1.25" fill="currentColor" />
    <circle cx="19" cy="12" r="1.25" fill="currentColor" />
  </Glyph>
)

/** Esto sale de esta aplicación. */
export const ExternalIcon: Icon = (props) => (
  <Glyph {...props}>
    <path d="M8 16 16 8M9.5 8H16v6.5" />
  </Glyph>
)
