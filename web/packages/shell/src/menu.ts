import { allows, type Capabilities, type ModuleCode, type PermissionCode } from '@kombo/api-client'
import type { ComponentType } from 'react'

/**
 * Cómo se dibuja un módulo. **No cuáles existen.**
 *
 * Esa distinción es todo el diseño: la lista de módulos vive en el servidor y
 * cambia sin desplegar. Aquí sólo está el «si este negocio tiene la carta, se
 * pinta así». Un módulo que el frontend todavía no sabe dibujar sencillamente
 * no aparece en el menú, en vez de romper la aplicación.
 */
export interface ModuleUi {
  module: ModuleCode
  path: string
  /** Permiso mínimo para que aparezca en el menú. */
  permission: PermissionCode
  Screen: ComponentType
  /** Emoji o texto corto para la barra de abajo. Sin librería de iconos. */
  icon: string
  /** Sobrescribe la etiqueta del manifiesto, si hace falta. */
  label?: string
  /** Sitio en la barra principal (1, 2, 3). Sin esto, va a «Más». */
  primary?: number
}

export interface MenuEntry {
  module: ModuleCode
  path: string
  label: string
  icon: string
  primary: number | undefined
  Screen: ComponentType
}

/**
 * El menú de ESTE negocio y ESTE usuario.
 *
 * La comprobación es la conjunción `hasModule && can`, la misma que aplica el
 * servidor. Y la etiqueta sale de `moduleNames` —del manifiesto del backend—
 * para que renombrar un módulo sea una línea en un sitio.
 */
export function buildMenu(registry: ModuleUi[], caps: Capabilities): MenuEntry[] {
  return registry
    .filter((entry) => allows(caps, entry.module, entry.permission))
    .map((entry) => ({
      module: entry.module,
      path: entry.path,
      label: entry.label ?? caps.moduleNames[entry.module] ?? entry.module,
      icon: entry.icon,
      primary: entry.primary,
      Screen: entry.Screen,
    }))
}

/**
 * Reparte entre la barra de abajo y «Más».
 *
 * Se rellenan los huecos con lo que haya aunque no sea `primary`: una barra con
 * dos botones y un espacio vacío se ve rota, y quien la usa no sabe si le falta
 * algo o si el sistema se cargó a medias.
 */
export function splitMenu(entries: MenuEntry[], slots = 3): { bar: MenuEntry[]; more: MenuEntry[] } {
  const preferred = entries
    .filter((e) => e.primary !== undefined)
    .sort((a, b) => (a.primary ?? 0) - (b.primary ?? 0))

  const rest = entries.filter((e) => e.primary === undefined)
  const bar = [...preferred, ...rest].slice(0, slots)
  const inBar = new Set(bar.map((e) => e.path))

  return { bar, more: entries.filter((e) => !inBar.has(e.path)) }
}
