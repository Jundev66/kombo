import { allows, type Capabilities, type ModuleCode, type PermissionCode } from '@kombo/api-client'
import type { Icon } from '@kombo/ui'
import type { ComponentType } from 'react'

/**
 * How a module is drawn. NOT which ones exist.
 *
 * That distinction is the whole design: the list of modules lives on the server
 * and changes without deploying. Here there is only "if this tenant has the
 * menu, paint it like this". A module the frontend cannot yet draw simply does
 * not appear, rather than breaking the app.
 */
export interface ModuleUi {
  module: ModuleCode
  path: string
  /** Minimum permission for it to appear in the menu. */
  permission: PermissionCode
  /**
   * The screen, if it lives in this app.
   *
   * Absent on entries leading to ANOTHER of the five — the till, the kitchen —
   * which use `href`.
   */
  Screen?: ComponentType
  /**
   * A link to another app of the same tenant.
   *
   * A full reload rather than router navigation, because these are separate
   * builds under the same origin: the cook does not download the reports
   * dashboard to see three tickets.
   */
  href?: string
  Icon: Icon
  /** Overrides the manifest's label, when needed. */
  label?: string
  /** Place in the main bar (1, 2, 3). Without this it goes to "More". */
  primary?: number
  /**
   * Which heading it groups under inside "More".
   *
   * Twelve flat entries are not a menu, they are a list: "Categorías" and
   * "Agregados" ended up level with "Equipo", and finding the opening hours
   * meant reading all of them. Groups appear in the order declared here.
   */
  group?: string
}

export interface MenuEntry {
  module: ModuleCode
  path: string
  href: string | undefined
  label: string
  Icon: Icon
  primary: number | undefined
  group: string | undefined
  Screen: ComponentType | undefined
}

export interface MenuGroup {
  /** `null` for the ungrouped: they sit loose at the top, with no heading. */
  title: string | null
  entries: MenuEntry[]
}

/**
 * The menu for THIS tenant and THIS user.
 *
 * The check is the conjunction `hasModule && can`, the same one the server
 * applies. The label comes from `moduleNames` — the backend manifest — so
 * renaming a module is one line in one place.
 */
export function buildMenu(registry: ModuleUi[], caps: Capabilities): MenuEntry[] {
  return registry
    .filter((entry) => allows(caps, entry.module, entry.permission))
    .map((entry) => ({
      module: entry.module,
      path: entry.path,
      href: entry.href,
      label: entry.label ?? caps.moduleNames[entry.module] ?? entry.module,
      Icon: entry.Icon,
      primary: entry.primary,
      group: entry.group,
      Screen: entry.Screen,
    }))
}

/**
 * Splits between the bar and the "More" panel, already grouped.
 *
 * The bar is filled with whatever there is even when not `primary`: a bar with
 * two buttons and an empty gap looks broken, and whoever uses it cannot tell
 * whether something is missing or the system half-loaded.
 *
 * What is already in the bar is not repeated in the groups.
 */
export function splitMenu(
  entries: MenuEntry[],
  slots = 3,
): { bar: MenuEntry[]; groups: MenuGroup[] } {
  const preferred = entries
    .filter((e) => e.primary !== undefined)
    .sort((a, b) => (a.primary ?? 0) - (b.primary ?? 0))

  /*
   * Any entry can fill a gap EXCEPT those that leave the app.
   *
   * A tab in the bar promises to come back: tap it, the content changes, the
   * bar stays. The till and the kitchen are another app — the back button does
   * not return — so in the bar they would look like another section and get
   * tapped by accident. In "More" they carry their arrow and the gesture is
   * deliberate.
   */
  const rest = entries.filter((e) => e.primary === undefined && e.href === undefined)
  const bar = [...preferred, ...rest].slice(0, slots)
  const inBar = new Set(bar.map((e) => e.path))

  const groups: MenuGroup[] = []

  for (const entry of entries) {
    if (inBar.has(entry.path)) continue

    const title = entry.group ?? null
    const existing = groups.find((g) => g.title === title)

    if (existing) {
      existing.entries.push(entry)
    } else {
      groups.push({ title, entries: [entry] })
    }
  }

  // The ungrouped go first and without a heading: a group called "Other" tells
  // nobody anything, and putting it last hides whatever is loose.
  return { bar, groups: groups.sort((a, b) => Number(a.title !== null) - Number(b.title !== null)) }
}
