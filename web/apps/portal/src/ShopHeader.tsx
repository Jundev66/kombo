import { brandSurface } from '@kombo/ui'
import type { ReactNode } from 'react'
import type { Shop } from './api'

/**
 * Who the tenant is, at the very top. Used by the menu and by order tracking.
 *
 * The brand colour tints the name band and nothing else. Not timidity:
 * `brand_color` is a hex the tenant types in, and once a surface carries an
 * arbitrary colour, the colour of EVERYTHING on it has to be recomputed. The
 * first attempt included the subtitle, and "Abierto ahora" in green on dark
 * orange could not be read. With the subtitle and the notices below, the status
 * colours stay on the neutral they were chosen for.
 *
 * `brandSurface` discards the colour when the text on it does not contrast. And
 * it is never used on a button or a status: green, amber and red say how the
 * order is going, and a green brand would say "ready" without being so.
 */
export function ShopHeader({
  shop,
  as: Title = 'h1',
  subtitle,
  children,
}: {
  shop: Shop
  /**
   * Which tag the tenant's name takes.
   *
   * `h1` on the menu, where the page IS the tenant. `p` on tracking, where the
   * page's heading is the ORDER — the figure the customer reads aloud on the
   * phone.
   */
  as?: 'h1' | 'p'
  /** Below the band, on the neutral: "Abierto ahora", "Pedido #518"… */
  subtitle?: ReactNode
  /** Notices that belong with the header. */
  children?: ReactNode
}) {
  const brand = brandSurface(shop.brandColor)

  return (
    <header className="flex flex-col bg-[var(--surface-raised)]">
      <div
        style={
          brand === null ? undefined : { background: brand.background, color: brand.foreground }
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
            // With a brand, the band's colour wins: the theme variable points at the
            // system ink, which does not read on a dark background.
            style={brand === null ? undefined : { color: brand.foreground }}
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
