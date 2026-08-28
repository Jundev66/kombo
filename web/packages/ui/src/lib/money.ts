/**
 * Dinero en el cliente. Centavos enteros, igual que en el servidor.
 *
 * `0.1 + 0.2 !== 0.3` no deja de ser cierto porque estemos en TypeScript: un
 * total calculado con flotantes en el navegador y otro calculado con enteros
 * en PHP acaban discrepando en un céntimo, y el que se ve es el del navegador.
 */

export type Cents = number

/** Cuántos bolívares vale un dólar. */
export type Rate = number

/** Para mostrar. Nunca para calcular. */
export function formatUsd(cents: Cents): string {
  const sign = cents < 0 ? '-' : ''
  const abs = Math.abs(cents)

  return `${sign}$${Math.trunc(abs / 100).toLocaleString('es-VE')},${String(abs % 100).padStart(2, '0')}`
}

/**
 * A bolívares, con la tasa del día.
 *
 * Redondea UNA vez, al final. Convertir cada línea y luego sumar es cómo
 * aparece la diferencia de un bolívar que nadie sabe de dónde salió.
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
 * De lo que alguien teclea a centavos.
 *
 * Acepta la coma decimal, que es como se escribe aquí. Obligar al punto es
 * pedirle a quien está de pie en el mostrador que piense en cómo escribe el
 * ordenador y no en cómo escribe él.
 */
export function parseAmount(input: string): Cents | null {
  const clean = input.replace(/\s/g, '').replace(',', '.')

  if (clean === '' || !/^-?\d*\.?\d*$/.test(clean)) {
    return null
  }

  const value = Number.parseFloat(clean)

  return Number.isNaN(value) ? null : Math.round(value * 100)
}

/** De centavos a lo que se muestra en un campo de texto editable. */
export function toAmountInput(cents: Cents): string {
  return (cents / 100).toFixed(2).replace('.', ',')
}
