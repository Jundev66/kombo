/**
 * Joins classes, skipping the falsy.
 *
 * Twelve lines of our own instead of `clsx` + `tailwind-merge` (~9 KB
 * together). They are not needed: the components accept no arbitrary appearance
 * overrides, so there are no utility conflicts to resolve. And 9 KB on the
 * counter PC is 9 KB.
 */
export function cn(...parts: Array<string | false | null | undefined>): string {
  return parts.filter(Boolean).join(' ')
}
