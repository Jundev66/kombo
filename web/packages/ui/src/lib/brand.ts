/**
 * The tenant's brand colour, used in a way that cannot break the screen.
 *
 * `brand_color` is a hex the TENANT types into the dashboard, so it can be
 * malformed, nearly white, or green:
 *
 * - Malformed → not applied, falling back to neutral. Never an invalid
 *   `background` the browser ignores, leaving white text on white.
 * - Nearly white or nearly black → the text on it stops reading. Ink or white is
 *   chosen by contrast, and if NEITHER reaches 4.5:1 the colour is dropped
 *   entirely.
 * - Green → which is why this is only used on decorative surfaces, never on a
 *   status. Green, amber and red are reserved for saying how something is going.
 */

export interface BrandSurface {
  /** For `style.background`. */
  background: string
  /** For `style.color`: the one that reads on top. */
  foreground: string
}

/** The theme's darkest ink, to avoid importing the CSS from here. */
const INK = '#16130f'

export function brandSurface(hex: string | null | undefined): BrandSurface | null {
  const rgb = parseHex(hex)

  if (rgb === null) return null

  const background = luminance(rgb)
  const withInk = ratio(background, luminance(parseHex(INK)!))
  const withBlank = ratio(background, 1)

  const better = Math.max(withInk, withBlank)

  // 4.5:1 is WCAG AA's minimum for body text. Below it, it is not that it
  // looks worse: it cannot be read in sunlight, which is where this is used.
  if (better < 4.5) return null

  return {
    background: `#${rgb.map((c) => c.toString(16).padStart(2, '0')).join('')}`,
    foreground: withBlank >= withInk ? '#ffffff' : INK,
  }
}

/** Accepts `#abc` and `#aabbcc`, with or without the hash. */
function parseHex(hex: string | null | undefined): [number, number, number] | null {
  if (typeof hex !== 'string') return null

  const trimmed = hex.trim().replace(/^#/, '')

  const complete =
    trimmed.length === 3
      ? trimmed
          .split('')
          .map((c) => c + c)
          .join('')
      : trimmed

  if (!/^[0-9a-fA-F]{6}$/.test(complete)) return null

  return [
    parseInt(complete.slice(0, 2), 16),
    parseInt(complete.slice(2, 4), 16),
    parseInt(complete.slice(4, 6), 16),
  ]
}

/** Relative luminance, as WCAG defines it. */
function luminance([r, g, b]: [number, number, number]): number {
  const [rl, gl, bl] = [r, g, b].map((channel) => {
    const s = channel / 255

    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  }) as [number, number, number]

  return 0.2126 * rl + 0.7152 * gl + 0.0722 * bl
}

function ratio(a: number, b: number): number {
  const [claro, oscuro] = a > b ? [a, b] : [b, a]

  return (claro + 0.05) / (oscuro + 0.05)
}
