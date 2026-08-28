/**
 * Une clases, saltándose lo falso.
 *
 * Doce líneas propias en vez de `clsx` + `tailwind-merge` (~9 KB juntos). No
 * hacen falta: los componentes no aceptan sobrescritura arbitraria de
 * apariencia, así que no hay conflictos de utilidades que resolver. Y 9 KB en
 * la PC del mostrador son 9 KB.
 */
export function cn(...parts: Array<string | false | null | undefined>): string {
  return parts.filter(Boolean).join(' ')
}
