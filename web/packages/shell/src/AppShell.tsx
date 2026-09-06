import { ExternalIcon, MoreIcon } from '@kombo/ui'
import { useState, type ReactNode } from 'react'
import { NavLink } from 'react-router'
import { buildMenu, splitMenu, type MenuEntry, type MenuGroup, type ModuleUi } from './menu'
import { logout, useSession } from './session'

/**
 * The shell. It starts on the phone and grows with the screen.
 *
 * There is no separate "desktop version": one bar that moves — fixed at the
 * bottom on mobile, within thumb reach; at the top on a wide screen — and
 * content that spreads into columns when there is room. An owner checks this
 * standing up one-handed, and also sitting at a laptop at closing time.
 *
 * One navigation for both sizes. There used to be two, and both were wrong: the
 * flat desktop row had no hierarchy, and the short mobile one hid nine entries
 * — the opening hours and the team among them — behind "More".
 */
export function AppShell({ registry, children }: { registry: ModuleUi[]; children: ReactNode }) {
  const { capabilities } = useSession()
  const [showMore, setShowMore] = useState(false)

  if (capabilities?.user == null) return null

  const entries = buildMenu(registry, capabilities)
  const { bar, groups } = splitMenu(entries)

  return (
    <div className="min-h-dvh bg-[var(--surface-sunken)]">
      {/* The header spans the full width — it is a bar — while its CONTENT lines
          up with the page. Without that, on a laptop the tenant's name sits
          against the edge and the content starts two hundred pixels in. */}
      <header className="sticky top-0 z-10 border-b border-[var(--surface-hairline)] bg-[var(--surface-raised)]">
        <div className="mx-auto flex max-w-7xl items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
        <div className="min-w-0 flex-1">
          <p className="truncate font-semibold text-[var(--text-strong)]">
            {capabilities.tenant?.name}
          </p>
          {/* The person AND their role. Without the role, "this cannot be done" and
              "you cannot do this" look the same, and they are very different
              problems to solve. */}
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
       * Width is decided by EACH screen with `<Page>`, not by the shell.
       *
       * It used to be `max-w-3xl` here and nothing else: on a laptop the
       * dashboard used half the screen and every card stretched to 736 px to
       * hold four lines. But widening everything is no better — an opening
       * hours form at 1200 px only pushes the label away from its field.
       *
       * `pb-28` on mobile: without it the last item sits under the fixed bar
       * and cannot be tapped.
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

/** One entry in the wide bar. */
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

/** One cell of the bottom bar. The whole cell is the touch target. */
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
 * Everything that does not fit in the bar, by group.
 *
 * It rises from the bottom on a phone — where the thumb is — and appears
 * centred on a wide screen, which is where the eye is.
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
                  {/* That this leaves the app is announced: the browser's back button does
                      not return to the dashboard. */}
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
