import { useState, type ReactNode } from 'react'
import { NavLink } from 'react-router'
import { buildMenu, splitMenu, type ModuleUi } from './menu'
import { logout, useSession } from './session'

/**
 * El armazón. **Diseñado para el teléfono**, no adaptado a él.
 *
 * No hay una «versión de escritorio»: hay una barra que cambia de sitio. En
 * móvil va fija abajo, al alcance del pulgar; en pantalla ancha, arriba. El
 * dueño de un local revisa esto de pie, con una mano, mientras pasa otra cosa.
 */
export function AppShell({ registry, children }: { registry: ModuleUi[]; children: ReactNode }) {
  const { capabilities } = useSession()
  const [showMore, setShowMore] = useState(false)

  if (capabilities?.user == null) return null

  const entries = buildMenu(registry, capabilities)
  const { bar, more } = splitMenu(entries)

  return (
    <div className="min-h-dvh bg-[var(--surface-sunken)]">
      <header className="sticky top-0 z-10 flex items-center justify-between gap-3 border-b border-[var(--surface-hairline)] bg-[var(--surface-raised)] px-4 py-3">
        <div className="min-w-0">
          <p className="truncate font-semibold text-[var(--text-strong)]">
            {capabilities.tenant?.name}
          </p>
          <p className="truncate text-xs text-[var(--text-muted)]">{capabilities.user.name}</p>
        </div>

        {/* En pantalla ancha la navegación va arriba; en móvil, abajo. */}
        <nav aria-label="Secciones" className="hidden md:flex md:items-center md:gap-1">
          {entries.map((entry) => (
            <NavLink
              key={entry.path}
              to={entry.path}
              className={({ isActive }) =>
                `rounded-[var(--radius-md)] px-3 py-2 text-sm ${
                  isActive
                    ? 'bg-[var(--surface-sunken)] font-medium text-[var(--text-strong)]'
                    : 'text-[var(--text-muted)]'
                }`
              }
            >
              {entry.label}
            </NavLink>
          ))}
        </nav>

        <button
          type="button"
          onClick={() => void logout()}
          className="shrink-0 text-sm text-[var(--text-muted)] underline-offset-2 hover:underline"
        >
          Salir
        </button>
      </header>

      {capabilities.tenant?.needsAttention === true && (
        <p role="alert" className="bg-warn-50 px-4 py-2 text-sm text-warn-700">
          Tu cuenta necesita atención. Revisa el pago para no quedarte en sólo lectura.
        </p>
      )}

      {/* pb-28 en móvil: sin eso, lo último de la página queda debajo de la
          barra fija y no se puede tocar. */}
      <main className="mx-auto max-w-3xl px-4 pt-4 pb-28 md:pb-8">{children}</main>

      <nav
        aria-label="Secciones"
        className="fixed inset-x-0 bottom-0 z-10 flex border-t border-[var(--surface-hairline)] bg-[var(--surface-raised)] pb-safe md:hidden"
      >
        {bar.map((entry) => (
          <NavLink
            key={entry.path}
            to={entry.path}
            className={({ isActive }) =>
              // Toda la celda es el objetivo táctil, no sólo el texto.
              `flex min-h-touch flex-1 flex-col items-center justify-center gap-0.5 text-[11px] ${
                isActive ? 'font-medium text-brand-700' : 'text-[var(--text-muted)]'
              }`
            }
          >
            <span aria-hidden="true" className="text-xl leading-none">
              {entry.icon}
            </span>
            {entry.label}
          </NavLink>
        ))}

        {more.length > 0 && (
          <button
            type="button"
            onClick={() => setShowMore(true)}
            className="flex min-h-touch flex-1 flex-col items-center justify-center gap-0.5 text-[11px] text-[var(--text-muted)]"
          >
            <span aria-hidden="true" className="text-xl leading-none">
              ⋯
            </span>
            Más
          </button>
        )}
      </nav>

      {showMore && (
        <div
          className="fixed inset-0 z-20 flex items-end bg-black/40 md:items-center md:justify-center"
          onClick={() => setShowMore(false)}
        >
          <div
            className="w-full rounded-t-[var(--radius-xl)] bg-[var(--surface-raised)] p-4 pb-safe md:max-w-sm md:rounded-[var(--radius-xl)]"
            onClick={(e) => e.stopPropagation()}
          >
            {more.map((entry) => (
              <NavLink
                key={entry.path}
                to={entry.path}
                onClick={() => setShowMore(false)}
                className="flex min-h-touch items-center gap-3 px-2 text-[var(--text-strong)]"
              >
                <span aria-hidden="true">{entry.icon}</span>
                {entry.label}
              </NavLink>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}
