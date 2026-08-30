/**
 * El color de marca del negocio, usado sin que se pueda romper la pantalla.
 *
 * `brand_color` es un hexa que **escribe el negocio** desde el panel, así que
 * puede venir mal escrito, puede ser casi blanco, y puede ser verde. Las tres
 * cosas importan:
 *
 * - Mal escrito → no se aplica, y se cae al neutro. Nunca a un `background`
 *   inválido que el navegador ignora dejando texto blanco sobre blanco.
 * - Casi blanco o casi negro → el texto encima deja de leerse. Se elige entre
 *   tinta y blanco el que contraste, y si NINGUNO llega a 4.5:1 se descarta el
 *   color entero. Un negocio con la marca ilegible prefiere no verla a que su
 *   cliente no lea el número de su pedido.
 * - Verde → por eso esto sólo se usa en superficies decorativas, nunca en un
 *   estado. El sistema visual reserva verde, ámbar y rojo para decir cómo va
 *   algo; una marca verde en un botón de estado diría «listo» sin serlo.
 */

export interface BrandSurface {
  /** Para `style.background`. */
  background: string
  /** Para `style.color`: el que se lee encima. */
  foreground: string
}

/** La tinta más oscura del tema, para no importar el CSS desde aquí. */
const INK = '#16130f'

export function brandSurface(hex: string | null | undefined): BrandSurface | null {
  const rgb = parseHex(hex)

  if (rgb === null) return null

  const fondo = luminance(rgb)
  const conTinta = ratio(fondo, luminance(parseHex(INK)!))
  const conBlanco = ratio(fondo, 1)

  const mejor = Math.max(conTinta, conBlanco)

  // 4.5:1 es el mínimo de WCAG AA para texto normal. Por debajo no es que se
  // vea peor: es que no se lee al sol, que es donde se usa esto.
  if (mejor < 4.5) return null

  return {
    background: `#${rgb.map((c) => c.toString(16).padStart(2, '0')).join('')}`,
    foreground: conBlanco >= conTinta ? '#ffffff' : INK,
  }
}

/** Acepta `#abc` y `#aabbcc`, con o sin almohadilla. */
function parseHex(hex: string | null | undefined): [number, number, number] | null {
  if (typeof hex !== 'string') return null

  const limpio = hex.trim().replace(/^#/, '')

  const completo =
    limpio.length === 3
      ? limpio
          .split('')
          .map((c) => c + c)
          .join('')
      : limpio

  if (!/^[0-9a-fA-F]{6}$/.test(completo)) return null

  return [
    parseInt(completo.slice(0, 2), 16),
    parseInt(completo.slice(2, 4), 16),
    parseInt(completo.slice(4, 6), 16),
  ]
}

/** Luminancia relativa, tal como la define WCAG. */
function luminance([r, g, b]: [number, number, number]): number {
  const [rl, gl, bl] = [r, g, b].map((canal) => {
    const s = canal / 255

    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }) as [number, number, number]

  return 0.2126 * rl + 0.7152 * gl + 0.0722 * bl
}

function ratio(a: number, b: number): number {
  const [claro, oscuro] = a > b ? [a, b] : [b, a]

  return (claro + 0.05) / (oscuro + 0.05)
}
