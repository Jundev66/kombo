import { allows, type Capabilities, type ModuleCode, type PermissionCode } from '@kombo/api-client'
import type { Icon } from '@kombo/ui'
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
  /**
   * La pantalla, si vive en esta aplicación.
   *
   * Ausente en las entradas que llevan a OTRA de las cinco —la caja, la
   * cocina—, que van con `href`.
   */
  Screen?: ComponentType
  /**
   * Enlace a otra aplicación del mismo negocio.
   *
   * Recarga entera y no navegación de router, porque son builds distintos
   * servidos bajo el mismo origen: el cocinero no descarga el panel de
   * reportes para ver tres comandas.
   */
  href?: string
  Icon: Icon
  /** Sobrescribe la etiqueta del manifiesto, si hace falta. */
  label?: string
  /** Sitio en la barra principal (1, 2, 3). Sin esto, va a «Más». */
  primary?: number
  /**
   * Bajo qué encabezado se agrupa dentro de «Más».
   *
   * Doce entradas planas no son un menú, son una lista: «Categorías» y
   * «Agregados» acababan al mismo nivel que «Equipo», y encontrar el horario
   * exigía leérselas todas. El orden de los grupos es el orden en que aparecen
   * aquí.
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
  /** `null` para lo que nadie agrupó: va suelto arriba, sin encabezado. */
  title: string | null
  entries: MenuEntry[]
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
      href: entry.href,
      label: entry.label ?? caps.moduleNames[entry.module] ?? entry.module,
      Icon: entry.Icon,
      primary: entry.primary,
      group: entry.group,
      Screen: entry.Screen,
    }))
}

/**
 * Reparte entre la barra y el panel de «Más», ya agrupado.
 *
 * La barra se rellena con lo que haya aunque no sea `primary`: una barra con
 * dos botones y un espacio vacío se ve rota, y quien la usa no sabe si le falta
 * algo o si el sistema se cargó a medias.
 *
 * Lo que ya está en la barra no se repite en los grupos. Verlo dos veces hace
 * dudar de si son la misma cosa.
 */
export function splitMenu(
  entries: MenuEntry[],
  slots = 3,
): { bar: MenuEntry[]; groups: MenuGroup[] } {
  const preferred = entries
    .filter((e) => e.primary !== undefined)
    .sort((a, b) => (a.primary ?? 0) - (b.primary ?? 0))

  /*
   * Para rellenar huecos sirve cualquier entrada MENOS las que salen de la
   * aplicación.
   *
   * Una pestaña de la barra promete volver: se toca, cambia el contenido, y la
   * barra sigue ahí. La caja y la cocina son otra aplicación —el botón de atrás
   * no devuelve al panel—, así que en la barra parecerían una sección más y se
   * las tocaría sin querer. En el panel de «Más» van marcadas con su flecha y
   * el gesto es deliberado.
   */
  const rest = entries.filter((e) => e.primary === undefined && e.href === undefined)
  const bar = [...preferred, ...rest].slice(0, slots)
  const inBar = new Set(bar.map((e) => e.path))

  const groups: MenuGroup[] = []

  for (const entry of entries) {
    if (inBar.has(entry.path)) continue

    const title = entry.group ?? null
    const existente = groups.find((g) => g.title === title)

    if (existente) {
      existente.entries.push(entry)
    } else {
      groups.push({ title, entries: [entry] })
    }
  }

  // Lo que nadie agrupó va primero y sin encabezado: un grupo llamado «Otros»
  // no le dice nada a nadie, y ponerlo al final esconde lo que quede suelto.
  return { bar, groups: groups.sort((a, b) => Number(a.title !== null) - Number(b.title !== null)) }
}
