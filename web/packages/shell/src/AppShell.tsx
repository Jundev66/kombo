import { ExternalIcon, MoreIcon } from '@kombo/ui'
import { useState, type ReactNode } from 'react'
import { NavLink } from 'react-router'
import { buildMenu, splitMenu, type MenuEntry, type MenuGroup, type ModuleUi } from './menu'
import { logout, useSession } from './session'

/**
 * El armazón. **Empieza en el teléfono y crece con la pantalla.**
 *
 * No hay una «versión de escritorio» aparte: hay una barra que cambia de sitio
 * —fija abajo en móvil, al alcance del pulgar; arriba en pantalla ancha— y un
 * contenido que se reparte en columnas cuando hay sitio. El dueño de un local
 * revisa esto de pie con una mano, y también sentado en un portátil al cerrar
 * la caja: las dos son ciertas y antes sólo se atendía la primera.
 *
 * Lo que había: `max-w-3xl` aquí y punto. En un portátil el panel usaba la
 * mitad de la pantalla, con dos márgenes grises enormes, y dentro de esa
 * columna cada tarjeta se estiraba a 736 px para sostener cuatro líneas. El
 * ancho lo decide ahora cada pantalla con `<Page>`, porque un tablero quiere
 * ancho y un formulario no.
 *
 * **Una sola navegación para los dos tamaños.** Antes eran dos: en escritorio
 * una fila plana con las doce entradas, en móvil tres y un «Más» que escondía
 * las otras nueve —el horario y el equipo entre ellas—. Dos menús distintos
 * para el mismo sistema significa que uno de los dos está mal, y aquí lo
 * estaban los dos: el plano no tenía jerarquía y el corto escondía lo que menos
 * se toca pero más cuesta encontrar.
 */
export function AppShell({ registry, children }: { registry: ModuleUi[]; children: ReactNode }) {
  const { capabilities } = useSession()
  const [showMore, setShowMore] = useState(false)

  if (capabilities?.user == null) return null

  const entries = buildMenu(registry, capabilities)
  const { bar, groups } = splitMenu(entries)

  return (
    <div className="min-h-dvh bg-[var(--surface-sunken)]">
      {/* La cabecera va a todo el ancho —es una barra— y su CONTENIDO se alinea
          con el de la página. Sin eso, en un portátil el nombre del negocio
          queda pegado al borde y el contenido empieza doscientos píxeles más
          adentro, como si fueran dos páginas distintas. */}
      <header className="sticky top-0 z-10 border-b border-[var(--surface-hairline)] bg-[var(--surface-raised)]">
        <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
        <div className="min-w-0 flex-1">
          <p className="truncate font-semibold text-[var(--text-strong)]">
            {capabilities.tenant?.name}
          </p>
          {/* La persona Y su rol. Sin el rol, «esto no se puede» y «esto no lo
              puedes tú» se ven igual, y son cosas muy distintas de resolver. */}
          <p className="truncate text-xs text-[var(--text-muted)]">
            {capabilities.user.name}
            {capabilities.user.roleName != null && ` · ${capabilities.user.roleName}`}
          </p>
        </div>

        <nav aria-label="Secciones" className="hidden md:flex md:items-center md:gap-1">
          {bar.map((entry) => (
            <BarLink key={entry.path} entry={entry} />
          ))}

          {groups.length > 0 && (
            <button
              type="button"
              onClick={() => setShowMore(true)}
              className="flex items-center gap-1.5 rounded-[var(--radius-md)] px-3 py-2 text-sm text-[var(--text-muted)]"
            >
              <MoreIcon className="size-5" />
              Más
            </button>
          )}
        </nav>

          <button
            type="button"
            onClick={() => void logout()}
            className="shrink-0 text-sm text-[var(--text-muted)] underline-offset-2 hover:underline"
          >
            Salir
          </button>
        </div>
      </header>

      {capabilities.tenant?.needsAttention === true && (
        <p role="alert" className="bg-warn-50 px-4 py-2 text-center text-sm text-warn-700">
          Tu cuenta necesita atención. Revisa el pago para no quedarte en sólo lectura.
        </p>
      )}

      {/*
       * El ancho lo decide CADA pantalla con `<Page>`, no el armazón.
       *
       * Antes era `max-w-3xl` aquí y punto: en un portátil el panel usaba la
       * mitad de la pantalla y cada tarjeta se estiraba a 736 px para sostener
       * cuatro líneas. Pero tampoco vale ensanchar todo — un formulario de
       * horario a 1200 px no se lee mejor, sólo aleja la etiqueta del campo.
       * Un tablero quiere ancho; un formulario, no.
       *
       * `pb-28` en móvil: sin eso, lo último queda debajo de la barra fija y no
       * se puede tocar.
       */}
      <main className="pt-4 pb-28 md:pb-8">{children}</main>

      <nav
        aria-label="Secciones"
        className="fixed inset-x-0 bottom-0 z-10 flex border-t border-[var(--surface-hairline)] bg-[var(--surface-raised)] pb-safe md:hidden"
      >
        {bar.map((entry) => (
          <TabLink key={entry.path} entry={entry} />
        ))}

        {groups.length > 0 && (
          <button
            type="button"
            onClick={() => setShowMore(true)}
            className="flex min-h-touch flex-1 flex-col items-center justify-center gap-0.5 text-[11px] text-[var(--text-muted)]"
          >
            <MoreIcon className="size-6" />
            Más
          </button>
        )}
      </nav>

      {showMore && <MoreSheet groups={groups} onClose={() => setShowMore(false)} />}
    </div>
  )
}

