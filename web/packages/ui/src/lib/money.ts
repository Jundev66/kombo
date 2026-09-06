/**
 * Money on the client. Whole cents, exactly as on the server.
 *
 * `0.1 + 0.2 !== 0.3` does not stop being true in TypeScript: a total computed
 * with floats in the browser and one computed with integers in PHP end up a
 * cent apart, and the one people see is the browser's.
 */

export type Cents = number

/** How many bolívares a dollar is worth. */
export type Rate = number

/** For display. Never for computing. */
export function formatUsd(cents: Cents): string {
  const sign = cents < 0 ? '-' : ''
  const abs = Math.abs(cents)

  return `${sign}$${Math.trunc(abs / 100).toLocaleString('es-VE')},${String(abs % 100).padStart(2, '0')}`
}

/**
 * To bolívares, at the rate of the day.
 *
 * Rounds ONCE, at the end. Converting each line and then summing is how the
 * one-bolívar difference nobody can account for appears.
 */
export function toBs(cents: Cents, rate: Rate): Cents {
  return Math.round(cents * rate)
}

export function formatBs(cents: Cents, rate: Rate): string {
  const bs = toBs(cents, rate)
  const sign = bs < 0 ? '-' : ''
  const abs = Math.abs(bs)

  return `${sign}Bs ${Math.trunc(abs / 100).toLocaleString('es-VE')},${String(abs % 100).padStart(2, '0')}`
}

/**
 * From what somebody types to cents.
 *
 * It accepts the decimal comma, which is how it is written here. Insisting on a
 * point asks whoever is standing at the counter to think about how the computer
 * writes rather than how they do.
 */
export function parseAmount(input: string): Cents | null {
  const clean = input.replace(/\s/g, '').replace(',', '.')

  if (clean === '' || !/^-?\d*\.?\d*$/.test(clean)) {
    return null
  }

  const value = Number.parseFloat(clean)

  return Number.isNaN(value) ? null : Math.round(value * 100)
}

/** From cents to what is shown in an editable text field. */
export function toAmountInput(cents: Cents): string {
  return (cents / 100).toFixed(2).replace('.', ',')
}