/** Una entrada de la barra ancha. */
function BarLink({ entry }: { entry: MenuEntry }) {
  const classes = 'rounded-[var(--radius-md)] px-3 py-2 text-sm'

  if (entry.href != null) {
    return (
      <a href={entry.href} className={`${classes} flex items-center gap-1.5 text-[var(--text-muted)]`}>
        {entry.label}
        <ExternalIcon className="size-4" />
      </a>
    )
  }

  return (
    <NavLink
      to={entry.path}
      className={({ isActive }) =>
        `${classes} ${
          isActive
            ? 'bg-[var(--surface-sunken)] font-medium text-[var(--text-strong)]'
            : 'text-[var(--text-muted)]'
        }`
      }
    >
      {entry.label}
    </NavLink>
  )
}

/** Una celda de la barra de abajo. Toda la celda es el objetivo táctil. */
function TabLink({ entry }: { entry: MenuEntry }) {
  const classes =
    'flex min-h-touch flex-1 flex-col items-center justify-center gap-0.5 text-[11px]'

  if (entry.href != null) {
    return (
      <a href={entry.href} className={`${classes} text-[var(--text-muted)]`}>
        <entry.Icon className="size-6" />
        {entry.label}
      </a>
    )
  }

  return (
    <NavLink
      to={entry.path}
      className={({ isActive }) =>
        `${classes} ${isActive ? 'font-medium text-brand-700' : 'text-[var(--text-muted)]'}`
      }
    >
      <entry.Icon className="size-6" />
      {entry.label}
    </NavLink>
  )
}

/**
 * Todo lo que no cabe en la barra, por grupos.
 *
 * Sube desde abajo en el teléfono —donde está el pulgar— y sale centrado en
 * pantalla ancha, que es donde está la vista.
 */
function MoreSheet({ groups, onClose }: { groups: MenuGroup[]; onClose: () => void }) {
  return (
    <div
      className="fixed inset-0 z-20 flex items-end bg-black/40 md:items-center md:justify-center"
      onClick={onClose}
    >
      <div
        className="max-h-[80dvh] w-full overflow-y-auto rounded-t-[var(--radius-xl)] bg-[var(--surface-raised)] p-4 pb-safe md:max-w-sm md:rounded-[var(--radius-xl)]"
        onClick={(e) => e.stopPropagation()}
      >
        {groups.map((group) => (
          <section key={group.title ?? 'sueltas'} className="mb-2 last:mb-0">
            {group.title != null && (
              <h2 className="px-2 pt-3 pb-1 text-xs font-semibold tracking-wide text-[var(--text-muted)] uppercase">
                {group.title}
              </h2>
            )}

            {group.entries.map((entry) =>
              entry.href != null ? (
                <a
                  key={entry.path}
                  href={entry.href}
                  className="flex min-h-touch items-center gap-3 px-2 text-[var(--text-strong)]"
                >
                  <entry.Icon className="size-5 text-[var(--text-muted)]" />
                  <span className="flex-1">{entry.label}</span>
                  {/* Que esto sale de aquí se avisa: el botón de atrás del
                      navegador no devuelve a la pantalla del panel. */}
                  <ExternalIcon className="size-4 text-[var(--text-muted)]" />
                </a>
              ) : (
                <NavLink
                  key={entry.path}
                  to={entry.path}
                  onClick={onClose}
                  className="flex min-h-touch items-center gap-3 px-2 text-[var(--text-strong)]"
                >
                  <entry.Icon className="size-5 text-[var(--text-muted)]" />
                  {entry.label}
                </NavLink>
              ),
            )}
          </section>
        ))}
      </div>
    </div>
  )
}
